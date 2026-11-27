import { test, expect } from '@playwright/test';
import { DIRECT_URL, PHONE, markDocument, noHorizontalOverflow, rows, sameDocument, settled, watch } from './support.mjs';

// Every test here opens its own browser context, so it starts with nothing saved.
test.beforeEach(({ page }) => {
    watch(page);
});

test.afterEach(({ page }) => {
    expect(page.problems, 'script errors, blocked content and server errors').toEqual([]);
});

test('the index page lists the tools', async ({ page }) => {
    await page.goto('/');

    await expect(page.locator('a.sui-card strong')).toHaveText([
        'Audit log',
        'Customers',
        'Refund orders',
        'Sales dashboard',
        'البحث في الطلبات',
    ]);
});

test('the dashboard draws two charts and reacts to the period', async ({ page }) => {
    await page.goto('/dashboard');
    await expect(page.locator('figure.sui-chart svg')).toHaveCount(2);

    const bars = page.locator('.sui-bar');
    const twoWeeks = await bars.count();
    expect(twoWeeks).toBeGreaterThan(0);

    await page.locator('#f-period').selectOption('30');

    await expect.poll(() => bars.count(), 'more days, more bars').toBeGreaterThan(twoWeeks);
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

    await page.locator('#f-table-0').click();

    await expect(page.locator('#sui-body .sui-json')).toBeVisible();
    await expect(page.locator('#sui-body table.sui-table'), 'the list and the orders of the customer').toHaveCount(2);
});

test('the Arabic page runs right to left', async ({ page }) => {
    await page.goto('/orders-ar');

    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
    const label = await page.locator('.sui-field label').first().boundingBox();
    const input = await page.locator('.sui-field .sui-input').first().boundingBox();
    expect(Math.abs(label.x + label.width - (input.x + input.width)), 'the label ends at the right edge of its field').toBeLessThan(2);
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
