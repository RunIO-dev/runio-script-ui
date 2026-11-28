import { defineConfig, devices } from '@playwright/test';
import { APPS } from './support.mjs';

// The tests of this folder run against a real Laravel and a real Symfony. They are a suite of
// their own, because the two applications have to be downloaded first:
//
//     node frameworks/setup.mjs
//     npx playwright test -c frameworks
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
        colorScheme: 'light',
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
    },
    projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
    webServer: APPS.map((app) => ({
        // PHP holds output back, as most servers are set up to do. Sessions of PHP itself,
        // which Symfony uses, go to the system temp folder: the folder that php.ini names is
        // not writable on every machine. Without a login the tools answer "not allowed",
        // which is all the test runner waits for.
        command: `php -d output_buffering=4096 -d session.save_path= -S 127.0.0.1:${app.port} -t public`,
        cwd: app.folder,
        url: `${app.url}/admin/tools`,
        reuseExistingServer: false,
        timeout: 60_000,
    })),
});
