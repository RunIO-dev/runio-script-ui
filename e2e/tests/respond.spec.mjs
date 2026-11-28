import { readFile } from 'node:fs/promises';
import { test, expect } from '@playwright/test';
import { RESPOND_URL, watch } from './support.mjs';

// The folder of these tests is e2e/respond: a stand-in for an application that is built on a
// framework. Its script calls respond(), which hands the page back, and the application sends
// it. The session is the application's own, kept as JSON, and so is the login.
const login = (page, name = 'ava') => page.goto(`${RESPOND_URL}/login?as=${name}`);
const said = (page) => page.locator('#sui-body > .sui-note');
const field = (page, label) => page.getByLabel(label);

test('the application sends the page that respond() hands back', async ({ page, context }) => {
    watch(page);
    await login(page);
    await expect(page.locator('a.sui-card strong')).toHaveText([
        'Answer alone', 'Answer alone and leave', 'Break down', 'Export a list', 'Keep a note', 'Leave early',
    ]);

    const response = await page.goto(`${RESPOND_URL}/tools/note`);
    expect(response.status()).toBe(200);
    // Only the application adds this header. Had the library sent the page, it would be missing.
    expect(response.headers()['x-app']).toBe('stand-in');
    // The headers of the page came along: the rule for its script, and that it is not kept.
    expect(response.headers()['content-security-policy']).toContain("default-src 'none'");
    expect(response.headers()['cache-control']).toContain('no-store');
    // The application finds its output buffers as it left them.
    expect(response.headers()['x-buffers']).toBe('1 before, 1 after');
    // The tool got a variable from the application.
    await expect(said(page)).toHaveText('From the application.');

    await field(page, 'Note').fill('call the bank');
    await page.getByRole('button', { name: 'Save' }).click();
    await expect(page.locator('.sui-success')).toHaveText('Saved "call the bank" for ava.');

    // The link back leads to the list at the base as it was written, without a slash.
    await expect(page.locator('a.sui-home')).toHaveAttribute('href', '/tools');
    // PHP's own session was never started: the only cookie is the one of the application.
    expect((await context.cookies()).map((cookie) => cookie.name)).toEqual(['app_session']);
    expect(page.problems).toEqual([]);
});

test('what a tool keeps lies in the session of the application', async ({ page }) => {
    await login(page);
    await page.goto(`${RESPOND_URL}/tools/note`);
    await field(page, 'Note').fill('call the bank');
    await page.getByRole('button', { name: 'Save' }).click();
    await expect(page.locator('.sui-success')).toBeVisible();

    await page.goto(`${RESPOND_URL}/tools/note`);
    await expect(field(page, 'Note')).toHaveValue('call the bank');

    // The application ends its session. Nothing of the tool outlives that.
    await page.goto(`${RESPOND_URL}/logout`);
    await expect(page.locator('.sui-note')).toHaveText('You do not have access to this page.');
    await login(page, 'ben');
    await page.goto(`${RESPOND_URL}/tools/note`);
    await expect(field(page, 'Note')).toHaveValue('');
});

test('another user in the same session starts from nothing', async ({ page }) => {
    await login(page);
    await page.goto(`${RESPOND_URL}/tools/note`);
    await field(page, 'Note').fill('the plan of ava');
    await page.getByRole('button', { name: 'Save' }).click();
    await expect(page.locator('.sui-success')).toBeVisible();

    // A login without a logout before it: the application keeps its session and puts
    // another name into it. What the tools kept for the first user is not handed on.
    await login(page, 'ben');
    await page.goto(`${RESPOND_URL}/tools/note`);
    await expect(page.locator('.sui-user')).toHaveText('ben');
    await expect(field(page, 'Note')).toHaveValue('');
});

test('without a login of the application nobody gets in', async ({ page }) => {
    for (const path of ['/tools', '/tools/note']) {
        const response = await page.goto(RESPOND_URL + path);

        expect(response.status(), path).toBe(403);
        expect(response.headers()['x-app'], path).toBe('stand-in');
    }
});

test('a file to download passes through the application', async ({ page }) => {
    watch(page);
    await login(page);
    await page.goto(`${RESPOND_URL}/tools/export`);

    const answered = page.waitForResponse((response) => 'POST' === response.request().method());
    const [download] = await Promise.all([page.waitForEvent('download'), page.getByRole('button', { name: 'Download as CSV' }).click()]);
    const response = await answered;

    // The application sent it, with the headers that make a file of it and not a page.
    expect(response.headers()['x-app']).toBe('stand-in');
    expect(response.headers()['content-type']).toBe('text/csv; charset=utf-8');
    expect(response.headers()['content-disposition']).toContain('attachment');
    expect(response.headers()['content-security-policy']).toContain('sandbox');
    expect(download.suggestedFilename()).toBe('people.csv');
    expect((await readFile(await download.path(), 'utf8')).trimEnd().split('\r\n')).toEqual(['﻿name,city', 'Ava,Lyon', 'Ben,Graz']);
    expect(page.problems).toEqual([]);
});

test('a tool that ends PHP still shows its page, and what was typed is kept', async ({ page }) => {
    watch(page);
    await login(page);
    const response = await page.goto(`${RESPOND_URL}/tools/leave`);
    await expect(said(page)).toHaveText('The word is "".');
    // The application never got to send this page: the library did, when the tool left.
    expect(response.headers()['x-app']).toBeUndefined();
    expect(response.headers()['content-security-policy']).toContain("default-src 'none'");

    // Nor does the application get to save its session. It closes the session as the first
    // thing when PHP shuts down, so the library has it saved before that. Otherwise the
    // word would be gone, and the form would be told that it has expired.
    await field(page, 'Word').fill('lantern');
    await field(page, 'Word').blur();
    await expect(said(page)).toHaveText('The word is "lantern".');

    await page.goto(`${RESPOND_URL}/tools/leave`);
    await expect(field(page, 'Word')).toHaveValue('lantern');
    expect(page.problems).toEqual([]);
});

test('an answer that a tool prints by itself is handed back like a page', async ({ page, context }) => {
    await login(page);
    const response = await page.goto(`${RESPOND_URL}/tools/own`);

    // The application sent it. It puts a type on every page it sends, as a framework does,
    // and PHP holds output back on this server: printed straight to the browser, the
    // text of the tool would have arrived as HTML.
    expect(response.headers()['x-app']).toBe('stand-in');
    expect(response.status()).toBe(203);
    expect(response.headers()['content-type']).toBe('text/plain; charset=utf-8');
    // Each header once: the application adds its headers to those PHP holds already.
    expect(response.headers()['x-tool']).toBe('own');
    // Only what the tool set was taken. What the application had set before is as it was,
    // also under a name that the tool added a line to.
    const lines = async (name) => (await response.headersArray())
        .filter((header) => name === header.name.toLowerCase()).map((header) => header.value);
    expect(await lines('x-app-early')).toEqual(['one', 'two', 'three']);
    // A header that the tool replaced stays replaced.
    expect(await lines('x-app-mode')).toEqual(['tool']);
    // And one that the tool did not touch is there once, as before.
    expect(await lines('x-app-kept')).toEqual(['once']);
    // A response holds one value for a name: two lines of the tool come as one list.
    expect(await lines('x-tool-list')).toEqual(['a, b']);
    expect(response.headers()['x-buffers']).toBe('1 before, 1 after');
    expect(await response.text()).toBe('An answer of the tool itself.');
    // Cookies stay with PHP, which sends every one of them.
    expect((await context.cookies()).map((cookie) => cookie.name).sort()).toEqual(['app_session', 'first', 'second']);
});

test('an answer that a tool prints before it ends PHP arrives as the tool made it', async ({ page }) => {
    await login(page);
    const response = await page.goto(`${RESPOND_URL}/tools/own-then-leave`);

    expect(response.status()).toBe(200);
    expect(response.headers()['content-type']).toBe('text/plain; charset=utf-8');
    expect(await response.text()).toBe('An answer, and then the end of PHP.');
});

test('after a fatal error the application reports it, and the page of the tool stays out', async ({ page }) => {
    await login(page);
    const response = await page.goto(`${RESPOND_URL}/tools/broken`);

    expect(response.status()).toBe(500);
    expect(response.headers()['x-app']).toBe('stand-in');
    // One document: the error page of the application, and nothing of the tool behind it.
    expect(await response.text()).toBe(
        '<!doctype html><html lang="en"><title>Error</title><h1 id="app-error">The application broke down.</h1></html>',
    );
});
