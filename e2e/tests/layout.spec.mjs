import { test, expect } from '@playwright/test';
import { PHONE, markDocument, noHorizontalOverflow, sameDocument, watch } from './support.mjs';

// The page of these tests is e2e/tools/test-layout.php: a box that starts closed with a field
// and a button inside, a box that starts open, and a line below that says what the tool got.
const note = (page) => page.locator('#sui-body > .sui-note');
const line = (page, name) => page.locator('details.sui-expander > summary', { hasText: name });
const limit = (page) => page.getByLabel('Limit');
const about = (page) => page.getByText('It only exists for the tests.');

/** What the page posts from now on: the runs of the tool, and the reports about its parts. */
function posts(page) {
    const seen = { runs: [], reports: [] };
    page.on('request', (request) => {
        if ('POST' !== request.method()) return;
        ('layout' === request.headers()['x-script-ui'] ? seen.reports : seen.runs).push(request);
    });

    return seen;
}

/** Waits until the server has answered the report with this number. */
async function reported(sent, count) {
    await expect.poll(() => sent.reports.length, 'the server is told how the parts stand').toBe(count);
    const response = await sent.reports[count - 1].response();
    expect(response.status()).toBe(204);
}

test.beforeEach(({ page }) => {
    watch(page);
});

test.afterEach(({ page }) => {
    expect(page.problems, 'script errors, blocked content and server errors').toEqual([]);
});

test('a box opens and closes without a run of the tool, and a reload finds it as it was left', async ({ page }) => {
    const sent = posts(page);
    await page.goto('/test-layout');

    await expect(limit(page)).toBeHidden();
    await expect(about(page), 'this one starts open').toBeVisible();

    // A box that the server draws open says nothing new: the browser announces it all the same.
    await page.waitForTimeout(600);
    expect(sent.reports.length, 'a page view reports nothing').toBe(0);

    await line(page, 'Advanced options').click();
    await expect(limit(page)).toBeVisible();
    await reported(sent, 1);
    expect(sent.runs.length, 'no tool ran for that').toBe(0);

    await page.reload();
    await expect(limit(page)).toBeVisible();

    await line(page, 'About this tool').click();
    await expect(about(page)).toBeHidden();
    await reported(sent, 2);

    await page.reload();
    await expect(about(page)).toBeHidden();
    await expect(limit(page)).toBeVisible();
});

test('what is inside a closed box still counts, and the box stays closed', async ({ page }) => {
    const sent = posts(page);
    await page.goto('/test-layout');
    await markDocument(page);

    await line(page, 'Advanced options').click();
    await limit(page).fill('25');
    await limit(page).press('Tab');
    await expect(note(page)).toHaveText('Owner: nobody. Limit: 25.');
    await expect(limit(page), 'the box is still open after the page was swapped').toBeVisible();

    await line(page, 'Advanced options').click();
    await expect(limit(page)).toBeHidden();

    await page.getByLabel('Owner').fill('Ava');
    await page.getByLabel('Owner').press('Tab');
    await expect(note(page)).toHaveText('Owner: Ava. Limit: 25.');
    await expect(limit(page), 'and closed after the next one').toBeHidden();
    expect(await sameDocument(page)).toBe(true);
    expect(sent.runs.length).toBe(2);
});

test('a box that is opened while an answer is on its way stays open', async ({ page }) => {
    await page.goto('/test-layout');
    // Every run of the tool takes a moment.
    await page.route('**/test-layout', async (route) => {
        const request = route.request();
        if ('POST' === request.method() && 'layout' !== request.headers()['x-script-ui']) {
            await new Promise((resolve) => { setTimeout(resolve, 400); });
        }
        await route.continue();
    });

    await page.getByLabel('Owner').fill('Ava');
    await page.getByLabel('Owner').press('Tab');
    // The answer that is on its way was asked for while the box was closed.
    await line(page, 'Advanced options').click();
    await expect(limit(page)).toBeVisible();

    await expect(note(page)).toHaveText('Owner: Ava. Limit: 10.');
    await expect(limit(page), 'the answer did not close it again').toBeVisible();

    // And the server hears of it with the next request.
    await limit(page).fill('40');
    await limit(page).press('Tab');
    await expect(note(page)).toHaveText('Owner: Ava. Limit: 40.');
    await expect(limit(page)).toBeVisible();
});

test('the keyboard opens a box, and the answer to a button inside is shown in it', async ({ page }) => {
    await page.goto('/test-layout');

    await line(page, 'Advanced options').focus();
    await page.keyboard.press('Enter');
    await expect(limit(page)).toBeVisible();
    await expect(line(page, 'Advanced options')).toBeFocused();

    await page.keyboard.press('Tab');
    await expect(limit(page)).toBeFocused();

    await page.getByRole('button', { name: 'Check' }).click();
    const answer = page.locator('details.sui-expander .sui-callout');
    await expect(answer).toHaveText('Checked with a limit of 10.');
    await expect(answer).toBeVisible();
    await expect(page.getByRole('button', { name: 'Check' })).toBeFocused();
});

test('a box fits a phone', async ({ page }) => {
    await page.setViewportSize(PHONE);
    await page.goto('/test-layout');

    await line(page, 'Advanced options').click();
    await expect(limit(page)).toBeVisible();
    expect(await noHorizontalOverflow(page)).toBe(true);

    const size = await line(page, 'Advanced options').boundingBox();
    expect(size.height, 'the line is tall enough for a finger').toBeGreaterThanOrEqual(38);
});

test.describe('without JavaScript', () => {
    test.use({ javaScriptEnabled: false });

    test('a box opens on its own, and the answer to a button inside is seen', async ({ page }) => {
        await page.goto('/test-layout');

        await line(page, 'Advanced options').click();
        await limit(page).fill('30');
        await page.getByRole('button', { name: 'Check' }).click();

        // The server never heard that the box was opened. The click inside told it.
        const answer = page.locator('details.sui-expander .sui-callout');
        await expect(answer).toHaveText('Checked with a limit of 30.');
        await expect(answer).toBeVisible();
        await expect(note(page)).toHaveText('Owner: nobody. Limit: 30.');
    });
});
