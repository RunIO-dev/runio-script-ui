// What a page sends to the server, for the tests of the parts that open and close.
//
// This is not in support.mjs: that file is also read by the script that copies the demo
// tools, and that script has no use for the test library.
import { expect } from '@playwright/test';

/**
 * What the page posts from now on: the runs of the tool, and the reports of the page script
 * about how its parts stand (which box is open, which tab is in view). A report runs no tool.
 */
export function posts(page) {
    const seen = { runs: [], reports: [] };
    page.on('request', (request) => {
        if ('POST' !== request.method()) return;
        ('layout' === request.headers()['x-script-ui'] ? seen.reports : seen.runs).push(request);
    });

    return seen;
}

/** Waits until the server has answered the report with this number. */
export async function reported(sent, count) {
    await expect.poll(() => sent.reports.length, 'the server is told how the parts stand').toBe(count);
    const response = await sent.reports[count - 1].response();
    expect(response.status()).toBe(204);
}
