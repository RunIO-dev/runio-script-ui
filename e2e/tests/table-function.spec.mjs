import { test, expect } from '@playwright/test';
import { PHONE, column, markDocument, noHorizontalOverflow, rows, sameDocument, settled, watch } from './support.mjs';

// The tool "Order archive" shows 100,000 orders. Its table gets a function in place of
// the rows and asks it for one page at a time, the way a tool asks a database. Order
// 500001 is the first, and every customer is one of 132 names.
const foot = (page) => page.locator('.sui-table-foot').first();
const pageNow = (page) => page.locator('.sui-page-now').first();
const next = (page) => page.locator('[id="p-table:next"]');
const previous = (page) => page.locator('[id="p-table:prev"]');
const box = (page, position) => page.locator(`[id="f-table:${position}"]`);
const search = (page) => page.locator('[id="f-table:find"]');
const all = (page) => page.locator('[id="b-table:all"]');
const header = (page, name) => page.locator('#sui-body thead th', { hasText: name });
/** What the table says about ticks that it could not keep. */
const unselected = (page) => page.locator('.sui-table-unselected');
/** What the page script hands to a screen reader. */
const said = (page) => page.locator('#sui-status');

test.beforeEach(({ page }) => {
    watch(page);
});

test.afterEach(({ page }) => {
    expect(page.problems, 'script errors, blocked content and server errors').toEqual([]);
});

test('a table of 100,000 rows shows one page, and Next brings the following one without a page load', async ({ page }) => {
    await page.goto('/archive');
    await markDocument(page);

    await expect(rows(page)).toHaveCount(25);
    await expect(foot(page)).toHaveText('Rows 1 to 25 of 100,000 · 0 selected on this page');
    await expect(pageNow(page)).toHaveText('Page 1 of 4,000');
    await expect(previous(page)).toBeDisabled();
    expect((await column(page, 1)).slice(0, 3)).toEqual(['500001', '500002', '500003']);

    await next(page).click();

    await expect(pageNow(page)).toHaveText('Page 2 of 4,000');
    await expect(foot(page)).toHaveText('Rows 26 to 50 of 100,000 · 0 selected on this page');
    expect((await column(page, 1)).slice(0, 3)).toEqual(['500026', '500027', '500028']);
    expect(await sameDocument(page), 'updated in place').toBe(true);
    await expect(next(page), 'the focus stays on the button').toBeFocused();
    await expect(said(page), 'a screen reader hears what the table shows now').toHaveText('Rows 26 to 50 of 100,000 · 0 selected on this page');

    await previous(page).click();
    await expect(pageNow(page)).toHaveText('Page 1 of 4,000');
});

test('a reload shows the page the user was on', async ({ page }) => {
    await page.goto('/archive');
    await next(page).click();
    await next(page).click();
    await expect(pageNow(page)).toHaveText('Page 3 of 4,000');
    await settled(page);

    await page.reload();

    await expect(pageNow(page)).toHaveText('Page 3 of 4,000');
    expect((await column(page, 1))[0]).toBe('500051');
});

test('a header sorts all 100,000 rows, and only the headers that were named do', async ({ page }) => {
    await page.goto('/archive');
    await next(page).click();
    await expect(pageNow(page)).toHaveText('Page 2 of 4,000');

    // "Status" was not named sortable: its header is its name, and nothing to press.
    await expect(header(page, 'Status').locator('button')).toHaveCount(0);
    await expect(header(page, 'Status')).toHaveText('Status');

    await header(page, 'Total').locator('button').click();

    // The first page of the new order, with the smallest total of them all in front.
    await expect(header(page, 'Total')).toHaveAttribute('aria-sort', 'ascending');
    await expect(pageNow(page)).toHaveText('Page 1 of 4,000');
    const rising = (await column(page, -1)).map(Number);
    expect(rising[0]).toBe(5);
    expect(rising).toEqual([...rising].sort((a, b) => a - b));

    await header(page, 'Total').locator('button').click();

    await expect(header(page, 'Total')).toHaveAttribute('aria-sort', 'descending');
    await expect.poll(async () => Number((await column(page, -1))[0])).toBe(504.98);

    // The order goes along to the next page.
    await next(page).click();
    await expect(pageNow(page)).toHaveText('Page 2 of 4,000');
    await expect(header(page, 'Total')).toHaveAttribute('aria-sort', 'descending');
    const falling = (await column(page, -1)).map(Number);
    expect(falling).toEqual([...falling].sort((a, b) => b - a));
    expect(falling[0]).toBeLessThan(504.98);
});

test('the search box asks for the matching rows while the user types', async ({ page }) => {
    await page.goto('/archive');
    await markDocument(page);

    await search(page).fill('okafor');

    await expect(foot(page)).toHaveText('Rows 1 to 25 of 9,091 · 0 selected on this page');
    await expect(pageNow(page)).toHaveText('Page 1 of 364');
    expect((await column(page, 2)).every((customer) => /Okafor$/.test(customer))).toBe(true);
    await expect(search(page), 'the box keeps the keyboard').toBeFocused();
    expect(await sameDocument(page), 'updated in place').toBe(true);

    // The number of one order: one row, and no pages to turn.
    await search(page).fill('599999');
    await expect(foot(page)).toHaveText('1 row · 0 selected');
    await expect(page.locator('.sui-pager')).toHaveCount(0);
    expect(await column(page, 1)).toEqual(['599999']);

    await search(page).fill('zzz-no-such-order');
    await expect(page.locator('#sui-body .sui-empty')).toHaveText('No rows match the search.');
    await expect(search(page)).toHaveValue('zzz-no-such-order');

    await search(page).fill('');
    await expect(foot(page)).toHaveText('Rows 1 to 25 of 100,000 · 0 selected on this page');
});

test('the ticked rows of the page reach the tool, and a turned page starts without ticks', async ({ page }) => {
    await page.goto('/archive');

    await box(page, 0).check();
    await box(page, 2).check();
    await expect(foot(page)).toHaveText('Rows 1 to 25 of 100,000 · 2 selected on this page');
    await page.getByRole('button', { name: 'Print labels' }).click();

    await expect(page.locator('#sui-body .sui-success')).toHaveText('Labels are on their way for 500001, 500003.');
    await expect(box(page, 0)).toBeChecked();

    // The table holds one page. The ticks are about the rows of that page and stay with it.
    await expect(unselected(page)).toHaveCount(0);
    await next(page).click();
    await expect(foot(page)).toHaveText('Rows 26 to 50 of 100,000 · 0 selected on this page');
    // It says what became of the two ticks, and a screen reader hears that as well.
    await expect(unselected(page)).toHaveText('2 selected rows are not on this page and are no longer selected.');
    await expect(said(page)).toHaveText('Rows 26 to 50 of 100,000 · 0 selected on this page 2 selected rows are not on this page and are no longer selected.');
    await page.getByRole('button', { name: 'Print labels' }).click();
    await expect(page.locator('#sui-body .sui-warning')).toHaveText('Tick the orders that need a label first.');
    // Said once: the next run has nothing more to tell.
    await expect(unselected(page)).toHaveCount(0);

    // The box in the header ticks the 25 rows of the page, and no row of another page.
    await all(page).click();
    await expect(foot(page)).toHaveText('Rows 26 to 50 of 100,000 · 25 selected on this page');
    await expect(all(page)).toHaveAttribute('aria-checked', 'true');
    await page.locator('[id="b-table:none"]').click();
    await expect(foot(page)).toHaveText('Rows 26 to 50 of 100,000 · 0 selected on this page');
});

test('a list that nobody counts offers Next for as long as rows follow', async ({ page }) => {
    await page.goto('/test-uncounted');

    await expect(rows(page)).toHaveCount(25);
    await expect(foot(page)).toHaveText('Rows 1 to 25');
    await expect(pageNow(page)).toHaveText('Page 1');
    await expect(previous(page)).toBeDisabled();

    await next(page).click();
    await expect(pageNow(page)).toHaveText('Page 2');
    await expect(foot(page)).toHaveText('Rows 26 to 50');
    await expect(next(page), 'the focus stays on the button').toBeFocused();

    await next(page).click();
    await expect(pageNow(page)).toHaveText('Page 3');
    await expect(foot(page)).toHaveText('Rows 51 to 60');
    await expect(rows(page)).toHaveCount(10);
    await expect(next(page)).toBeDisabled();
    // A button that cannot be pressed cannot hold the focus. It moves to the one that can.
    await expect(previous(page)).toBeFocused();

    // Another order is another list: it starts at its first page.
    await header(page, 'ID').locator('button').click();
    await expect(header(page, 'ID')).toHaveAttribute('aria-sort', 'ascending');
    await expect(pageNow(page)).toHaveText('Page 1');
    await header(page, 'ID').locator('button').click();
    await expect(header(page, 'ID')).toHaveAttribute('aria-sort', 'descending');
    expect((await column(page, 0))[0]).toBe('60');
});

test.describe('in a phone-sized window', () => {
    test.use({ viewport: PHONE });

    test('the archive scrolls inside its frame, and the pager stays on the screen', async ({ page }) => {
        await page.goto('/archive');

        expect(await noHorizontalOverflow(page), 'the page itself does not scroll sideways').toBe(true);
        // 25 rows are longer than the screen. Scrolled down to, the pager is on it and not beside it.
        await next(page).scrollIntoViewIfNeeded();
        await expect(next(page)).toBeInViewport({ ratio: 1 });

        // A ticked row and a turned page: the line about the tick that was dropped fits the screen too.
        await box(page, 0).check();
        await next(page).click();
        await expect(pageNow(page)).toHaveText('Page 2 of 4,000');
        await expect(unselected(page)).toHaveText('1 selected row is not on this page and is no longer selected.');
        await unselected(page).scrollIntoViewIfNeeded();
        await expect(unselected(page)).toBeInViewport({ ratio: 1 });
        expect(await noHorizontalOverflow(page), 'the page itself does not scroll sideways').toBe(true);
    });
});

test.describe('without JavaScript', () => {
    test.use({ javaScriptEnabled: false });

    test('the pages, the order and the search work as plain form posts', async ({ page }) => {
        await page.goto('/archive');

        await next(page).click();
        await expect(pageNow(page)).toHaveText('Page 2 of 4,000');
        expect((await column(page, 1))[0]).toBe('500026');

        await header(page, 'Order').locator('button').click();
        await header(page, 'Order').locator('button').click();
        await expect(header(page, 'Order')).toHaveAttribute('aria-sort', 'descending');
        expect((await column(page, 1))[0]).toBe('600000');

        await search(page).fill('ava okafor');
        await search(page).press('Enter');
        await expect(foot(page)).toHaveText('Rows 1 to 25 of 757 · 0 selected on this page');
        await expect(search(page)).toHaveValue('ava okafor');
    });
});
