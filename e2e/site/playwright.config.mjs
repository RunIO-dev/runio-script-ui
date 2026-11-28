import { existsSync } from 'node:fs';
import { delimiter, join } from 'node:path';
import { defineConfig, devices } from '@playwright/test';
import { PHP_SETTINGS_DIR } from '../tests/support.mjs';
import { SAMPLES_PORT, SAMPLES_URL, SITE_DIR, SITE_PORT, SITE_URL } from './support.mjs';

// The tests of this folder use the live pictures of the website in a browser, and compare what
// they say with the real library. They are a suite of their own, because the website has to be
// built first:
//
//     (cd ../site && npm ci && npm run build)
//     npx playwright test -c site
if (!existsSync(join(SITE_DIR, '.vitepress/dist/index.html'))) {
    throw new Error('The website is not built yet. Run "npm ci && npm run build" in site/ first.');
}

// PHP reads the settings in php/ after its own, as it does for the other suites.
const PHP_INI_SCAN_DIR = `${process.env.PHP_INI_SCAN_DIR ?? ''}${delimiter}${PHP_SETTINGS_DIR}`;

export default defineConfig({
    testDir: './tests',
    fullyParallel: false,
    workers: 1,
    retries: 0,
    timeout: 60_000,
    expect: { timeout: 5_000 },
    forbidOnly: !!process.env.CI,
    reporter: [['list'], ['html', { open: 'never' }]],
    use: {
        baseURL: SITE_URL,
        colorScheme: 'light',
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
    },
    projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
    webServer: [
        {
            // The website as it was built, on this computer only.
            command: `npx vitepress preview --port ${SITE_PORT} --host 127.0.0.1`,
            cwd: SITE_DIR,
            url: SITE_URL,
            reuseExistingServer: false,
            timeout: 30_000,
        },
        {
            // The sample tools of the website, run by the library itself. The picture of one
            // click on the first page is compared with what hello.php really does.
            command: `php ../../bin/script-ui serve ../../site/samples --port=${SAMPLES_PORT}`,
            env: { PHP_INI_SCAN_DIR },
            url: `${SAMPLES_URL}/hello`,
            reuseExistingServer: false,
            timeout: 30_000,
        },
    ],
});
