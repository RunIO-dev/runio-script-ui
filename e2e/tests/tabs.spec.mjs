import { test, expect } from '@playwright/test';
import { PHONE, markDocument, noHorizontalOverflow, sameDocument, watch } from './support.mjs';
import { posts, reported } from './reports.mjs';

// The page of these tests is e2e/tools/test-tabs.php: three tabs (a list in the first, a number
// and a button in the second), a line below that says what the tool got, and eight more tabs
// with the names of months.
const note = (page) => page.locator('#sui-body > .sui-note');
const header = (page, name) => page.getByRole('tab', { name, exact: true });
const status = (page) => page.getByLabel('Status');
const limit = (page) => page.getByLabel('Limit');
const about = (page) => page.getByText('It only exists for the tests.');

/** Is the whole header inside the window, from left to right? */
async function inSight(page, name) {
    const box = await header(page, name).boundingBox();
    const width = page.viewportSize().width;

    return box.x >= 0 && box.x + box.width <= width;
}

const months = (page) => page.locator('#t-months > [role="tablist"]');

/**
 * At which ends the line of the months fades out, because more headers stand out of sight there:
 * what the page script says about the two ends, and what the style sheet makes of it
 * (where the line starts and where it ends: "solid" or "clear").
 */
const fading = (page) => months(page).evaluate((list) => {
    const image = getComputedStyle(list).maskImage;
    const stops = image.match(/rgba?\([^)]*\)|transparent/g) || [];
    const look = (stop) => ('transparent' === stop || /^rgba\(.*,\s*0\)$/.test(stop) ? 'clear' : 'solid');

    return {
        before: list.classList.contains('sui-more-before'),
        after: list.classList.contains('sui-more-after'),
        mask: 'none' === image ? 'none' : `${look(stops[0])}-${look(stops.at(-1))}`,
    };
});

/** The side on which the line of the months ends, as the fading sees it. */
const fadesToward = (page) => months(page).evaluate((list) => (/to (left|right)/.exec(getComputedStyle(list).maskImage) || [])[1]);

test.beforeEach(({ page }) => {
    watch(page);
});

test.afterEach(({ page }) => {
    expect(page.problems, 'script errors, blocked content and server errors').toEqual([]);
});

test('a tab is changed without a run of the tool, and a reload finds the one that was left open', async ({ page }) => {
    const sent = posts(page);
    await page.goto('/test-tabs');

    await expect(header(page, 'Orders')).toHaveAttribute('aria-selected', 'true');
    await expect(status(page)).toBeVisible();
    await expect(limit(page), 'the other tabs are out of sight').toBeHidden();
    await expect(about(page)).toBeHidden();

    await header(page, 'Settings').click();
    await expect(limit(page)).toBeVisible();
    await expect(status(page)).toBeHidden();
    await expect(header(page, 'Settings')).toHaveAttribute('aria-selected', 'true');
    await expect(header(page, 'Orders')).toHaveAttribute('aria-selected', 'false');
    await expect(page.getByRole('tabpanel', { name: 'Settings' }), 'the part is named by its header').toBeVisible();

    await reported(sent, 1);
    expect(sent.runs.length, 'no tool ran for that').toBe(0);
    expect(await fading(page), 'every header fits, so nothing fades out').toEqual({ before: false, after: false, mask: 'none' });

    // The tab that is open already says nothing new.
    await header(page, 'Settings').click();
    await page.waitForTimeout(600);
    expect(sent.reports.length).toBe(1);

    await page.reload();
    await expect(limit(page)).toBeVisible();
    await expect(header(page, 'Settings')).toHaveAttribute('aria-selected', 'true');
    // The other group of tabs was left alone.
    await expect(header(page, 'January')).toHaveAttribute('aria-selected', 'true');

    // The window gets narrow, and not every header fits any more.
    await page.setViewportSize(PHONE);
    await expect.poll(() => fading(page)).toEqual({ before: false, after: true, mask: 'solid-clear' });
});

test('what is behind a closed tab still counts, and the open tab stays open', async ({ page }) => {
    const sent = posts(page);
    await page.goto('/test-tabs');
    await markDocument(page);

    await header(page, 'Settings').click();
    await limit(page).fill('25');
    await limit(page).press('Tab');
    await expect(note(page)).toHaveText('Owner: nobody. Status: all. Limit: 25.');
    await expect(limit(page), 'the tab is still open after the page was swapped').toBeVisible();

    await header(page, 'Orders').click();
    await status(page).selectOption('paid');
    await expect(note(page)).toHaveText('Owner: nobody. Status: paid. Limit: 25.');
    await expect(status(page)).toBeVisible();
    await expect(limit(page)).toBeHidden();

    await page.getByLabel('Owner').fill('Ava');
    await page.getByLabel('Owner').press('Tab');
    await expect(note(page)).toHaveText('Owner: Ava. Status: paid. Limit: 25.');
    expect(await sameDocument(page)).toBe(true);
    expect(sent.runs.length).toBe(3);
});

test('a tab that is chosen while an answer is on its way stays chosen', async ({ page }) => {
    await page.goto('/test-tabs');
    // Every run of the tool takes a moment.
    await page.route('**/test-tabs', async (route) => {
        const request = route.request();
        if ('POST' === request.method() && 'layout' !== request.headers()['x-script-ui']) {
            await new Promise((resolve) => { setTimeout(resolve, 400); });
        }
        await route.continue();
    });

    await page.getByLabel('Owner').fill('Ava');
    await page.getByLabel('Owner').press('Tab');
    // The answer that is on its way was asked for while "Orders" was open.
    await header(page, 'Settings').click();
    await expect(limit(page)).toBeVisible();

    await expect(note(page)).toHaveText('Owner: Ava. Status: all. Limit: 10.');
    await expect(limit(page), 'the answer did not bring the old tab back').toBeVisible();
    await expect(header(page, 'Settings')).toHaveAttribute('aria-selected', 'true');
    await expect(status(page)).toBeHidden();

    // And the server hears of it with the next request.
    await limit(page).fill('40');
    await limit(page).press('Tab');
    await expect(note(page)).toHaveText('Owner: Ava. Status: all. Limit: 40.');
    await expect(limit(page)).toBeVisible();
});

test('the arrow keys go from header to header, and the tab follows them', async ({ page }) => {
    const sent = posts(page);
    await page.goto('/test-tabs');

    await header(page, 'Orders').focus();
    await page.keyboard.press('ArrowRight');
    await expect(header(page, 'Settings')).toBeFocused();
    await expect(limit(page)).toBeVisible();

    await page.keyboard.press('ArrowRight');
    await expect(header(page, 'About')).toBeFocused();
    await expect(about(page)).toBeVisible();

    // After the last header comes the first, and before the first the last.
    await page.keyboard.press('ArrowRight');
    await expect(header(page, 'Orders')).toBeFocused();
    await expect(status(page)).toBeVisible();
    await page.keyboard.press('ArrowLeft');
    await expect(header(page, 'About')).toBeFocused();
    await expect(about(page)).toBeVisible();

    await page.keyboard.press('Home');
    await expect(header(page, 'Orders')).toBeFocused();
    await expect(status(page)).toBeVisible();
    // With another key held down the arrows belong to the browser.
    await page.keyboard.press('Control+ArrowRight');
    await expect(header(page, 'Orders')).toBeFocused();
    await expect(status(page)).toBeVisible();
    await page.keyboard.press('End');
    await expect(header(page, 'About')).toBeFocused();
    await expect(about(page)).toBeVisible();

    // Every header can be reached with the Tab key, and Enter opens it.
    await page.keyboard.press('Shift+Tab');
    await expect(header(page, 'Settings')).toBeFocused();
    await expect(about(page), 'the focus alone opens nothing').toBeVisible();
    await page.keyboard.press('Enter');
    await expect(limit(page)).toBeVisible();
    await expect(header(page, 'Settings')).toBeFocused();

    expect(sent.runs.length, 'no tool ran for any of that').toBe(0);

    // From the header on into its part.
    await page.keyboard.press('Tab');
    await expect(header(page, 'About')).toBeFocused();
    await page.keyboard.press('Tab');
    await expect(limit(page)).toBeFocused();
});

test('the answer to a button in a tab is shown in it', async ({ page }) => {
    await page.goto('/test-tabs');

    await header(page, 'Settings').click();
    await page.getByRole('button', { name: 'Check' }).click();

    const answer = page.locator('.sui-tab-panel .sui-callout');
    await expect(answer).toHaveText('Checked with a limit of 10.');
    await expect(answer).toBeVisible();
    await expect(page.getByRole('button', { name: 'Check' })).toBeFocused();
    await expect(header(page, 'Settings')).toHaveAttribute('aria-selected', 'true');
});

test('on a page from right to left the left arrow goes on to the next header', async ({ page }) => {
    await page.goto('/test-tabs-ar');

    const first = await header(page, 'الطلبات').boundingBox();
    const second = await header(page, 'العملاء').boundingBox();
    expect(first.x, 'the first header stands on the right').toBeGreaterThan(second.x);

    await header(page, 'الطلبات').focus();
    await page.keyboard.press('ArrowLeft');
    await expect(header(page, 'العملاء')).toBeFocused();
    await expect(page.getByText('هنا العملاء.')).toBeVisible();
    await expect(page.getByText('هنا الطلبات.')).toBeHidden();

    await page.keyboard.press('ArrowRight');
    await expect(header(page, 'الطلبات')).toBeFocused();
    await expect(page.getByText('هنا الطلبات.')).toBeVisible();

    // Before the first header comes the last.
    await page.keyboard.press('ArrowRight');
    await expect(header(page, 'الإعدادات')).toBeFocused();
    expect(await noHorizontalOverflow(page)).toBe(true);
});

test('on a phone the line of headers moves sideways, and the open header stays in sight', async ({ page }) => {
    await page.setViewportSize(PHONE);
    const sent = posts(page);
    await page.goto('/test-tabs');

    expect(await noHorizontalOverflow(page), 'the page itself is no wider than the phone').toBe(true);
    expect(await inSight(page, 'August'), 'eight months do not fit').toBe(false);
    // The page script looks at the line a moment after the page is drawn.
    await expect.poll(() => fading(page), 'the line goes on to the right, and says so').toEqual({ before: false, after: true, mask: 'solid-clear' });
    expect(await fadesToward(page)).toBe('right');

    const size = await header(page, 'Orders').boundingBox();
    expect(size.height, 'a header is tall enough for a finger').toBeGreaterThanOrEqual(38);

    // A header in the middle keeps clear of both ends, and a little of the next one shows.
    await header(page, 'June').click();
    await expect(page.getByText('The numbers of June.')).toBeVisible();
    expect(await fading(page)).toEqual({ before: true, after: true, mask: 'clear-clear' });
    const june = await header(page, 'June').boundingBox();
    const july = await header(page, 'July').boundingBox();
    expect(june.x + june.width, 'the open header is not under the fading').toBeLessThanOrEqual(PHONE.width - 28);
    expect(july.x, 'the next header looks in').toBeLessThan(PHONE.width);

    await header(page, 'August').click();
    await expect(page.getByText('The numbers of August.')).toBeVisible();
    expect(await inSight(page, 'August')).toBe(true);
    expect(await fading(page), 'the line ends here').toEqual({ before: true, after: false, mask: 'clear-solid' });
    // Two tabs in a row are one report: the server only needs to know where the user ended up.
    await reported(sent, 1);

    // The user moves the line back by hand.
    await months(page).evaluate((list) => { list.scrollLeft = 0; });
    await expect.poll(() => fading(page)).toEqual({ before: false, after: true, mask: 'solid-clear' });

    // The keyboard reaches a header that is half out of sight. It keeps clear of the fading too.
    await header(page, 'January').focus();
    for (const month of ['February', 'March', 'April', 'May', 'June']) {
        await page.keyboard.press('Tab');
        await expect(header(page, month)).toBeFocused();
    }
    const reached = await header(page, 'June').boundingBox();
    expect(reached.x + reached.width).toBeLessThanOrEqual(PHONE.width - 28);
    await expect(page.getByText('The numbers of August.'), 'the focus alone opens nothing').toBeVisible();

    // The page is drawn again, by the server and then by the page script.
    await page.reload();
    await expect(page.getByText('The numbers of August.')).toBeVisible();
    await expect.poll(() => inSight(page, 'August'), 'after a reload').toBe(true);

    await page.getByLabel('Owner').fill('Ava');
    await page.getByLabel('Owner').press('Tab');
    await expect(note(page)).toHaveText('Owner: Ava. Status: all. Limit: 10.');
    await expect.poll(() => inSight(page, 'August'), 'after the page was swapped').toBe(true);
    expect(await noHorizontalOverflow(page)).toBe(true);
});

test('a line of headers that comes into sight later finds its place then', async ({ page }) => {
    await page.setViewportSize(PHONE);
    const sent = posts(page);
    await page.goto('/test-tabs');

    // The years are behind the tab "About", which is not open.
    const years = page.locator('#t-years > [role="tablist"]');
    await expect(header(page, '2015')).toBeHidden();

    await header(page, 'About').click();
    await expect(header(page, '2015')).toBeVisible();
    await expect(years, 'twelve years do not fit, and the line says so').toHaveClass(/sui-more-after/);
    await expect(years).not.toHaveClass(/sui-more-before/);

    await header(page, '2026').click();
    await expect(page.getByText('The numbers of 2026.')).toBeVisible();
    await header(page, 'Orders').click();
    await expect(header(page, '2026')).toBeHidden();
    await reported(sent, 1);

    // The page comes back with "Orders" open, and the last year open behind "About".
    await page.reload();
    await expect(status(page)).toBeVisible();
    await expect(header(page, '2026')).toBeHidden();

    await header(page, 'About').click();
    await expect(page.getByText('The numbers of 2026.')).toBeVisible();
    await expect.poll(() => inSight(page, '2026'), 'the open header is brought into sight with its tab').toBe(true);
    await expect(years).toHaveClass(/sui-more-before/);
    await expect(years).not.toHaveClass(/sui-more-after/);
});

test('on a phone that reads from right to left the open header stays in sight too', async ({ page }) => {
    await page.setViewportSize(PHONE);
    const sent = posts(page);
    await page.goto('/test-tabs-ar');

    expect(await noHorizontalOverflow(page)).toBe(true);
    expect(await inSight(page, 'يناير'), 'the first month stands on the right').toBe(true);
    expect(await inSight(page, 'أغسطس'), 'and the last is cut off on the left').toBe(false);
    await expect.poll(() => fading(page)).toEqual({ before: false, after: true, mask: 'solid-clear' });
    expect(await fadesToward(page), 'here the line goes on to the left').toBe('left');

    // A header in the middle keeps clear of both ends.
    await header(page, 'يونيو').click();
    await expect(page.getByText('أرقام شهر يونيو.')).toBeVisible();
    expect(await fading(page)).toEqual({ before: true, after: true, mask: 'clear-clear' });
    const june = await header(page, 'يونيو').boundingBox();
    expect(june.x, 'the open header is not under the fading').toBeGreaterThanOrEqual(28);

    await header(page, 'أغسطس').click();
    await expect(page.getByText('أرقام شهر أغسطس.')).toBeVisible();
    await reported(sent, 1);

    await page.reload();
    await expect(page.getByText('أرقام شهر أغسطس.')).toBeVisible();
    await expect.poll(() => inSight(page, 'أغسطس'), 'after a reload').toBe(true);
    expect(await fading(page), 'the line ends here, on the left').toEqual({ before: true, after: false, mask: 'clear-solid' });
    expect(await noHorizontalOverflow(page)).toBe(true);
});

test.describe('without JavaScript', () => {
    test.use({ javaScriptEnabled: false });

    test('a header sends the form, and the page comes back with that tab in view', async ({ page }) => {
        await page.goto('/test-tabs');
        await expect(limit(page)).toBeHidden();

        await page.getByLabel('Owner').fill('Ava');
        await header(page, 'Settings').click();
        await expect(limit(page)).toBeVisible();
        await expect(header(page, 'Settings')).toHaveAttribute('aria-selected', 'true');
        await expect(note(page), 'what was typed went along').toHaveText('Owner: Ava. Status: all. Limit: 10.');

        await limit(page).fill('30');
        await page.getByRole('button', { name: 'Check' }).click();
        await expect(page.locator('.sui-tab-panel .sui-callout')).toHaveText('Checked with a limit of 30.');
        await expect(limit(page), 'the tab is still the open one').toBeVisible();

        await header(page, 'March').click();
        await expect(page.getByText('The numbers of March.')).toBeVisible();
        await expect(limit(page), 'the other group of tabs was left alone').toBeVisible();

        // Enter in a field is not a press on the first header.
        await page.getByLabel('Owner').press('Enter');
        await expect(limit(page)).toBeVisible();
        await expect(page.getByText('The numbers of March.')).toBeVisible();
    });
});
