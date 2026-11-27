import { fileURLToPath } from 'node:url';

export const TOOLS_PORT = Number(process.env.E2E_TOOLS_PORT ?? 8775);
export const DIRECT_PORT = Number(process.env.E2E_DIRECT_PORT ?? 8776);
export const TOOLS_URL = `http://127.0.0.1:${TOOLS_PORT}`;
export const DIRECT_URL = `http://127.0.0.1:${DIRECT_PORT}`;

export const EXAMPLES_DIR = fileURLToPath(new URL('../../examples/', import.meta.url));
/** The copy of the example tools that the tests run against. See prepare-demo.mjs. */
export const DEMO_DIR = fileURLToPath(new URL('../.demo/', import.meta.url));

export const PHONE = { width: 390, height: 800 };

/**
 * Collects on `page.problems` what must never happen: script errors, messages on the error
 * console (the browser reports there what the Content-Security-Policy blocked) and server errors.
 */
export function watch(page) {
    page.problems = [];
    page.on('pageerror', (error) => page.problems.push(error.message));
    page.on('console', (message) => {
        if ('error' === message.type()) page.problems.push(message.text());
    });
    page.on('response', (response) => {
        if (response.status() >= 500) page.problems.push(`HTTP ${response.status()} ${response.url()}`);
    });

    return page;
}

/** Waits until the page has swapped in the answer to the request that is on its way. */
export const settled = (page) =>
    page.waitForFunction(() => !document.getElementById('sui-body').hasAttribute('aria-busy'));

/** Marks the document, so that a full page load can be told from an update in place. */
export const markDocument = (page) => page.evaluate(() => { window.__sameDocument = true; });
export const sameDocument = (page) => page.evaluate(() => true === window.__sameDocument);

/** The rows of the first table on the page. */
export const rows = (page) => page.locator('#sui-body table.sui-table').first().locator('tbody tr');

/** The text of one cell in every row of the first table. A negative index counts from the end. */
export const column = (page, index) =>
    rows(page).evaluateAll((trs, i) => trs.map((tr) => [...tr.children].at(i).textContent.trim()), index);

export const noHorizontalOverflow = (page) =>
    page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1);
