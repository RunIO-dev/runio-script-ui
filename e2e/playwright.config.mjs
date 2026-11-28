import { delimiter } from 'node:path';
import { defineConfig, devices } from '@playwright/test';
import { ACCESS_PORT, ACCESS_URL, DIRECT_PORT, DIRECT_URL, OWN_PORT, OWN_URL, PHP_SETTINGS_DIR, TOOLS_PORT, TOOLS_URL } from './tests/support.mjs';

// PHP reads the settings in php/ after its own. The empty entry in front stands for the
// folder it scans anyway, so nothing that is set up on the machine gets lost.
const PHP_INI_SCAN_DIR = `${process.env.PHP_INI_SCAN_DIR ?? ''}${delimiter}${PHP_SETTINGS_DIR}`;

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
            env: { PHP_INI_SCAN_DIR },
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
        {
            // A folder with a login check and a rule for who may open which tool. Without a
            // login it answers "not allowed", which is all the test runner waits for.
            command: `php ../bin/script-ui serve access --port=${ACCESS_PORT}`,
            url: ACCESS_URL,
            reuseExistingServer: false,
            timeout: 30_000,
        },
        {
            // Scripts that the web server runs by themselves, with the same two checks in a
            // file that each of them includes. Without a login they answer "not allowed" too.
            command: `php -d session.save_path= -S 127.0.0.1:${OWN_PORT} -t direct`,
            url: `${OWN_URL}/open.php`,
            reuseExistingServer: false,
            timeout: 30_000,
        },
    ],
});
