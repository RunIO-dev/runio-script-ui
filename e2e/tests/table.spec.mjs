import { test, expect } from '@playwright/test';
import { PHONE, column, markDocument, noHorizontalOverflow, rows, sameDocument, watch } from './support.mjs';

// The tool "Ship orders" lists the paid orders, ten on a page. The tests that ship
// orders change the demo data, so the numbers are read from the page, not written here.
const foot = (page) => page.locator('.sui-table-foot').first();
const pageNow = (page) => page.locator('.sui-page-now').first();
const next = (page) => page.locator('[id="p-table:next"]');
const previous = (page) => page.locator('[id="p-table:prev"]');
const box = (page, position) => page.locator(`[id="f-table:${position}"]`);
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
});
