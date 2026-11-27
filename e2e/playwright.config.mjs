import { defineConfig, devices } from '@playwright/test';
import { DIRECT_PORT, DIRECT_URL, TOOLS_PORT, TOOLS_URL } from './tests/support.mjs';

// The specs share one set of demo data and change it, so they run one at a time. They count on
// a fresh server for every run: the refund flow changes the data that the other tests read.
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
        baseURL: TOOLS_URL,
        // The same look on every machine, whatever theme the system uses.
        colorScheme: 'light',
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
    },
    projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
    // Both servers are started for the run and stopped after it. They use their own ports,
    // so a demo that is already running on 8765 is left alone.
    webServer: [
        {
            // A folder of tools, served the way the README shows. prepare-demo.mjs makes
            // a fresh copy of the example tools first, so every run starts with the same data.
            command: `node tests/prepare-demo.mjs && php ../bin/script-ui serve .demo/tools --port=${TOOLS_PORT}`,
            url: TOOLS_URL,
            reuseExistingServer: false,
            timeout: 30_000,
        },
        {
            // One script on its own, run directly by the web server. Sessions go to the system
            // temp folder: the folder that php.ini names is not writable on every machine.
            command: `php -d session.save_path= -S 127.0.0.1:${DIRECT_PORT} -t ../examples/direct`,
            url: `${DIRECT_URL}/hello.php`,
            reuseExistingServer: false,
            timeout: 30_000,
        },
    ],
});
