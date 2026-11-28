import { readFile } from 'node:fs/promises';
import { test, expect } from '@playwright/test';
import { watch } from '../../tests/support.mjs';
import { APPS } from '../support.mjs';

// The same tools inside a real Laravel and a real Symfony: frameworks/tools, served by one
// route of each application with respond(), the way the README shows it. The login, the
// session, the response and the handling of errors are the framework's own.
const said = (page) => page.locator('#sui-body > .sui-note');
const login = (page, app, name = 'ava') => page.goto(`${app.url}/login-as/${name}`);
const documents = (text) => (text.match(/<!doctype html/gi) ?? []).length;

for (const app of APPS) {
    const tools = `${app.url}/admin/tools`;

    test.describe(app.name, () => {
        test('the framework sends the list and the page of a tool', async ({ page, context }) => {
            watch(page);
            await login(page, app);
            await expect(page).toHaveURL(tools);
            await expect(page.locator('a.sui-card strong')).toHaveText([
                'Answer alone', 'Break down', 'Export a list', 'Import a file', 'Keep a note', 'Leave early', 'Payroll', 'Read what is not there',
            ]);

            const response = await page.goto(`${tools}/note`);
            expect(response.status()).toBe(200);
            // The headers of the page came through the framework: the rule for its script, and
            // that it is not kept. "private" is what the framework adds for a session.
            expect(response.headers()['content-security-policy']).toContain("default-src 'none'");
            expect(response.headers()['cache-control']).toContain('no-store');
            expect(response.headers()['cache-control']).toContain('private');
            // A variable from the application, and the user object of the framework by its name.
            await expect(said(page)).toHaveText(new RegExp(`^From ${app.name} \\d+\\.\\d+\\.\\d+\\.$`));
            await expect(page.locator('.sui-user')).toHaveText('ava');

            await page.getByLabel('Note').fill('call the bank');
            await page.getByRole('button', { name: 'Save' }).click();
            await expect(page.locator('.sui-success')).toHaveText('Saved "call the bank" for ava.');

            // One session: the framework's. The library started none of its own.
            const cookies = (await context.cookies()).map((cookie) => cookie.name);
            expect(cookies.filter((name) => /sess/i.test(name))).toHaveLength(1);
            expect(page.problems).toEqual([]);
        });

        test('what a tool keeps lies in the session of the framework, and ends with it', async ({ page }) => {
            await login(page, app);
            await page.goto(`${tools}/note`);
            await page.getByLabel('Note').fill('call the bank');
            await page.getByRole('button', { name: 'Save' }).click();
            await expect(page.locator('.sui-success')).toBeVisible();

            await page.goto(`${tools}/note`);
            await expect(page.getByLabel('Note')).toHaveValue('call the bank');

            // The framework ends the session at the logout. Nothing of the tool outlives that.
            const out = await page.goto(`${app.url}/logout`);
            expect(out.status()).toBe(403);
            await login(page, app, 'ben');
            await page.goto(`${tools}/note`);
            await expect(page.getByLabel('Note')).toHaveValue('');
            await expect(page.locator('.sui-user')).toHaveText('ben');
        });

        test('another user in the same session starts from nothing', async ({ page }) => {
            await login(page, app);
            await page.goto(`${tools}/note`);
            await page.getByLabel('Note').fill('the plan of ava');
            await page.getByRole('button', { name: 'Save' }).click();
            await expect(page.locator('.sui-success')).toBeVisible();

            // A login without a logout before it. The framework keeps what is in the
            // session: Symfony moves it to a session with a new id, and this login of
            // Laravel puts another name into it. The tools do not hand it on.
            await login(page, app, 'ben');
            await page.goto(`${tools}/note`);
            await expect(page.locator('.sui-user')).toHaveText('ben');
            await expect(page.getByLabel('Note')).toHaveValue('');
        });

        test('without a login of the framework nobody gets in', async ({ page }) => {
            for (const address of [tools, `${tools}/note`]) {
                expect((await page.goto(address)).status(), address).toBe(403);
                await expect(page.locator('h1')).toHaveText('Not allowed');
            }
        });

        test('a tool is closed to a user the framework does not allow', async ({ page }) => {
            await login(page, app, 'ben');
            await expect(page.locator('a.sui-card strong')).toHaveText([
                'Answer alone', 'Break down', 'Export a list', 'Import a file', 'Keep a note', 'Leave early', 'Read what is not there',
            ]);
            expect((await page.goto(`${tools}/payroll`)).status()).toBe(403);
            await expect(page.locator('.sui-note')).toHaveText('You may not open this tool.');

            await login(page, app, 'ava');
            expect((await page.goto(`${tools}/payroll`)).status()).toBe(200);
            await expect(said(page)).toHaveText('Read by ava.');
        });

        test('a file to download arrives as a file', async ({ page }) => {
            watch(page);
            await login(page, app);
            await page.goto(`${tools}/export`);

            const answered = page.waitForResponse((response) => 'POST' === response.request().method());
            const [download] = await Promise.all([page.waitForEvent('download'), page.getByRole('button', { name: 'Download as CSV' }).click()]);
            const response = await answered;

            expect(response.headers()['content-type']).toBe('text/csv; charset=utf-8');
            expect(response.headers()['content-disposition']).toContain('attachment');
            expect(response.headers()['content-security-policy']).toContain('sandbox');
            expect(download.suggestedFilename()).toBe('people.csv');
            expect((await readFile(await download.path(), 'utf8')).trimEnd().split('\r\n')).toEqual(['﻿name,city', 'Ava,Lyon', 'Ben,Graz']);
            expect(page.problems).toEqual([]);
        });

        test('a file can be uploaded', async ({ page }) => {
            watch(page);
            await login(page, app);
            await page.goto(`${tools}/import`);

            await page.getByLabel('File').setInputFiles({ name: 'people.csv', mimeType: 'text/csv', buffer: Buffer.from('name,city\nAva,Lyon\nBen,Graz\n') });
            await page.getByRole('button', { name: 'Read' }).click();
            await expect(page.locator('.sui-success')).toHaveText('Read people.csv: 2 rows.');
            expect(page.problems).toEqual([]);
        });

        test('a form that arrives without the headers of a browser of today is taken', async ({ page }) => {
            // Laravel lets a form in by its own token, or by a header that browsers send to a
            // safe address only. The forms of the tools carry the token of the library, so the
            // route is taken out of that check, as the README says. This request has neither.
            await login(page, app);
            await page.goto(`${tools}/note`);
            const token = await page.locator('input[name="_token"]').inputValue();

            const answer = await page.request.post(`${tools}/note`, { form: { _token: token, note: 'by hand', _click: 'save' } });

            expect(answer.status()).toBe(200);
            expect(await answer.text()).toContain('Saved &quot;by hand&quot; for ava.');

            // The token of the library is still asked for.
            const refused = await page.request.post(`${tools}/note`, { form: { _token: 'f'.repeat(64), note: 'forged', _click: 'save' } });
            expect(await refused.text()).not.toContain('forged');
        });

        test('a tool that ends PHP still shows its page, and what was typed is kept', async ({ page }) => {
            // Nothing of the framework runs after exit. In Laravel that includes giving back
            // the lock that ->block() holds on the session: the next request of this user
            // waits until the lock runs out, which is 10 seconds. So a tool ends a run with
            // stop() and not with exit, and this test is patient.
            const patient = { timeout: 20_000 };
            watch(page);
            await login(page, app);
            await page.goto(`${tools}/leave`);
            await expect(said(page)).toHaveText('The word is "".');

            await page.getByLabel('Word').fill('lantern');
            await page.getByLabel('Word').blur();
            await expect(said(page)).toHaveText('The word is "lantern".', patient);

            const again = await page.goto(`${tools}/leave`);
            await expect(page.getByLabel('Word')).toHaveValue('lantern');
            expect(documents(await again.text())).toBe(1);
            expect(page.problems).toEqual([]);
        });

        test('an answer that a tool prints by itself arrives as the tool made it', async ({ page }) => {
            await login(page, app);
            const response = await page.goto(`${tools}/own`);

            // PHP holds output back on this server. Printed straight to the browser, the answer
            // would get the type the framework puts on its own pages, and "<b>" would be bold.
            expect(response.status()).toBe(200);
            expect(response.headers()['content-type']).toBe('text/plain; charset=utf-8');
            expect(await response.text()).toBe('An answer of the tool itself: <b>not HTML</b>.');
        });

        test('after a fatal error the framework reports it, and the page of the tool stays out', async ({ page }) => {
            await login(page, app);
            const response = await page.goto(`${tools}/broken`);
            const body = await response.text();

            expect(response.status()).toBe(500);
            expect(documents(body)).toBe(1);
            expect(body).not.toContain('id="sui-form"');
        });

        test('a warning in a tool is an error, as everywhere in the framework', async ({ page }) => {
            await login(page, app);
            const response = await page.goto(`${tools}/warning`);

            // The page of the tool reports it: what was drawn before, and that it failed.
            // Details are not shown, because there is a login check and no debug: true.
            expect(response.status()).toBe(500);
            await expect(said(page)).toHaveText('Drawn before the warning.');
            await expect(page.locator('#sui-body > .sui-error')).toBeVisible();
            await expect(page.locator('#sui-body')).not.toContainText('missing');
        });
    });
}
