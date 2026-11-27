import { test, expect } from '@playwright/test';
import { DIRECT_URL, PHONE, column, markDocument, noHorizontalOverflow, rows, sameDocument, settled, watch } from './support.mjs';

// Every test here opens its own browser context, so it starts with nothing saved.
test.beforeEach(({ page }) => {
    watch(page);
});

test.afterEach(({ page }) => {
    expect(page.problems, 'script errors, blocked content and server errors').toEqual([]);
});

test('the index page lists the tools', async ({ page }) => {
    await page.goto('/');

    await expect(page.locator('a.sui-card').first()).toBeVisible();
    const names = await page.locator('a.sui-card strong').allTextContents();

    // The tools that only the tests need are served from the same folder. See prepare-demo.mjs.
    expect(names.filter((name) => !name.startsWith('Test:'))).toEqual([
        'Audit log',
        'Customers',
        'Import orders',
        'Refund orders',
        'Sales dashboard',
        'Ship orders',
        'البحث في الطلبات',
    ]);
});

test('the dashboard draws two charts and reacts to the period', async ({ page }) => {
    await page.goto('/dashboard');
    await expect(page.locator('figure.sui-chart svg')).toHaveCount(2);

    // The explanation of the numbers is one click away.
    const counted = page.getByText('Revenue and orders leave out the orders that were refunded.');
    await expect(counted).toBeHidden();
    await page.locator('summary', { hasText: 'How these numbers are counted' }).click();
    await expect(counted).toBeVisible();
    await expect(page.getByText('compares the last 14 days with the 14 days before them')).toBeVisible();

    const bars = page.locator('.sui-bar');
    const twoWeeks = await bars.count();
    expect(twoWeeks).toBeGreaterThan(0);

    await page.getByRole('radio', { name: 'Last 30 days' }).check();

    await expect.poll(() => bars.count(), 'more days, more bars').toBeGreaterThan(twoWeeks);
});

test('the dashboard keeps itself up to date, with the period that was chosen', async ({ page }) => {
    // The clock of the page stands still. Time passes when the test says so.
    const start = new Date('2026-03-02T09:00:00Z');
    await page.clock.install({ time: new Date(start.getTime() - 10_000) });
    await page.clock.pauseAt(start);
    await page.goto('/dashboard');
    await markDocument(page);

    const line = page.locator('.sui-refresh');
    await expect(line).toContainText(/Updated \d\d:\d\d:\d\d/);
    await expect(line.getByRole('button', { name: 'Pause' })).toBeVisible();

    await page.getByRole('radio', { name: 'Last 30 days' }).check();
    const bars = page.locator('.sui-bar');
    await expect.poll(() => bars.count()).toBeGreaterThan(14);
    const month = await bars.count();

    // Half a minute later the page asks again by itself, for the same 30 days.
    const asked = page.waitForRequest((request) => 'refresh' === request.headers()['x-script-ui']);
    await page.clock.runFor(30_000);
    const fields = new URLSearchParams((await asked).postData());
    expect(fields.get('period')).toBe('30');
    await (await (await asked).response()).finished();

    await expect(page.getByRole('radio', { name: 'Last 30 days' })).toBeChecked();
    await expect(bars).toHaveCount(month);
    expect(await sameDocument(page)).toBe(true);
});

test('customers: the country filter takes several countries', async ({ page }) => {
    await page.goto('/customers');
    const all = await rows(page).count();

    await page.getByRole('checkbox', { name: 'Jordan' }).check();
    await expect(rows(page)).toHaveCount(1);
    await page.getByRole('checkbox', { name: 'Egypt' }).check();
    await expect(rows(page)).toHaveCount(2);
    expect((await column(page, 3)).sort()).toEqual(['Egypt', 'Jordan']);

    // None ticked means every country, as the text under the boxes says.
    await page.getByRole('checkbox', { name: 'Jordan' }).uncheck();
    await page.getByRole('checkbox', { name: 'Egypt' }).uncheck();
    await expect(rows(page)).toHaveCount(all);
});

test('customers: a filter and a detail view', async ({ page }) => {
    await page.goto('/customers');
    const all = await rows(page).count();
    expect(all).toBeGreaterThan(1);

    await page.locator('#f-only-customers-with-a-refund').click();
    await settled(page);

    await expect(page.locator('#f-only-customers-with-a-refund')).toBeChecked();
    const withRefund = await rows(page).count();
    expect(withRefund).toBeGreaterThan(0);
    expect(withRefund).toBeLessThan(all);

    await page.locator('[id="f-table:0"]').click();

    // Two views of the customer behind two headers: the orders first.
    await expect(page.getByRole('tab', { name: /^Orders \(\d+\)$/ })).toHaveAttribute('aria-selected', 'true');
    await expect(page.locator('#sui-body table.sui-table'), 'the list and the orders of the customer').toHaveCount(2);
    await expect(page.locator('#sui-body table.sui-table').nth(1)).toBeVisible();
    await expect(page.locator('#sui-body .sui-json')).toBeHidden();

    await page.getByRole('tab', { name: 'Record' }).click();
    await expect(page.locator('#sui-body .sui-json')).toBeVisible();
    await expect(page.locator('#sui-body table.sui-table').nth(1)).toBeHidden();
});

test('text typed while an answer is on its way is sent when the user leaves the field, not before', async ({ page }) => {
    await page.goto('/customers');
    const refundsOnly = page.locator('#f-only-customers-with-a-refund');

    // Somebody who has a refund, so that both filters together leave a row.
    await refundsOnly.click();
    await settled(page);
    const [name] = (await column(page, 1))[0].split(' ');
    await refundsOnly.click();
    await settled(page);

    // From here on, the answer to the first request is held back until the test lets it go.
    let posts = 0;
    let letGo;
    const held = new Promise((resolve) => { letGo = resolve; });
    await page.route('**/customers', async (route) => {
        if ('POST' === route.request().method() && 1 === ++posts) await held;
        await route.continue();
    });

    const first = page.waitForRequest((request) => 'POST' === request.method());
    await refundsOnly.click();
    await first;

    // The user goes on and types while the page waits for its answer.
    await page.locator('#f-search').click();
    await page.keyboard.type(name);
    letGo();
    await settled(page);

    // The answer replaced the content of the page. The field is where it was.
    await expect(refundsOnly).toBeChecked();
    await expect(page.locator('#f-search')).toHaveValue(name);
    await expect(page.locator('#f-search')).toBeFocused();
    await page.waitForTimeout(300);
    expect(posts, 'the half typed text was not sent').toBe(1);

    await page.keyboard.press('Tab');

    await expect.poll(() => posts).toBe(2);
    await settled(page);
    const names = await column(page, 1);
    expect(names.length).toBeGreaterThan(0);
    expect(names.every((shown) => shown.includes(name)), 'only customers with that name').toBe(true);
});

test('the Arabic page runs right to left', async ({ page }) => {
    await page.goto('/orders-ar');

    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
    const label = await page.locator('.sui-field label').first().boundingBox();
    const input = await page.locator('.sui-field .sui-input').first().boundingBox();
    expect(Math.abs(label.x + label.width - (input.x + input.width)), 'the label ends at the right edge of its field').toBeLessThan(2);

    // The title is Arabic, so the texts the library writes itself are Arabic too.
    await expect(page.locator('a.sui-reset')).toHaveText('إعادة تعيين');
    await expect(page.locator('.sui-table-foot')).toHaveText('الصفوف 1 إلى 20 من 80');
    await expect(page.locator('.sui-pager')).toHaveText('السابق' + 'الصفحة 1 من 4' + 'التالي');
    await expect(page.locator('input[type="search"]')).toHaveAttribute('placeholder', 'بحث');

    // The hint of an empty field starts at the right, like its label. Once the user
    // types, the field follows what is typed: Latin letters run left to right.
    const search = page.locator('input[type="search"]');
    await expect(search).toHaveCSS('direction', 'rtl');
    await search.fill('lina');
    await expect(search).toHaveCSS('direction', 'ltr');
    await search.fill('لينا');
    await expect(search).toHaveCSS('direction', 'rtl');
    await expect(page.locator('html')).toHaveAttribute('lang', 'ar');
});

test('when the server does not answer, the page says so in its own language', async ({ page }) => {
    await page.goto('/orders-ar');
    await page.route('**/orders-ar', (route) => route.abort());

    await page.locator('select.sui-input').selectOption('paid');

    await expect(page.locator('#sui-offline')).toHaveText(/^لم يستجب الخادم\./);

    // The browser reports the request that was cut off. That one is expected here.
    expect(page.problems).toHaveLength(1);
    expect(page.problems[0]).toContain('Failed to load resource');
    page.problems.length = 0;
});

test('a script that is opened directly works the same way', async ({ page }) => {
    await page.goto(`${DIRECT_URL}/hello.php`);
    await markDocument(page);

    await page.locator('#f-your-name').fill('Ada');
    await page.locator('#b-say-hello').click();

    await expect(page.locator('#sui-body .sui-success')).toHaveText('Hello, Ada!');
    expect(await sameDocument(page), 'updated in place').toBe(true);
});

test.describe('with a dark system theme', () => {
    test.use({ colorScheme: 'dark' });

    test('the page is dark too', async ({ page }) => {
        await page.goto('/dashboard');

        const [red, green, blue] = await page.evaluate(() => getComputedStyle(document.body).backgroundColor.match(/\d+/g).map(Number));
        expect(red + green + blue, 'a dark background').toBeLessThan(120);
        await expect(page.locator('figure.sui-chart svg')).toHaveCount(2);
    });
});

test.describe('in a phone-sized window', () => {
    test.use({ viewport: PHONE });

    test('the dashboard is not wider than the screen', async ({ page }) => {
        await page.goto('/dashboard');

        await expect(page.locator('figure.sui-chart svg')).toHaveCount(2);
        expect(await noHorizontalOverflow(page)).toBe(true);
    });
});

test.describe('without JavaScript', () => {
    test.use({ javaScriptEnabled: false });

    test('the page still works as a plain form', async ({ page }) => {
        await page.goto('/refund');

        await page.locator('#f-customer-email').fill('omar@example.com');
        await page.locator('.sui-noscript button').click();

        await expect(rows(page).first()).toBeVisible();
        await expect(page.locator('#f-customer-email')).toHaveValue('omar@example.com');
    });
});
