import { test, expect } from '@playwright/test';
import { PHONE, markDocument, noHorizontalOverflow, sameDocument, watch } from './support.mjs';
import { posts } from './reports.mjs';

// The page of these tests is e2e/tools/test-progress.php: a task of four steps. Every run that
// the page starts by itself does one step, so a test says when the task moves on.
//
// The clock of the page stands still in every test: time passes when the test says so.
const START = new Date('2026-03-02T09:00:00Z');

const bar = (page) => page.getByLabel('Working');
const beside = (page) => page.locator('#sui-body .sui-progress-text');
const line = (page) => page.locator('#sui-body .sui-refresh');
const done = (page) => page.locator('#sui-body .sui-success', { hasText: 'The task is done.' });

/** The runs that the page started by itself. */
const own = (sent) => sent.runs.filter((request) => 'refresh' === request.headers()['x-script-ui']);

/** Gives a run that should not start the time to show up. */
async function still(page, sent, count, why) {
    await page.waitForTimeout(150);
    expect(own(sent).length, why).toBe(count);
}

/** Opens the page with no task on it. */
async function open(page) {
    await page.clock.install({ time: new Date(START.getTime() - 10_000) });
    await page.clock.pauseAt(START);
    await page.goto('/test-progress');
    await page.getByRole('button', { name: 'Reset' }).click();
    await expect(page.locator('#sui-body > .sui-note')).toHaveText('No task.');

    return posts(page);
}

/** The page goes out of sight (another tab is looked at) or comes back. */
const lookAway = (page, away) => page.evaluate((hidden) => {
    Object.defineProperty(document, 'hidden', { configurable: true, get: () => hidden });
    Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => (hidden ? 'hidden' : 'visible') });
    document.dispatchEvent(new Event('visibilitychange'));
}, away);

test.beforeEach(({ page }) => {
    watch(page);
});

test.afterEach(({ page }) => {
    expect(page.problems, 'script errors, blocked content and server errors').toEqual([]);
});

test('a bar shows how far a task is, moves on while the page runs again, and the page stands still at the end', async ({ page }) => {
    const sent = await open(page);
    await markDocument(page);

    await page.getByRole('button', { name: 'Start', exact: true }).click();

    // The bar is the browser's own, named by its label, with the words of the tool beside it.
    await expect(bar(page)).toHaveJSProperty('tagName', 'PROGRESS');
    await expect(bar(page)).toHaveJSProperty('value', 0);
    await expect(bar(page)).toHaveJSProperty('max', 4);
    await expect(beside(page)).toHaveText('0 of 4 steps');

    for (const step of [1, 2, 3]) {
        await page.clock.runFor(2_000);
        await expect(bar(page)).toHaveJSProperty('value', step);
        await expect(beside(page)).toHaveText(`${step} of 4 steps`);
    }
    expect(own(sent).length).toBe(3);

    // The last step. The task says that it is done, and the page no longer asks to be run.
    await page.clock.runFor(2_000);
    await expect(done(page)).toBeVisible();
    await expect(page.locator('#sui-body progress')).toHaveCount(0);
    await expect(line(page)).toHaveCount(0);

    await page.clock.runFor(60_000);
    await still(page, sent, 4, 'nothing runs any more');
    expect(await sameDocument(page), 'all of it without a page load').toBe(true);
});

// The second button says it and calls rerun(): the message goes along to the page that is loaded then.
for (const start of ['Start and say so', 'Start, say so and run again']) {
    test(`what a button said next to a running bar gets its ten seconds, and then the page goes on by itself ("${start}")`, async ({ page }) => {
        const sent = await open(page);

        await page.getByRole('button', { name: start }).click();
        const news = page.locator('#sui-body .sui-success', { hasText: 'The task started.' });
        await expect(news).toBeVisible();
        await expect(bar(page)).toHaveJSProperty('value', 0);

        // Nobody touches the page. Somebody who started a task watches it.
        await page.clock.runFor(9_900);
        await still(page, sent, 0, 'the news gets the time to be read');
        await expect(news).toBeVisible();

        await page.clock.runFor(100);
        await expect(bar(page)).toHaveJSProperty('value', 1);
        await expect(news).toBeHidden();

        // From here on the page runs at its own rate again.
        await page.clock.runFor(2_000);
        await expect(bar(page)).toHaveJSProperty('value', 2);
    });
}

test('one bar that is under way is enough, next to one that is full', async ({ page }) => {
    await open(page);

    await page.getByRole('button', { name: 'Start two and say so' }).click();
    const news = page.locator('#sui-body .sui-success', { hasText: 'The second task started.' });
    await expect(news).toBeVisible();
    await expect(page.getByLabel('Unpacking')).toHaveJSProperty('position', 1);
    await expect(bar(page)).toHaveJSProperty('value', 0);

    await page.clock.runFor(10_000);
    await expect(bar(page)).toHaveJSProperty('value', 1);
    await expect(news).toBeHidden();
});

test('a browser that cannot say what is hidden takes every bar to be watched', async ({ page }) => {
    await page.addInitScript(() => { delete Element.prototype.checkVisibility; });
    await open(page);
    expect(await page.evaluate(() => typeof document.body.checkVisibility)).toBe('undefined');

    await page.getByRole('button', { name: 'Start and say so' }).click();
    await expect(page.locator('#sui-body .sui-success', { hasText: 'The task started.' })).toBeVisible();
    // The attribute, not the property: to read a property Playwright runs its own code in the page, and that
    // code needs the method this test took away.
    await expect(bar(page)).toHaveAttribute('value', '0');

    await page.clock.runFor(10_000);
    await expect(bar(page)).toHaveAttribute('value', '1');
});

test('a bar without a value says that a task is under way too', async ({ page }) => {
    const sent = await open(page);

    await page.getByRole('button', { name: 'Queue and say so' }).click();
    // info() is good news as success() is.
    const news = page.locator('#sui-body .sui-info', { hasText: 'The task waits for a worker.' });
    await expect(news).toBeVisible();
    // Work is going on, and nobody knows how far it is: the bar has no value and no words beside it.
    const waiting = page.getByLabel('Waiting for a worker');
    await expect(waiting).toHaveJSProperty('tagName', 'PROGRESS');
    await expect(waiting).toHaveJSProperty('position', -1);
    await expect(beside(page)).toHaveCount(0);
    const room = () => page.locator('#sui-body .sui-progress').evaluate((element) => element.getBoundingClientRect().height);
    const before = await room();

    await page.clock.runFor(9_900);
    await still(page, sent, 0, 'the news gets the time to be read');
    await page.clock.runFor(100);

    // The worker took the task: now the bar says how far it is.
    await expect(bar(page)).toHaveJSProperty('value', 0);
    await expect(waiting).toHaveCount(0);
    await expect(news).toBeHidden();
    // The words beside the bar need no room that the bar alone did not have: a button under it stays where it was.
    await expect(beside(page)).toHaveText('0 of 4 steps');
    expect(await room(), 'what is under the bar does not jump').toBe(before);
});

test('news that arrives while the page is out of sight waits for the user, bar or no bar', async ({ page }) => {
    const sent = await open(page);

    // The answer to the button is held back until the page is out of sight.
    let release;
    const held = new Promise((resolve) => { release = resolve; });
    await page.route('**/test-progress', async (route) => {
        if ('POST' === route.request().method() && (route.request().postData() ?? '').includes('start-and-say-so')) await held;
        await route.continue();
    });
    await page.getByRole('button', { name: 'Start and say so' }).click();
    await lookAway(page, true);
    release();
    const news = page.locator('#sui-body .sui-success', { hasText: 'The task started.' });
    await expect(news).toBeAttached();

    // Nobody saw it arrive. Coming back is not enough: the page waits for a touch, and then for ten seconds.
    await lookAway(page, false);
    await page.clock.runFor(40_000);
    await still(page, sent, 0, 'nobody has read it yet');
    await expect(news).toBeVisible();

    await page.mouse.move(1200, 600);
    await page.clock.runFor(10_000);
    await expect(bar(page)).toHaveJSProperty('value', 1);
    await expect(news).toBeHidden();
});

test('news next to a bar that is full waits for the user, as it does on any other page', async ({ page }) => {
    const sent = await open(page);
    await page.getByRole('button', { name: 'Show a full bar' }).click();
    await expect(bar(page)).toHaveJSProperty('position', 1);
    await expect(beside(page)).toHaveText('100%');

    await page.getByRole('button', { name: 'Say something' }).click();
    const news = page.locator('#sui-body .sui-success', { hasText: 'Said.' });
    await expect(news).toBeVisible();

    // No task is under way, so nobody is taken to be watching.
    await page.clock.runFor(40_000);
    await still(page, sent, 0, 'nobody is there to read it');
    await expect(news).toBeVisible();
});

for (const [start, where, around] of [
    ['Start out of sight', 'in an expander that is closed', 'details:not([open])'],
    ['Start behind a tab', 'behind a tab that is not the open one', '[role="tabpanel"][hidden]'],
]) {
    test(`news next to a running bar ${where} waits for the user: nobody watches a bar they cannot see`, async ({ page }) => {
        const sent = await open(page);
        await page.getByRole('button', { name: start }).click();

        // The task is under way, and its bar is on the page where nobody sees it.
        const unseen = page.locator(`#sui-body ${around} progress`);
        await expect(unseen).toHaveJSProperty('position', 0);
        await expect(unseen).toBeHidden();

        await page.getByRole('button', { name: 'Say something' }).click();
        const news = page.locator('#sui-body .sui-success', { hasText: 'Said.' });
        await expect(news).toBeVisible();

        await page.clock.runFor(40_000);
        await still(page, sent, 0, 'nobody is there to read it');
        await expect(news).toBeVisible();
        await expect(unseen).toHaveJSProperty('value', 0);
    });
}

test('bad news next to a running bar waits for the user: a warning is not passed by', async ({ page }) => {
    const sent = await open(page);

    await page.getByRole('button', { name: 'Start and warn' }).click();
    const news = page.locator('#sui-body .sui-warning', { hasText: 'The task started late.' });
    await expect(news).toBeVisible();
    await expect(bar(page)).toHaveJSProperty('value', 0);

    await page.clock.runFor(40_000);
    await still(page, sent, 0, 'a warning is read before it goes');
    await expect(news).toBeVisible();

    // Back at the page: ten seconds to read it, and then the bar goes on.
    await page.mouse.move(1200, 600);
    await page.clock.runFor(10_000);
    await expect(bar(page)).toHaveJSProperty('value', 1);
    await expect(news).toBeHidden();
});

test('what is wrong with a field next to a running bar waits for the user too', async ({ page }) => {
    const sent = await open(page);

    await page.getByRole('button', { name: 'Start with a wrong field' }).click();
    const wrong = page.locator('#sui-body .sui-field-error');
    await expect(wrong).toContainText('A name is needed.');
    await expect(bar(page)).toHaveJSProperty('value', 0);

    await page.clock.runFor(40_000);
    await still(page, sent, 0, 'what is wrong with a field is read before it goes');
    await expect(wrong).toBeVisible();
});

test('a run of the user that never arrives waits for them, next to a running bar too', async ({ page }) => {
    const sent = await open(page);
    await page.getByRole('button', { name: 'Start', exact: true }).click();
    await expect(bar(page)).toHaveJSProperty('value', 0);

    // The server is away for what the user asks. The page keeps what it showed, with the bar.
    await page.route('**/test-progress', (route) => ('fragment' === route.request().headers()['x-script-ui']
        ? route.abort()
        : route.continue()));
    await page.getByRole('button', { name: 'Reset' }).click();
    await expect(page.locator('#sui-offline')).toBeVisible();
    await expect(bar(page)).toHaveJSProperty('value', 0);

    await page.clock.runFor(40_000);
    await still(page, sent, 0, 'what went wrong is still there to be read');
    page.problems = page.problems.filter((problem) => !/Failed to load resource|net::ERR_FAILED/.test(problem));
});

test('on a page that reads from right to left the bar starts at the right, and its percentage stands to its left', async ({ page }) => {
    await page.goto('/test-progress-ar');
    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');

    const arabic = page.getByLabel('استيراد الطلبات');
    await expect(arabic).toHaveJSProperty('value', 2);
    await expect(beside(page)).toHaveText('40%');
    expect(await arabic.evaluate((element) => getComputedStyle(element).direction), 'the bar fills from the right').toBe('rtl');

    const place = (locator) => locator.evaluate((element) => {
        const box = element.getBoundingClientRect();
        return { left: box.left, right: box.right };
    });
    const [filled, words, label] = [await place(arabic), await place(beside(page)), await place(page.locator('#sui-body .sui-progress > label'))];
    expect(words.right, 'the words come after the bar, which is to its left here').toBeLessThanOrEqual(filled.left);
    expect(Math.abs(label.right - filled.right), 'the label and the bar begin at the same edge').toBeLessThan(1);
});

test.describe('in a phone-sized window', () => {
    test.use({ viewport: PHONE });

    test('the bar and the words beside it fit the screen', async ({ page }) => {
        await open(page);
        await page.getByRole('button', { name: 'Start', exact: true }).click();

        await expect(bar(page)).toBeInViewport({ ratio: 1 });
        await expect(beside(page)).toBeInViewport({ ratio: 1 });
        expect(await noHorizontalOverflow(page), 'the page does not scroll sideways').toBe(true);
        // The bar takes the room that the words leave.
        const width = await bar(page).evaluate((element) => element.getBoundingClientRect().width);
        expect(width).toBeGreaterThan(160);
    });
});

test.describe('without JavaScript', () => {
    test.use({ javaScriptEnabled: false });

    test('the bar shows how far the task was when the page was made', async ({ page }) => {
        await page.goto('/test-progress');
        await page.getByRole('button', { name: 'Reset' }).click();
        await page.getByRole('button', { name: 'Start', exact: true }).click();

        // Nothing runs by itself, so the task of this test stands still. The bar is drawn all the same.
        await expect(bar(page)).toHaveAttribute('value', '0');
        await expect(bar(page)).toHaveAttribute('max', '4');
        await expect(beside(page)).toHaveText('0 of 4 steps');
        await page.reload();
        await expect(bar(page)).toHaveAttribute('value', '0');
    });
});
