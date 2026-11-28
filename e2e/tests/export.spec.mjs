import { mkdir, readFile, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { test, expect } from '@playwright/test';
import { DEMO_DIR, markDocument, sameDocument, watch } from './support.mjs';

// The tool of the demo that takes long: examples/tools/export.php. Its "worker" is the clock:
// three seconds of waiting, then 2,400 orders in 24 seconds. So this test runs on the real clock.
const beside = (page) => page.locator('#sui-body .sui-progress-text');
const line = (page) => page.locator('#sui-body .sui-refresh');

test.beforeEach(({ page }) => {
    watch(page);
});

test.afterEach(({ page }) => {
    expect(page.problems, 'script errors, blocked content and server errors').toEqual([]);
});

test('the demo exports orders: it waits for a worker, shows how far it is, and can be stopped', async ({ page }) => {
    await page.goto('/export');
    const stop = page.getByRole('button', { name: 'Stop the export' });
    if (await stop.isVisible()) {
        // An export that an earlier run left behind.
        await stop.click();
        await page.getByRole('button', { name: 'Yes' }).click();
    }
    await markDocument(page);
    await page.getByRole('button', { name: /^Start (the|a new) export$/ }).click();

    // First nobody knows how far it is. Then the worker has it, and the words beside the bar count the orders.
    await expect(page.getByLabel('Waiting for a worker')).toHaveJSProperty('position', -1);
    const exporting = page.getByLabel('Exporting orders');
    await expect(exporting).toBeVisible({ timeout: 15_000 });
    await expect(beside(page)).toHaveText(/^[\d,]+ of 2,400 orders$/);
    const early = await exporting.evaluate((element) => element.value);
    await expect.poll(() => exporting.evaluate((element) => element.value), { timeout: 15_000 }).toBeGreaterThan(early);
    expect(await sameDocument(page), 'the bar moved without a page load').toBe(true);

    await stop.click();
    await page.getByRole('button', { name: 'Yes' }).click();
    await expect(page.locator('#sui-body .sui-warning')).toHaveText(/^The export was stopped at [\d,]+ of 2,400 orders\.$/);
    await expect(page.locator('#sui-body progress')).toHaveCount(0);
    await expect(line(page)).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Start a new export' })).toBeVisible();
});

test('an export that is finished stands still and hands out its file', async ({ page }) => {
    // The export of the demo is as far as the time since its start says. This one started an hour ago.
    await mkdir(path.join(DEMO_DIR, 'var'), { recursive: true });
    await writeFile(path.join(DEMO_DIR, 'var', 'export.json'), JSON.stringify({ started: Math.floor(Date.now() / 1000) - 3600 }));

    await page.goto('/export');
    await expect(page.locator('#sui-body .sui-success')).toHaveText('2,400 orders are exported.');
    await expect(page.locator('#sui-body progress')).toHaveCount(0);
    await expect(line(page)).toHaveCount(0);

    const [download] = await Promise.all([
        page.waitForEvent('download'),
        page.getByRole('button', { name: 'Download orders.csv' }).click(),
    ]);
    expect(download.suggestedFilename()).toBe('orders.csv');
    const lines = (await readFile(await download.path(), 'utf8')).replace(/^\uFEFF/, '').trimEnd().split(/\r?\n/);
    expect(lines.length, 'the names of the columns and 2,400 orders').toBe(2401);
    expect(lines[0]).toBe('id,customer,total');
    expect(lines[1]).toBe('700001,"Omar Haddad",84.19');
    expect(lines[2400]).toMatch(/^702400,"Ava Morgan",/);

    await expect(page.getByRole('button', { name: 'Start a new export' })).toBeVisible();
});
