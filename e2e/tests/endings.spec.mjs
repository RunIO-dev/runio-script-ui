import { test, expect } from '@playwright/test';
import { OWN_URL, TOOLS_URL, WEB_ROOT_URL, watch } from './support.mjs';

// A run that never reaches its last line: the tool ends PHP with exit, or PHP breaks down with
// a fatal error that nothing can catch. Both are tried the two ways a tool can run: served
// from a folder (e2e/tools/test-leave.php and test-broken.php), and as a script that the web
// server runs by itself (e2e/direct/leave.php and broken.php).
const said = (page) => page.locator('#sui-body > .sui-note');
const documents = async (page, url) => ((await (await page.request.get(url)).text()).match(/<!doctype html>/gi) ?? []).length;

const WAYS = [
    { way: 'a served tool', leave: `${TOOLS_URL}/test-leave`, broken: `${TOOLS_URL}/test-broken` },
    { way: 'a script on its own', leave: `${OWN_URL}/leave.php`, broken: `${OWN_URL}/broken.php` },
];

for (const { way, leave, broken } of WAYS) {
    test(`${way} that ends PHP shows its page once, and what was typed is kept`, async ({ page }) => {
        watch(page);
        await page.goto(leave);
        await expect(said(page)).toHaveText('The word is "".');

        await page.getByLabel('Word').fill('lantern');
        await page.getByLabel('Word').blur();
        await expect(said(page)).toHaveText('The word is "lantern".');

        await page.goto(leave);
        await expect(page.getByLabel('Word')).toHaveValue('lantern');
        // One page, and not the same one twice.
        expect(await documents(page, leave)).toBe(1);
        expect(page.problems).toEqual([]);
    });

    test(`${way} that breaks down shows what it drew, and the error`, async ({ page }) => {
        const response = await page.goto(broken);

        expect(response.status()).toBe(500);
        await expect(said(page)).toHaveText('Drawn before the end.');
        // Without a login check, on this computer, the error is shown with its details.
        // PHP 8.5 calls it "Cannot redeclare function …". Before, it left the word out.
        await expect(page.locator('#sui-body > .sui-error')).toContainText(/Cannot redeclare (function )?declared_twice_by_a_test\(\)/);
        expect(await documents(page, broken)).toBe(1);
    });
}

test('a fatal error is reported also when the application raised a warning after it', async ({ page }) => {
    // The script of this server adds a step to the shutdown of PHP that raises a warning,
    // before serve() adds its own. Asked why it ended, PHP then names the warning.
    const broken = `${WEB_ROOT_URL}/?_tool=broken&warn-at-the-end=1`;
    const response = await page.goto(broken);

    // Not a good page with half a tool on it: the run failed, and the page says so.
    expect(response.status()).toBe(500);
    await expect(said(page)).toHaveText('Drawn before the end.');
    await expect(page.locator('#sui-body > .sui-error')).toContainText('PHP ended while the tool ran.');
    expect(await documents(page, broken)).toBe(1);
});
