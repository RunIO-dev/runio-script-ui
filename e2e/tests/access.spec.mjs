import { test, expect } from '@playwright/test';
import { ACCESS_URL, OWN_URL, WEB_ROOT_URL } from './support.mjs';

// The folder of these tests is e2e/access, served the way the README shows, with settings in
// _config.php: a login check that reads a cookie, and a rule for who may open which tool.
// Ava may open the refunds and the report, Ben only the report, and nobody the payroll.
const as = (context, name) => context.addCookies([{ name: 'as', value: name, url: ACCESS_URL }]);
const cards = (page) => page.locator('a.sui-card strong');
const heading = (page) => page.getByRole('heading', { level: 1 });
const said = (page) => page.locator('.sui-note');

test('the list shows what this user may open, and a tool on it works as always', async ({ page, context }) => {
    await as(context, 'ava');
    await page.goto(ACCESS_URL);

    await expect(cards(page)).toHaveText(['Refund orders', 'Sales report']);
    await expect(page.locator('body'), 'nothing about the rest').not.toContainText('Payroll');

    await page.getByRole('link', { name: 'Refund orders' }).click();
    await page.getByLabel('Order').fill('1001');
    await page.getByRole('button', { name: 'Refund' }).click();
    // The desk is a variable that the settings give to every tool of the folder.
    await expect(page.locator('.sui-success')).toHaveText('Refunded 1001 for ava at the returns desk.');
});

test('another user gets another list', async ({ page, context }) => {
    await as(context, 'ben');
    await page.goto(ACCESS_URL);

    await expect(cards(page)).toHaveText(['Sales report']);

    await page.getByRole('link', { name: 'Sales report' }).click();
    await expect(said(page)).toHaveText('Read by ben.');
});

test('a tool that is not allowed says so, and leads back to the tools that are', async ({ page, context }) => {
    await as(context, 'ben');
    const response = await page.goto(`${ACCESS_URL}/refund`);

    expect(response.status()).toBe(403);
    await expect(heading(page)).toHaveText('Not allowed');
    await expect(said(page)).toHaveText('You may not open this tool.');
    await expect(page.locator('body'), 'the page says nothing about the tool').not.toContainText('Refund');
    await expect(page.locator('form')).toHaveCount(0);

    await page.getByRole('link', { name: 'All tools' }).click();
    await expect(cards(page)).toHaveText(['Sales report']);
});

test('a user who may open nothing is told so', async ({ page, context }) => {
    await as(context, 'cy');
    await page.goto(ACCESS_URL);

    await expect(cards(page)).toHaveCount(0);
    await expect(page.locator('.sui-callout')).toHaveText('There is no tool here that you may open.');
});

test('without a login nobody gets in, and nobody learns which tools there are', async ({ page }) => {
    for (const path of ['/', '/refund', '/payroll', '/missing']) {
        const response = await page.goto(ACCESS_URL + path);

        expect(response.status(), path).toBe(403);
        await expect(said(page), path).toHaveText('You do not have access to this page.');
    }
});

test('a tool that is not there is not found, for a user who is logged in', async ({ page, context }) => {
    await as(context, 'ava');
    const response = await page.goto(`${ACCESS_URL}/missing`);

    expect(response.status()).toBe(404);
    await expect(said(page)).toHaveText('There is no tool at this address.');
});

test('access that is taken away ends with the next request, also on a page that is open', async ({ page, context }) => {
    await as(context, 'ava');
    await page.goto(`${ACCESS_URL}/refund`);
    await page.getByLabel('Order').fill('1001');

    // Somebody closes the tool while Ava has it open.
    await context.addCookies([{ name: 'closed', value: 'refund', url: ACCESS_URL }]);
    await page.getByRole('button', { name: 'Refund' }).click();

    // The answer is not the tool's. The page script loads the page, and the page says why.
    await expect(heading(page)).toHaveText('Not allowed');
    await expect(said(page)).toHaveText('You may not open this tool.');
    await expect(page.locator('.sui-success'), 'nothing was refunded').toHaveCount(0);

    // The list no longer offers it either.
    await page.getByRole('link', { name: 'All tools' }).click();
    await expect(cards(page)).toHaveText(['Sales report']);
});

test('a page that runs again by itself says so too, with nobody at it', async ({ page, context }) => {
    await as(context, 'ava');
    // The clock of the page stands still: time passes when the test says so.
    await page.clock.install({ time: new Date('2026-03-02T08:59:50Z') });
    await page.clock.pauseAt(new Date('2026-03-02T09:00:00Z'));
    await page.goto(`${ACCESS_URL}/report`);
    await expect(said(page)).toHaveText('Read by ava.');

    // The report stays open on a screen, and somebody closes the tool.
    await context.addCookies([{ name: 'closed', value: 'report', url: ACCESS_URL }]);
    await page.clock.runFor(4_900);
    await expect(said(page), 'not before its time').toHaveText('Read by ava.');
    await page.clock.runFor(100);

    await expect(heading(page)).toHaveText('Not allowed');
    await expect(said(page)).toHaveText('You may not open this tool.');
});

// The folder of these tests is e2e/direct. No serve() and no list: the web server runs each
// script by its name, and the settings come from a file that every script includes.
test.describe('a script on its own', () => {
    const own = (context, name) => context.addCookies([{ name: 'as', value: name, url: OWN_URL }]);

    test('is asked about by the name of its file, as a tool of a folder is', async ({ page, context }) => {
        await own(context, 'ava');
        await page.goto(`${OWN_URL}/my_report.php`);
        await expect(said(page)).toHaveText('Read by ava.');

        // The rule names "my_report". The page of the script goes by "my-report", and a
        // rule that was asked about that name would let Ben in.
        await own(context, 'ben');
        const response = await page.goto(`${OWN_URL}/my_report.php`);
        expect(response.status()).toBe(403);
        await expect(heading(page)).toHaveText('Not allowed');
        await expect(said(page)).toHaveText('You may not open this tool.');
        await expect(page.getByRole('link', { name: 'All tools' }), 'there is no list to go back to').toHaveCount(0);

        // More of an address behind the file name changes nothing about which file it is.
        expect((await page.goto(`${OWN_URL}/my_report.php/open`)).status()).toBe(403);

        await page.goto(`${OWN_URL}/open.php`);
        await expect(said(page)).toHaveText('Read by ben.');
    });

    test('lets nobody in without a login', async ({ page }) => {
        const response = await page.goto(`${OWN_URL}/open.php`);

        expect(response.status()).toBe(403);
        await expect(said(page)).toHaveText('You do not have access to this page.');
    });
});

// A web server runs a file of its web root by its name, and serve() has no say then. The
// library cannot stop that. It tells whoever sets the tools up, on the list, where error
// details are shown: here that is a browser on the same computer, with no login check.
test.describe('a folder of tools in the web root', () => {
    const note = (page) => page.locator('.sui-warning');

    test('is pointed out on the list', async ({ page }) => {
        await page.goto(WEB_ROOT_URL);

        await expect(cards(page)).toHaveText(['Say hello']);
        await expect(note(page)).toContainText('lies in the web root');
        await expect(note(page).locator('code')).toHaveText(/[\\/]e2e[\\/]webroot[\\/]tools$/);

        // On the page of a tool there is nothing to say again.
        await page.getByRole('link', { name: 'Say hello' }).click();
        await expect(heading(page)).toHaveText('Say hello');
        await expect(note(page)).toHaveCount(0);
    });

    test('is not claimed of the server that comes with the library', async ({ page, context }) => {
        // "script-ui serve" answers every address through one script, so no file is run by
        // its name there, wherever the folder lies. The folder of these tools does lie in
        // the folder that the server was started in.
        await as(context, 'ava');
        await page.goto(ACCESS_URL);
        await expect(cards(page)).toHaveText(['Refund orders', 'Sales report']);
        await expect(note(page)).toHaveCount(0);

        await page.goto('/');
        await expect(page.locator('a.sui-card').first()).toBeVisible();
        await expect(note(page)).toHaveCount(0);
    });
});

test.describe('without JavaScript', () => {
    test.use({ javaScriptEnabled: false });

    test('the rule holds for a plain form as well', async ({ page, context }) => {
        await as(context, 'ava');
        await page.goto(`${ACCESS_URL}/refund`);
        await page.getByLabel('Order').fill('1002');
        await page.getByRole('button', { name: 'Refund' }).click();
        await expect(page.locator('.sui-success')).toHaveText('Refunded 1002 for ava at the returns desk.');

        await context.addCookies([{ name: 'closed', value: 'refund', url: ACCESS_URL }]);
        await page.getByRole('button', { name: 'Refund' }).click();
        await expect(heading(page)).toHaveText('Not allowed');
        await expect(page.locator('.sui-success')).toHaveCount(0);
    });
});
