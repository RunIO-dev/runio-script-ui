// Takes the pictures of the website: the demo tools and the samples of the tutorials, as a
// browser shows them.
//
//   node site-shots.mjs        (from e2e/, while no other Playwright run is going)
//
// It makes a fresh copy of the demo and of the samples, starts two servers of its own on the
// ports 8791 and 8792, writes PNG files to ../site/public/shots/ and stops the servers again.
// Run it when the look of the pages changed, and before a release. Every picture is a real page:
// when a step no longer works, the script fails and says which picture it was.
import { spawn } from 'node:child_process';
import { cpSync, mkdirSync, mkdtempSync, rmSync, statSync } from 'node:fs';
import { createServer } from 'node:net';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from '@playwright/test';
import { watch } from './tests/support.mjs';

// Waits until a request that is on its way has been answered. The list of tools has no such part and never waits.
const settled = (page) => page.waitForFunction(() => !document.getElementById('sui-body')?.hasAttribute('aria-busy'));

const here = (path) => fileURLToPath(new URL(path, import.meta.url));
const OUT = here('../site/public/shots/');
const DEMO = 'http://127.0.0.1:8791';
const SAMPLES = 'http://127.0.0.1:8792';
const WIDTH = 900;
const TALLEST = 1000;   // a longer page is cut here: the top of it says what the tool is

const ORDERS = 'email,items,total\nava@example.com,Notebook,24.50\nomar@example.com,Monitor arm,89\n';

const copy = mkdtempSync(join(tmpdir(), 'script-ui-shots-'));

/** Each picture: its name, the address, and what a user does before the picture is taken. */
const pictures = [
    ['hello-start', SAMPLES + '/hello', async () => {}],
    ['hello', SAMPLES + '/hello', async (page) => {
        await page.getByLabel('Your name').fill('Ava');
        await page.getByRole('button', { name: 'Greet' }).click();
        await page.getByText('Hello, Ava.').waitFor();
    }],
    ['list', SAMPLES + '/', async () => {}],
    // The reader of the first tutorial writes the second tool after they saw the list with one.
    ['orders', () => { cpSync(here('../site/samples/orders.php'), join(copy, 'samples/orders.php')); return SAMPLES + '/orders'; }, async (page) => {
        await page.getByLabel('Customer').fill('ha');
        await page.keyboard.press('Enter');
        await settled(page);
        await page.getByText('2 of 6 orders.').waitFor();
    }],
    ['refund', DEMO + '/refund', async (page) => {
        await page.getByLabel('Customer email').fill('ava@example.com');
        await page.keyboard.press('Enter');
        await page.locator('[id="f-table:0"]').check();
        await settled(page);
        await page.locator('[id="f-table:1"]').check();
        await settled(page);
        await page.getByRole('button', { name: 'Refund selected' }).click();
        await page.locator('[data-confirm]').waitFor();
    }],
    ['refund-done', null, async (page) => {
        await page.locator('[data-confirm]').getByRole('button', { name: 'Yes' }).click();
        await page.getByText('Refunded 2 orders.').waitFor();
    }],
    ['coupon', DEMO + '/coupon', async (page) => {
        await page.getByLabel('Discount in percent').fill('30');
        await page.getByRole('button', { name: 'Give coupon' }).click();
        await page.getByText('Enter the email of a customer.').waitFor();
    }],
    ['customers', DEMO + '/customers', async (page) => {
        await page.getByLabel('Search').fill('omar');
        await page.keyboard.press('Enter');
        await settled(page);
        await page.locator('[id="f-table:0"]').check();
        await page.getByRole('tab').first().waitFor();
    }],
    ['dashboard', DEMO + '/dashboard', async () => {}],
    ['import', DEMO + '/import', async (page) => {
        await page.locator('input[type=file]').setInputFiles({ name: 'orders.csv', mimeType: 'text/csv', buffer: Buffer.from(ORDERS) });
        await page.getByRole('cell', { name: 'Monitor arm' }).waitFor();
    }],
    ['archive', DEMO + '/archive', async (page) => {
        await page.getByRole('searchbox').fill('haddad');
        await page.keyboard.press('Enter');
        await settled(page);
    }],
    ['ship', DEMO + '/ship', async () => {}],
    ['orders-ar', DEMO + '/orders-ar', async () => {}],
    ['export', DEMO + '/export', async (page) => {
        await page.getByRole('button', { name: 'Start the export' }).click();
        // The clock of the demo stands in for a worker: a third of the way takes about ten seconds.
        await page.waitForFunction(() => (document.querySelector('.sui-progress progress')?.position ?? 0) >= 0.33, null, { timeout: 40_000 });
    }],
    ['audit', DEMO + '/audit', async () => {}],
    ['demo-list', DEMO + '/', async () => {}],
];

cpSync(here('../examples/tools'), join(copy, 'tools'), { recursive: true });
mkdirSync(join(copy, 'samples'));
cpSync(here('../site/samples/hello.php'), join(copy, 'samples/hello.php'));
mkdirSync(OUT, { recursive: true });

// "script-ui serve" starts PHP's own server as a child. Each one gets a process group of its own,
// so that the group can be stopped as a whole at the end.
const serve = (folder, port) => spawn('php', [here('../bin/script-ui'), 'serve', folder, '--port=' + port], { stdio: 'ignore', detached: true });
let servers = [];

// Whatever else answers on one of the two ports would be photographed in place of the tools.
const free = (port) => new Promise((resolve, reject) => {
    const probe = createServer();
    probe.once('error', () => reject(new Error(`The port ${port} is taken. Stop what runs there first.`)));
    probe.listen(port, '127.0.0.1', () => probe.close(resolve));
});

function tidy() {
    for (const server of servers) {
        try {
            process.kill(-server.pid, 'SIGTERM');
        } catch {
            // It has ended by itself.
        }
    }
    rmSync(copy, { recursive: true, force: true });
}

// Ctrl-C ends the script before its "finally" runs. The servers and the copy would stay behind.
for (const signal of ['SIGINT', 'SIGTERM']) {
    process.on(signal, () => {
        tidy();
        process.exit(130);
    });
}

async function answers(url) {
    for (let tries = 0; tries < 60; tries++) {
        try {
            if ((await fetch(url)).ok) return;
        } catch {
            // Not up yet.
        }
        await new Promise((resolve) => setTimeout(resolve, 250));
    }
    throw new Error(`No answer from ${url}.`);
}

let browser;
let failed = false;
try {
    await Promise.all([free(8791), free(8792)]);
    servers = [serve(join(copy, 'tools'), 8791), serve(join(copy, 'samples'), 8792)];
    await Promise.all([answers(DEMO), answers(SAMPLES)]);
    browser = await chromium.launch();
    const context = await browser.newContext({ viewport: { width: WIDTH, height: 700 }, deviceScaleFactor: 1.5, colorScheme: 'light', locale: 'en-US' });
    const page = watch(await context.newPage());

    for (const [name, url, steps] of pictures) {
        try {
            if (url !== null) {
                await page.goto(typeof url === 'function' ? url() : url);
            }
            await steps(page);
            await settled(page);
            // The mouse rests where nothing reacts to it.
            await page.mouse.move(0, 0);
            // As tall as what is on the page, with some air under it: a short page is a short picture.
            const bottom = await page.evaluate(() => Math.max(0, ...[...document.querySelectorAll('body *')].map((element) => element.getBoundingClientRect().bottom + window.scrollY)));
            const height = Math.min(TALLEST, Math.ceil(bottom) + 28);
            await page.screenshot({ path: join(OUT, name + '.png'), fullPage: true, clip: { x: 0, y: 0, width: WIDTH, height } });
            process.stdout.write(`${name}.png  ${Math.round(statSync(join(OUT, name + '.png')).size / 1024)} KB\n`);
        } catch (error) {
            failed = true;
            process.stderr.write(`${name}: ${error.message.split('\n')[0]}\n`);
        }
    }
    if (page.problems.length > 0) {
        failed = true;
        process.stderr.write('The pages reported problems:\n  ' + page.problems.join('\n  ') + '\n');
    }
} catch (error) {
    failed = true;
    process.stderr.write(error.message.split('\n')[0] + '\n');
} finally {
    await browser?.close();
    tidy();
}
process.exit(failed ? 1 : 0);
