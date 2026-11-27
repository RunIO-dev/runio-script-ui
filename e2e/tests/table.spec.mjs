import { test, expect } from '@playwright/test';
import { PHONE, column, markDocument, noHorizontalOverflow, rows, sameDocument, settled, watch } from './support.mjs';

// The tool "Ship orders" lists the paid orders, ten on a page. The tests that ship
// orders change the demo data, so the numbers are read from the page, not written here.
const foot = (page) => page.locator('.sui-table-foot').first();
const pageNow = (page) => page.locator('.sui-page-now').first();
const next = (page) => page.locator('[id="p-table:next"]');
const previous = (page) => page.locator('[id="p-table:prev"]');
const box = (page, position) => page.locator(`[id="f-table:${position}"]`);
const search = (page) => page.locator('[id="f-table:find"]');
const all = (page) => page.locator('[id="b-table:all"]');
const clear = (page) => page.locator('[id="b-table:none"]');
const ticked = (page) => page.locator('#sui-body tbody input:checked');
/** What the page script hands to a screen reader. */
const said = (page) => page.locator('#sui-status');

/** The number behind "of" in a line like "Rows 1 to 10 of 41" or "Page 1 of 5". */
const outOf = async (line) => Number((await line.textContent()).match(/of ([\d,]+)/)[1].replace(',', ''));

test.beforeEach(({ page }) => {
    watch(page);
});

test.afterEach(({ page }) => {
    expect(page.problems, 'script errors, blocked content and server errors').toEqual([]);
});

test('a long table shows one page, and Next brings the following rows without a page load', async ({ page }) => {
    await page.goto('/ship');
    await markDocument(page);

    await expect(rows(page)).toHaveCount(10);
    await expect(pageNow(page)).toHaveText(/^Page 1 of \d+$/);
    await expect(previous(page)).toBeDisabled();
    const first = await column(page, 1);

    await next(page).click();

    await expect(pageNow(page)).toHaveText(/^Page 2 of \d+$/);
    await expect(foot(page)).toHaveText(/^Rows 11 to 20 of \d+ · 0 selected$/);
    const second = await column(page, 1);
    expect(second.filter((order) => first.includes(order)), 'other orders than on page 1').toEqual([]);
    expect(await sameDocument(page), 'updated in place').toBe(true);
    await expect(next(page), 'the focus stays on the button').toBeFocused();
    await expect(said(page), 'a screen reader hears what the table shows now').toHaveText(/^Rows 11 to 20 of \d+ · 0 selected$/);
});

test('the keyboard reaches the last page, and the focus moves on when Next has nothing left', async ({ page }) => {
    await page.goto('/ship');
    const pages = await outOf(pageNow(page));
    expect(pages, 'enough pages for this test').toBeGreaterThan(2);

    await next(page).focus();
    for (let turn = 2; turn <= pages; turn++) {
        await page.keyboard.press('Enter');
        await expect(pageNow(page)).toHaveText(`Page ${turn} of ${pages}`);
    }

    await expect(next(page)).toBeDisabled();
    await expect(previous(page), 'the focus went to the button that still works').toBeFocused();

    await page.keyboard.press('Enter');
    await expect(pageNow(page)).toHaveText(`Page ${pages - 1} of ${pages}`);
    await expect(previous(page)).toBeFocused();
});

test('a tick stays when the page changes, and the button gets the rows of all pages', async ({ page }) => {
    await page.goto('/ship');
    const waiting = await outOf(foot(page));

    await box(page, 0).click();
    await expect(foot(page)).toContainText('1 selected');
    const [onFirstPage] = await column(page, 1);

    await next(page).click();
    await expect(pageNow(page)).toHaveText(/^Page 2 of/);
    await expect(foot(page)).toContainText('1 selected (1 on other pages)');
    await expect(page.locator('#sui-body tbody input:checked'), 'no box of page 2 is ticked').toHaveCount(0);

    await box(page, 1).click();
    await expect(foot(page)).toContainText('2 selected (1 on other pages)');
    const onSecondPage = (await column(page, 1))[1];

    await previous(page).click();
    await expect(box(page, 0), 'the tick of page 1 is still there').toBeChecked();
    await expect(foot(page)).toContainText('2 selected (1 on other pages)');

    await page.locator('#b-mark-as-shipped').click();
    await expect(page.locator('[data-confirm] p')).toHaveText('Mark 2 orders as shipped?');
    await page.locator('[id="b-mark-as-shipped:yes"]').click();

    await expect(page.locator('#sui-body .sui-success')).toHaveText('Marked 2 orders as shipped.');
    await expect(foot(page)).toContainText('0 selected');
    expect(await outOf(foot(page)), 'two orders fewer are waiting').toBe(waiting - 2);

    // Both orders are gone from the list of paid orders, also the one from page 2.
    await page.goto('/audit');
    const entry = rows(page).filter({ hasText: 'Mark as shipped' }).first();
    await expect(entry).toContainText(onFirstPage);
    await expect(entry).toContainText(onSecondPage);
});

test('a filter of the tool brings the table back to its first page', async ({ page }) => {
    await page.goto('/orders-ar');
    await next(page).click();
    await expect(pageNow(page)).toHaveText('الصفحة 2 من 4');

    await page.locator('select.sui-input').selectOption('shipped');

    await expect(pageNow(page)).toHaveText(/^الصفحة 1 من \d+$/);
    await expect(foot(page)).toHaveText(/^الصفوف 1 إلى 20 من \d+$/);
});

test('a reload shows the page the user was on', async ({ page }) => {
    await page.goto('/ship');
    await next(page).click();
    await expect(pageNow(page)).toHaveText(/^Page 2 of/);

    await page.reload();

    await expect(pageNow(page)).toHaveText(/^Page 2 of/);
});

// ------------------------------------------------------------------ search

test('the search box filters while the user types, and the text is never lost', async ({ page }) => {
    await page.goto('/ship');
    await markDocument(page);
    const all = await outOf(foot(page));

    await search(page).click();
    await page.keyboard.type('noah', { delay: 60 });

    await expect(foot(page)).toHaveText(new RegExp(`\\(filtered from ${all}\\)`));
    await settled(page);
    const customers = await column(page, 3);
    expect(customers.length).toBeGreaterThan(0);
    expect(customers.every((name) => /noah/i.test(name)), 'only orders of Noah').toBe(true);

    await expect(search(page), 'the user can go on typing').toBeFocused();
    await expect(search(page)).toHaveValue('noah');
    expect(await search(page).evaluate((input) => input.selectionStart), 'the caret is at the end').toBe(4);
    expect(await sameDocument(page), 'updated in place').toBe(true);
    await expect(said(page), 'a screen reader hears how many rows are left').toHaveText(new RegExp(`\\(filtered from ${all}\\)`));

    // A second word narrows it down, whatever column it is in.
    await page.keyboard.type(' keyboard', { delay: 60 });
    await expect.poll(async () => (await column(page, 4)).every((items) => /keyboard/i.test(items))).toBe(true);
    await expect(search(page)).toHaveValue('noah keyboard');
});

test('fast typing does not send a request for every key', async ({ page }) => {
    await page.goto('/ship');

    // The answer to the first request is held back until the test lets it go,
    // so the keys that follow are typed while it is on its way.
    let posts = 0;
    let letGo;
    const held = new Promise((resolve) => { letGo = resolve; });
    await page.route('**/ship', async (route) => {
        if ('POST' === route.request().method() && 1 === ++posts) await held;
        await route.continue();
    });

    await search(page).click();
    const first = page.waitForRequest((request) => 'POST' === request.method());
    await page.keyboard.type('k');
    await first;

    await page.keyboard.type('eyboard');
    // Longer than the pause after the last key that starts a search.
    await page.waitForTimeout(600);
    expect(posts, 'nothing is sent while a request is on its way').toBe(1);

    letGo();

    await expect.poll(async () => (await column(page, 4)).every((items) => /keyboard/i.test(items)), { timeout: 10_000 }).toBe(true);
    await settled(page);
    await expect(search(page)).toHaveValue('keyboard');
    expect(posts, 'one request for "k", one for all the rest').toBe(2);
});

test('a row that the search hides does not count, and its tick comes back with it', async ({ page }) => {
    await page.goto('/ship');
    const [, order] = await rows(page).first().locator('td').allTextContents();

    await box(page, 0).click();
    await expect(foot(page)).toContainText('1 selected');
    await expect(page.locator('#b-mark-as-shipped')).toBeVisible();

    // A typing mistake: nothing matches.
    await search(page).fill('zzz-no-such-order');
    await expect(page.locator('#sui-body .sui-empty')).toHaveText('No rows match the search.');
    await expect(page.locator('#b-mark-as-shipped'), 'no row in the list is ticked, so there is nothing to ship').toHaveCount(0);

    await search(page).fill('');
    await expect(foot(page)).toContainText('1 selected');
    await expect(rows(page).first()).toContainText(order);
    await expect(box(page, 0), 'the tick is back').toBeChecked();
    await expect(page.locator('#b-mark-as-shipped')).toBeVisible();
});

test('Enter in the search box filters at once and presses no button', async ({ page }) => {
    await page.goto('/ship');
    await box(page, 0).click();
    await expect(page.locator('#b-mark-as-shipped')).toBeVisible();

    await search(page).fill('lina');
    await page.keyboard.press('Enter');

    await expect(foot(page)).toContainText('filtered from');
    await expect(page.locator('[data-confirm]'), 'the button was not pressed').toHaveCount(0);
    await expect(page.locator('#sui-body .sui-success')).toHaveCount(0);
});

test('while a character is being composed the page is left alone', async ({ page }) => {
    await page.goto('/ship');
    const before = await column(page, 1);

    // What a Chinese, Japanese or Korean keyboard does: the text in the field
    // changes several times before the character is final.
    await search(page).click();
    await search(page).dispatchEvent('compositionstart');
    await page.keyboard.type('mia');
    await page.waitForTimeout(700);
    expect(await column(page, 1), 'nothing was swapped under the keyboard').toEqual(before);

    await search(page).dispatchEvent('compositionend');

    await expect(foot(page)).toContainText('filtered from');
    await expect(search(page)).toHaveValue('mia');
    await expect(search(page)).toBeFocused();
});

test('a keyboard that never says the character is finished does not hold the page for ever', async ({ page }) => {
    await page.goto('/ship');

    await search(page).click();
    await search(page).dispatchEvent('compositionstart');
    await page.keyboard.type('mia');

    // The field loses the focus half way, and no "compositionend" follows.
    await all(page).focus();

    await expect(foot(page)).toContainText('filtered from');
    await expect(search(page)).toHaveValue('mia');
});

// -------------------------------------------------------------- select all

test('the box in the header ticks the page, and "Clear selection" unticks every page', async ({ page }) => {
    await page.goto('/ship');
    await expect(all(page)).toHaveAttribute('aria-checked', 'false');
    await expect(clear(page)).toHaveCount(0);

    await all(page).click();

    await expect(ticked(page)).toHaveCount(10);
    await expect(foot(page)).toContainText('10 selected');
    await expect(all(page)).toHaveAttribute('aria-checked', 'true');
    await expect(all(page), 'the focus stays on the box').toBeFocused();

    // One row less: the box shows that only some rows of the page are ticked.
    await box(page, 2).click();
    await expect(all(page)).toHaveAttribute('aria-checked', 'mixed');
    await expect(foot(page)).toContainText('9 selected');

    // The next page has no tick yet. Its box ticks its rows and leaves page 1 alone.
    await next(page).click();
    await expect(all(page)).toHaveAttribute('aria-checked', 'false');
    await all(page).click();
    await expect(foot(page)).toContainText('19 selected (9 on other pages)');

    await clear(page).click();

    await expect(foot(page)).toContainText('0 selected');
    await expect(ticked(page)).toHaveCount(0);
    await expect(clear(page), 'nothing left to clear').toHaveCount(0);
    await expect(all(page), 'the focus moved to the box in the header').toBeFocused();
    await previous(page).click();
    await expect(ticked(page), 'page 1 is unticked too').toHaveCount(0);
});

test('the box in the header works with the space bar and unticks a page that is fully ticked', async ({ page }) => {
    await page.goto('/ship');

    await all(page).focus();
    await page.keyboard.press('Space');
    await expect(ticked(page)).toHaveCount(10);

    await page.keyboard.press('Space');
    await expect(ticked(page)).toHaveCount(0);
    await expect(all(page)).toHaveAttribute('aria-checked', 'false');
    await expect(all(page)).toBeFocused();
});

test('the box in the header looks like the tick boxes under it', async ({ page }) => {
    await page.goto('/ship');

    const size = async (locator) => {
        const frame = await locator.boundingBox();

        return [Math.round(frame.width), Math.round(frame.height)];
    };
    expect(await size(all(page))).toEqual(await size(box(page, 0)));

    // Ticked, it takes the colour that the browser gives a ticked box.
    await all(page).click();
    await expect(all(page)).toHaveAttribute('aria-checked', 'true');
    const accent = await page.evaluate(() => getComputedStyle(document.documentElement).getPropertyValue('--accent').trim());
    const colour = await all(page).evaluate((button) => {
        const probe = document.createElement('i');
        probe.style.color = getComputedStyle(button).backgroundColor;
        document.body.append(probe);
        const value = getComputedStyle(probe).color;
        probe.remove();

        return value;
    });
    const wanted = await page.evaluate((value) => {
        const probe = document.createElement('i');
        probe.style.color = value;
        document.body.append(probe);
        const computed = getComputedStyle(probe).color;
        probe.remove();

        return computed;
    }, accent);
    expect(colour).toBe(wanted);
});

test.describe('in a phone-sized window', () => {
    test.use({ viewport: PHONE });

    test('a wide table scrolls inside its frame, and the pager stays on the screen', async ({ page }) => {
        await page.goto('/ship');

        expect(await noHorizontalOverflow(page), 'the page itself does not scroll sideways').toBe(true);
        const frame = page.locator('.sui-table-wrap').first();
        expect(await frame.evaluate((element) => element.scrollWidth > element.clientWidth), 'the table is wider than its frame').toBe(true);
        await expect(next(page)).toBeInViewport();

        await next(page).click();
        await expect(pageNow(page)).toHaveText(/^Page 2 of/);
    });

    test('a wide table stays where the user moved it when the page is drawn again', async ({ page }) => {
        await page.goto('/customers');
        const frame = page.locator('.sui-table-wrap').first();
        const room = await frame.evaluate((el) => el.scrollWidth - el.clientWidth);
        expect(room, 'the table is wider than its frame').toBeGreaterThan(60);

        // The user moves the table sideways to see the last columns, then sets a filter.
        await frame.evaluate((el) => { el.scrollLeft = 60; });
        const all = await rows(page).count();
        await page.getByLabel('Only customers with a refund').check();
        await expect.poll(() => rows(page).count(), 'the filter came back').toBeLessThan(all);

        expect(await frame.evaluate((el) => el.scrollLeft), 'the new page shows the same columns').toBe(60);
    });
});

test.describe('without JavaScript', () => {
    test.use({ javaScriptEnabled: false });

    test('the pager and the ticks work as plain form posts', async ({ page }) => {
        await page.goto('/ship');

        await box(page, 0).check();
        await next(page).click();

        await expect(pageNow(page)).toHaveText(/^Page 2 of/);
        await expect(foot(page)).toContainText('1 selected (1 on other pages)');

        await previous(page).click();

        await expect(pageNow(page)).toHaveText(/^Page 1 of/);
        await expect(box(page, 0)).toBeChecked();
    });

    test('the box in the header and "Clear selection" work as plain form posts', async ({ page }) => {
        await page.goto('/ship');

        await all(page).click();
        await expect(ticked(page)).toHaveCount(10);
        await expect(all(page)).toHaveAttribute('aria-checked', 'true');

        await clear(page).click();
        await expect(ticked(page)).toHaveCount(0);
    });

    test('the search works with the Enter key', async ({ page }) => {
        await page.goto('/ship');

        await search(page).fill('noah');
        await search(page).press('Enter');

        await expect(foot(page)).toContainText('filtered from');
        await expect(search(page)).toHaveValue('noah');
        expect((await column(page, 3)).every((name) => /noah/i.test(name))).toBe(true);
    });
});
