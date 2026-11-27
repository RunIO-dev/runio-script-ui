import { readFile } from 'node:fs/promises';
import { test, expect } from '@playwright/test';
import { markDocument, rows, sameDocument, settled, watch } from './support.mjs';

// What a CSV file starts with, so that Excel reads it as UTF-8.
const MARK = '﻿';
const button = (page) => page.locator('#b-download-as-csv');

/** Presses a download button and gives back the file the browser saved. */
async function save(page, pressed) {
    const [download] = await Promise.all([page.waitForEvent('download'), pressed.click()]);

    return download;
}

/** The lines of a saved CSV file, without the empty one at its end. */
async function lines(download) {
    const text = await readFile(await download.path(), 'utf8');

    return text.trimEnd().split('\r\n');
}

test.beforeEach(({ page }) => {
    watch(page);
});

test.afterEach(({ page }) => {
    expect(page.problems, 'script errors, blocked content and server errors').toEqual([]);
});

test('the download button saves the rows as a CSV file and the page stays', async ({ page }) => {
    await page.goto('/customers');
    await markDocument(page);
    const shown = await rows(page).count();

    const download = await save(page, button(page));

    expect(download.suggestedFilename()).toBe('customers.csv');
    const saved = await lines(download);
    expect(saved[0]).toBe(`${MARK}id,name,email,country,joined,orders,refunds,spent`);
    expect(saved, 'one line per customer and one for the names of the columns').toHaveLength(shown + 1);

    await settled(page);
    expect(await sameDocument(page), 'no page load').toBe(true);
    await expect(button(page), 'the focus is back on the button').toBeFocused();
});

test('typing a filter and pressing download right away gives the file for that filter', async ({ page }) => {
    await page.goto('/customers');
    const all = await rows(page).count();

    await page.locator('#f-search').click();
    await page.keyboard.type('omar');
    const download = await save(page, button(page));

    const saved = (await lines(download)).slice(1);
    expect(saved.length).toBeGreaterThan(0);
    expect(saved.length).toBeLessThan(all);
    expect(saved.every((line) => /omar/i.test(line)), 'only what the filter leaves').toBe(true);

    // The page is brought up to date after the file, so both show the same.
    await expect(rows(page)).toHaveCount(saved.length);
    await expect(page.locator('#f-search')).toHaveValue('omar');
});

test('a double click gives one file', async ({ page }) => {
    await page.goto('/customers');
    const saved = [];
    page.on('download', (download) => saved.push(download));

    // Make the server look slow, so the second click lands while the file is being made.
    await page.route('**/customers', async (route) => {
        await new Promise((resolve) => setTimeout(resolve, 300));
        await route.continue();
    });
    await button(page).dblclick();

    await expect.poll(() => saved.length).toBe(1);
    await settled(page);
    expect(saved).toHaveLength(1);
});

test('a file name in Arabic arrives as it is', async ({ page }) => {
    await page.goto('/orders-ar');

    const download = await save(page, page.locator('#b-download'));

    expect(download.suggestedFilename()).toBe('الطلبات.csv');
    expect(await lines(download), 'every order, not only the page of the table').toHaveLength(81);
});

test('the log says who downloaded which file', async ({ page }) => {
    await page.goto('/customers');
    await save(page, button(page));

    await page.goto('/audit');

    const entry = rows(page).filter({ hasText: 'Download as CSV' }).first();
    await expect(entry).toContainText('customers');
    await expect(entry).toContainText('customers.csv');
});

test.describe('without JavaScript', () => {
    test.use({ javaScriptEnabled: false });

    test('the browser saves the file from the plain form', async ({ page }) => {
        await page.goto('/customers');

        const download = await save(page, button(page));

        expect(download.suggestedFilename()).toBe('customers.csv');
        expect((await lines(download))[0]).toBe(`${MARK}id,name,email,country,joined,orders,refunds,spent`);
        await expect(page.locator('h1'), 'the page is still there').toHaveText('Customers');
    });
});
