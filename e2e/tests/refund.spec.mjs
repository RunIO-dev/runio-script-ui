import { test, expect } from '@playwright/test';
import { TOOLS_URL, column, markDocument, rows, sameDocument, settled, watch } from './support.mjs';

// One user works through the refund tool. The steps build on each other and share one page.
test.describe.configure({ mode: 'serial' });

let page;
let ordersBefore;
let tickedOrder;

const email = () => page.locator('#f-customer-email');
const status = () => page.locator('#f-status');
const refund = () => page.locator('#b-refund-selected');
const question = () => page.locator('[data-confirm]');
const message = () => page.locator('#sui-body .sui-success');

test.beforeAll(async ({ browser }) => {
    const context = await browser.newContext({ baseURL: TOOLS_URL, colorScheme: 'light' });
    page = watch(await context.newPage());
});

test.afterAll(async () => {
    await page.context().close();
});

test('typing an email and pressing Enter shows the orders without a page load', async () => {
    await page.goto('/refund');
    await markDocument(page);
    await expect(page.locator('#sui-body .sui-info')).toHaveText('Type a customer email to see their orders.');

    await email().click();
    await page.keyboard.type('omar@example.com');
    await page.keyboard.press('Enter');

    await expect(rows(page).first()).toBeVisible();
    expect(await sameDocument(page), 'updated in place').toBe(true);
    await expect(email(), 'focus stays in the field').toBeFocused();

    ordersBefore = await rows(page).count();
    expect(ordersBefore, 'enough orders for the steps below').toBeGreaterThan(1);
});

test('ticking a row brings up the refund button', async () => {
    await expect(refund()).toHaveCount(0);

    await page.locator('[id="f-table:0"]').click();

    await expect(refund()).toBeVisible();
    await expect(page.locator('[id="f-table:0"]')).toBeChecked();
    [tickedOrder] = await column(page, 1);
});

test('a click on a column header sorts, and the tick survives', async () => {
    const ticked = page.locator('#sui-body tbody input:checked');
    const order = await ticked.inputValue();

    await page.locator('[id="s-table:3"]').click();

    await expect(page.locator('#sui-body th[aria-sort="ascending"]')).toHaveCount(1);
    const totals = (await column(page, -1)).map(Number);
    expect(totals, 'sorted by total').toEqual([...totals].sort((a, b) => a - b));
    await expect(ticked, 'still one tick').toHaveCount(1);
    await expect(ticked, 'on the same order').toHaveValue(order);
    await expect(page.locator('[id="s-table:3"]'), 'focus stays on the header').toBeFocused();
});

test('the refund button asks first', async () => {
    await refund().click();

    await expect(question().locator('p')).toHaveText('Refund 1 order?');
    await expect(question(), 'the question gets the focus').toBeFocused();
});

test('cancel closes the question and refunds nothing', async () => {
    await page.locator('[id="b-refund-selected:no"]').click();

    await expect(question()).toHaveCount(0);
    await expect(rows(page)).toHaveCount(ordersBefore);
    await expect(refund(), 'focus goes back to the button').toBeFocused();
});

test('the page is locked while a button is being handled', async () => {
    // Make the server look slow: hold the request back for a moment.
    await page.route('**/refund', async (route) => {
        await new Promise((resolve) => setTimeout(resolve, 800));
        await route.continue();
    });

    await refund().click();

    await expect(page.locator('#sui-body')).toHaveJSProperty('inert', true);
    await expect(page.locator('html')).toHaveClass(/sui-busy/);
    await expect(question()).toBeVisible();
    await expect(page.locator('#sui-body')).toHaveJSProperty('inert', false);
    await expect(page.locator('html')).not.toHaveClass(/sui-busy/);

    await page.unroute('**/refund');

    // Close the question again, so the next step starts where it expects to.
    await page.locator('[id="b-refund-selected:no"]').click();
    await expect(question()).toHaveCount(0);
});

test('answering yes refunds, reloads the table and says so', async () => {
    await refund().click();
    await page.locator('[id="b-refund-selected:yes"]').click();

    await expect(message()).toHaveText('Refunded 1 order.');
    await expect(rows(page)).toHaveCount(ordersBefore - 1);
    expect(await column(page, 1), 'the refunded order is gone').not.toContain(tickedOrder);
    await expect(refund()).toHaveCount(0);
    expect(await sameDocument(page), 'updated in place').toBe(true);
});

test('changing the select reruns the page at once', async () => {
    const paid = await column(page, 1);

    await status().selectOption('shipped');

    await expect(message(), 'the message of the last action is gone').toHaveCount(0);
    await expect(status()).toHaveValue('shipped');
    await expect.poll(() => column(page, 1), 'the orders with the other status').not.toEqual(paid);
});

test('typing and then clicking right away loses neither', async () => {
    const earlier = await column(page, 1);

    await email().click();
    await page.keyboard.press('ControlOrMeta+a');
    await page.keyboard.type('ava@example.com');

    // The field loses the focus on mouse down, which starts a rerun.
    await page.locator('[id="s-table:0"]').hover();
    await page.mouse.down();
    // Its answer arrives while the button is still held. Swapping the page now would lose the click.
    await settled(page);
    expect(await column(page, 1), 'nothing is swapped under a pressed button').toEqual(earlier);
    await page.mouse.up();

    // Both arrived: the click (sorted by order number) and the new email (other rows).
    await expect(page.locator('#sui-body thead th').nth(1)).toHaveAttribute('aria-sort', 'ascending');
    await expect(email()).toHaveValue('ava@example.com');
    const orders = await column(page, 1);
    expect(orders.length).toBeGreaterThan(0);
    expect(orders, 'the orders of the new email').not.toEqual(earlier);
    expect(orders, 'sorted by order number').toEqual([...orders].sort());
});

test('a reload shows the saved inputs and repeats nothing', async () => {
    await page.goto('/refund');

    await expect(email()).toHaveValue('ava@example.com');
    await expect(status()).toHaveValue('shipped');
    await expect(message()).toHaveCount(0);
});

test('only the reset link of the page itself forgets the inputs', async () => {
    // Another site could send the browser to this address. It cannot know the code of the real link.
    await page.goto('/refund?_reset=1');
    await expect(page).toHaveURL(/\/refund$/);
    await expect(email(), 'a made-up link forgets nothing').toHaveValue('ava@example.com');

    const reset = page.locator('a.sui-reset');
    expect(await reset.getAttribute('href')).toMatch(/^\/refund\?_reset=[0-9a-f]{24}$/);

    await reset.click();
    await expect(page).toHaveURL(/\/refund$/);
    await expect(email()).toHaveValue('');
    await expect(status()).toHaveValue('paid');
});

test('a link fills in a field, and the address is cleaned after the first change', async () => {
    await page.goto('/refund?customer-email=lina@example.com');
    await expect(email()).toHaveValue('lina@example.com');

    await status().selectOption('shipped');

    await expect(page).toHaveURL(/\/refund$/);
    await expect(email()).toHaveValue('lina@example.com');
    await expect(status()).toHaveValue('shipped');
});

test('the audit log shows the one refund and nothing else', async () => {
    await page.goto('/audit');

    // The button was pressed three times above. Only the press that was answered with yes is an action.
    // (Other test files leave entries of their own tools in the same log.)
    expect((await column(page, 2)).filter((tool) => 'refund' === tool), 'entries of the refund tool').toHaveLength(1);

    const entry = rows(page).filter({ hasText: 'Refund selected' });
    await expect(entry).toHaveCount(1);
    await expect(entry).toContainText('omar@example.com');
    await expect(entry).toContainText(tickedOrder);
});

test('no script failed and the security policy blocked nothing', async () => {
    expect(page.problems).toEqual([]);
});
