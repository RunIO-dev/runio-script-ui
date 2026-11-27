import { test, expect } from '@playwright/test';
import { PHONE, markDocument, noHorizontalOverflow, sameDocument, settled, watch } from './support.mjs';

// The page of these tests is e2e/tools/test-choices.php: a group of radio buttons under each
// other, one in a row, a long list of tick boxes, a short one in a row, and a table below.
const note = (page) => page.locator('#sui-body .sui-note');
const radio = (page, name) => page.getByRole('radio', { name, exact: true });
const box = (page, name) => page.getByRole('checkbox', { name, exact: true });
const pageNow = (page) => page.locator('.sui-page-now');

/** The form posts from now on. */
function posts(page) {
    const seen = [];
    page.on('request', (request) => {
        if ('POST' === request.method()) seen.push(request);
    });

    return seen;
}

test.beforeEach(({ page }) => {
    watch(page);
});

test.afterEach(({ page }) => {
    expect(page.problems, 'script errors, blocked content and server errors').toEqual([]);
});

test('a click on an option runs the tool again with it', async ({ page }) => {
    await page.goto('/test-choices');
    await markDocument(page);

    await expect(radio(page, 'Free')).toBeChecked();
    await expect(radio(page, 'Yearly'), 'the default of the tool').toBeChecked();

    await radio(page, 'Team').check();
    await expect(note(page)).toContainText('Plan: team.');
    await expect(radio(page, 'Team')).toBeChecked();
    await expect(radio(page, 'Team'), 'the focus stays on the option').toBeFocused();

    await radio(page, 'Monthly').check();
    await expect(note(page)).toContainText('Billing: 1.');
    await expect(radio(page, 'Team'), 'the other group keeps its choice').toBeChecked();
    expect(await sameDocument(page), 'the page was updated in place').toBe(true);

    // What is chosen is still there when the page is opened again.
    await page.reload();
    await expect(radio(page, 'Team')).toBeChecked();
    await expect(radio(page, 'Monthly')).toBeChecked();
});

test('the arrow keys walk through the options, and the tool runs once at the end', async ({ page }) => {
    await page.goto('/test-choices');
    const sent = posts(page);

    await radio(page, 'Free').focus();
    await page.keyboard.press('ArrowDown');
    await page.keyboard.press('ArrowDown');
    await page.keyboard.press('ArrowDown');

    const last = page.getByRole('radio', { name: /^Enterprise/ });
    await expect(note(page)).toContainText('Plan: enterprise.');
    await expect(last).toBeChecked();
    await expect(last).toBeFocused();
    expect(sent.length, 'one request for three steps').toBe(1);

    // The group still answers to the keys after the page was swapped.
    await page.keyboard.press('ArrowUp');
    await expect(note(page)).toContainText('Plan: business.');
    await expect(radio(page, 'Business')).toBeChecked();
    await expect(radio(page, 'Business')).toBeFocused();
    expect(sent.length).toBe(2);
});

test('an answer that is on its way does not put back the option the user has left', async ({ page }) => {
    await page.goto('/test-choices');
    // Every answer takes a moment: less than the pause the page waits for after a key.
    await page.route('**/test-choices', async (route) => {
        if ('POST' === route.request().method()) await new Promise((resolve) => { setTimeout(resolve, 150); });
        await route.continue();
    });

    // A click is sent at once. The key comes before its answer is there.
    await radio(page, 'Team').click();
    await page.keyboard.press('ArrowDown');

    await expect(note(page)).toContainText('Plan: business.');
    await expect(radio(page, 'Business')).toBeChecked();
    await expect(radio(page, 'Business')).toBeFocused();
    await expect(radio(page, 'Team')).not.toBeChecked();
});

test('the page only waits after a key: a click is sent at once', async ({ page }) => {
    // The clock of the page stands still here, so a pause that the page waits for never ends by itself.
    await page.clock.install();
    await page.goto('/test-choices');
    await page.clock.pauseAt(Date.now() + 60_000);
    const sent = posts(page);

    await radio(page, 'Free').focus();
    await page.keyboard.press('ArrowDown');
    await expect(radio(page, 'Team')).toBeChecked();
    await page.waitForTimeout(400);
    expect(sent.length, 'after a key the page waits for a pause').toBe(0);

    await page.clock.runFor(250);
    await expect.poll(() => sent.length).toBe(1);
    await expect(note(page)).toContainText('Plan: team.');

    // A click that assistive software makes for the user comes without a mouse.
    await radio(page, 'Business').evaluate((option) => option.click());
    await expect.poll(() => sent.length, 'a click is sent without a pause').toBe(2);
    await expect(note(page)).toContainText('Plan: business.');

    // A mouse click counts at once even while an arrow key is still down.
    await radio(page, 'Business').focus();
    await page.keyboard.down('ArrowDown');
    await expect(page.getByRole('radio', { name: /^Enterprise/ })).toBeChecked();
    await radio(page, 'Free').click();
    await expect.poll(() => sent.length).toBe(3);
    await expect(note(page)).toContainText('Plan: free.');
    await page.keyboard.up('ArrowDown');
});

test('a key that never comes up does not leave the page waiting', async ({ page }) => {
    await page.clock.install();
    await page.goto('/test-choices');
    await page.clock.pauseAt(Date.now() + 60_000);
    const sent = posts(page);

    // The window loses the focus while an arrow key is down. The page never hears the key come up.
    await radio(page, 'Free').focus();
    await page.keyboard.down('ArrowDown');
    await expect(radio(page, 'Team')).toBeChecked();
    await page.evaluate(() => window.dispatchEvent(new Event('blur')));

    await radio(page, 'Business').evaluate((option) => option.click());
    await expect.poll(() => sent.length, 'the click is sent without a pause').toBe(1);
    await expect(note(page)).toContainText('Plan: business.');
    await page.keyboard.up('ArrowDown');
});

test('a run that waited behind a dropped answer is not sent on its own', async ({ page }) => {
    await page.clock.install();
    await page.goto('/test-choices');
    await page.clock.pauseAt(Date.now() + 60_000);
    // Every answer takes a second: long enough for everything below to happen on the way.
    await page.route('**/test-choices', async (route) => {
        if ('POST' === route.request().method()) await new Promise((resolve) => { setTimeout(resolve, 1000); });
        await route.continue();
    });
    const sent = posts(page);
    const search = page.locator('input[type="search"]');

    // A run is on its way, and the search of the table queues up behind it.
    await radio(page, 'Team').click();
    await search.fill('Mar');
    await page.clock.runFor(300);

    // Leaving the search box sends what was typed. The queued run is still wanted after that one.
    await radio(page, 'Team').focus();
    await expect.poll(() => sent.length).toBe(2);

    // An arrow key drops the answer that is on its way. Nothing is sent until the user pauses.
    await page.keyboard.press('ArrowDown');
    await page.waitForTimeout(300);
    expect(sent.length, 'the queued run does not go out on its own').toBe(2);

    await page.clock.runFor(250);
    await expect.poll(() => sent.length).toBe(3);
    await expect(note(page)).toContainText('Plan: business.');
    await expect(search, 'and the run that is sent carries everything').toHaveValue('Mar');
});

test('ticked boxes come back as a list, in the order of the options', async ({ page }) => {
    await page.goto('/test-choices');
    await markDocument(page);
    await expect(note(page)).toContainText('Months: March.');

    await box(page, 'November').check();
    await expect(note(page)).toContainText('Months: March, November.');
    await box(page, 'January').check();
    await expect(note(page)).toContainText('Months: January, March, November.');
    await expect(box(page, 'January')).toBeFocused();

    for (const month of ['January', 'March', 'November']) {
        await box(page, month).uncheck();
        await settled(page);
    }
    await expect(note(page)).toContainText('Months: none.');
    expect(await sameDocument(page)).toBe(true);

    // Nothing ticked is remembered too: the default does not come back with the next page view.
    await page.reload();
    await expect(note(page)).toContainText('Months: none.');
    await expect(box(page, 'March')).not.toBeChecked();
});

test('a tick box keeps the focus when the page is swapped', async ({ page }) => {
    await page.goto('/test-choices');

    await box(page, 'May').focus();
    await page.keyboard.press('Space');
    await expect(note(page)).toContainText('Months: March, May.');
    await expect(box(page, 'May')).toBeFocused();

    await page.keyboard.press('Space');
    await expect(note(page)).toContainText('Months: March.');
    await expect(box(page, 'May')).toBeFocused();

    await page.keyboard.press('Tab');
    await expect(box(page, 'June'), 'the keyboard goes on from where it was').toBeFocused();
});

test('what is chosen travels with a button', async ({ page }) => {
    await page.goto('/test-choices');

    await radio(page, 'Business').check();
    await box(page, 'May').check();
    await box(page, 'VIP').check();
    await expect(note(page)).toContainText('Plan: business. Billing: 12. Months: March, May. Tags: vip.');

    await page.getByRole('button', { name: 'Save' }).click();
    await expect(page.locator('.sui-callout')).toHaveText('Saved business for 2 of 12 months.');
    await expect(box(page, 'VIP')).toBeChecked();
});

test('a table below keeps its page until a choice gives it other rows', async ({ page }) => {
    await page.goto('/test-choices');
    await page.getByRole('button', { name: 'Next' }).click();
    await expect(pageNow(page)).toHaveText('Page 2 of 3');

    // A month changes nothing in the rows.
    await box(page, 'May').check();
    await expect(note(page)).toContainText('Months: March, May.');
    await expect(pageNow(page)).toHaveText('Page 2 of 3');

    // The plan is in every row.
    await radio(page, 'Team').check();
    await expect(note(page)).toContainText('Plan: team.');
    await expect(pageNow(page)).toHaveText('Page 1 of 3');
});

test('a link can choose an option and tick boxes', async ({ page }) => {
    await page.goto('/test-choices?plan=business&months[]=May&months[]=June&tags=vip');

    await expect(note(page)).toContainText('Plan: business. Billing: 12. Months: May, June. Tags: vip.');
    await expect(radio(page, 'Business')).toBeChecked();
    await expect(box(page, 'June')).toBeChecked();
    await expect(box(page, 'March'), 'the link names the months, so the default does not count').not.toBeChecked();

    // From the first change on the page is its own again: the address drops what the link filled in.
    await box(page, 'June').uncheck();
    await expect(note(page)).toContainText('Months: May.');
    await expect(page).toHaveURL(/\/test-choices$/);
});

test('a screen reader hears the name of a group and its help text', async ({ page }) => {
    await page.goto('/test-choices');

    const plan = page.getByRole('group', { name: 'Plan' });
    await expect(plan).toHaveAccessibleDescription('The arrow keys walk through the plans.');
    await expect(plan.getByRole('radio')).toHaveCount(4);

    const months = page.getByRole('group', { name: 'Months' });
    await expect(months).toHaveAccessibleDescription('Tick the months that count.');
    await expect(months.getByRole('checkbox')).toHaveCount(12);
    await expect(months.getByRole('checkbox', { checked: true })).toHaveCount(1);
});

test('a long list stands in columns, and every option is large enough to hit', async ({ page }) => {
    await page.goto('/test-choices');

    const place = async (name) => box(page, name).boundingBox();
    const [january, february, april] = [await place('January'), await place('February'), await place('April')];
    expect(february.y, 'the list is read down the first column').toBeGreaterThan(january.y);
    expect(april.x, 'and then down the next').toBeGreaterThan(january.x + 100);
    expect(Math.abs(april.y - january.y)).toBeLessThan(2);

    // In a row the options stand side by side.
    const [monthly, yearly] = [await radio(page, 'Monthly').boundingBox(), await radio(page, 'Yearly').boundingBox()];
    expect(yearly.x).toBeGreaterThan(monthly.x);
    expect(Math.abs(yearly.y - monthly.y)).toBeLessThan(2);

    for (const option of await page.locator('.sui-choices .sui-check').all()) {
        const size = await option.boundingBox();
        expect(size.height, 'an option is at least 24 pixels high').toBeGreaterThanOrEqual(24);
    }
});

test('the groups fit a phone', async ({ page }) => {
    await page.setViewportSize(PHONE);
    await page.goto('/test-choices');

    expect(await noHorizontalOverflow(page), 'the long option breaks into lines').toBe(true);

    const [january, july] = [await box(page, 'January').boundingBox(), await box(page, 'July').boundingBox()];
    expect(july.x, 'the months stand in two columns').toBeGreaterThan(january.x + 100);
    expect(Math.abs(july.y - january.y)).toBeLessThan(2);

    await box(page, 'December').check();
    await expect(note(page)).toContainText('Months: March, December.');
    expect(await noHorizontalOverflow(page)).toBe(true);
});

test('an Arabic page lays its options out from the right', async ({ page }) => {
    await page.goto('/test-choices-ar');
    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');

    const [first, second] = [await radio(page, 'مدفوع').boundingBox(), await radio(page, 'تم الشحن').boundingBox()];
    expect(first.x, 'the first option stands at the right').toBeGreaterThan(second.x);

    // Ten cities stand in columns, and the first column is the right one.
    const [amman, london] = [await box(page, 'عمّان').boundingBox(), await box(page, 'London').boundingBox()];
    expect(amman.x).toBeGreaterThan(london.x + 100);

    await expect(page.locator('.sui-choices-none'), 'a group without options says so').toHaveText('لا توجد خيارات.');

    await box(page, 'القاهرة').check();
    await expect(note(page)).toContainText('المدن: عمّان، القاهرة.');

    await page.setViewportSize(PHONE);
    expect(await noHorizontalOverflow(page)).toBe(true);
});

test.describe('without JavaScript', () => {
    test.use({ javaScriptEnabled: false });

    test('what is chosen is sent with the next button', async ({ page }) => {
        await page.goto('/test-choices');

        await radio(page, 'Business').check();
        await box(page, 'May').check();
        await box(page, 'March').uncheck();
        await expect(note(page), 'nothing has run yet').toContainText('Plan: free.');

        await page.getByRole('button', { name: 'Save' }).click();

        await expect(page.locator('.sui-callout')).toHaveText('Saved business for 1 of 12 months.');
        await expect(note(page)).toContainText('Plan: business. Billing: 12. Months: May. Tags: none.');
        await expect(radio(page, 'Business')).toBeChecked();
        await expect(box(page, 'May')).toBeChecked();
        await expect(box(page, 'March')).not.toBeChecked();
    });

    test('unticking every box is sent as nothing ticked', async ({ page }) => {
        await page.goto('/test-choices');

        await box(page, 'March').uncheck();
        await page.getByRole('button', { name: 'Save' }).click();

        await expect(note(page)).toContainText('Months: none.');
        await expect(page.locator('.sui-callout')).toHaveText('Saved free for 0 of 12 months.');
    });
});
