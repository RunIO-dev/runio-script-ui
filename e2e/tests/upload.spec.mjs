import { writeFileSync } from 'node:fs';
import { test, expect } from '@playwright/test';
import { markDocument, rows, sameDocument, settled, watch } from './support.mjs';

// The server of the tests takes files of 2 MB and requests of 3 MB. See php/limits.ini.
const KB = 1024;
const MB = 1024 * KB;

const ORDERS = 'email,items,total\nava@example.com,Notebook,24.50\nomar@example.com,Monitor arm,89\n';

/** A file as the browser hands it over. It only exists in memory. */
const file = (name, content = ORDERS) => ({ name, mimeType: 'application/octet-stream', buffer: Buffer.from(content) });
const sized = (name, bytes) => ({ name, mimeType: 'application/octet-stream', buffer: Buffer.alloc(bytes, 'a') });

const orders = (page) => page.locator('#f-orders-as-csv');
const attachments = (page) => page.locator('#f-attachments');
const names = (page) => page.locator('.sui-file-name');
const complaint = (page) => page.locator('.sui-field-error');
const notice = (page) => page.locator('.sui-callout');

/** How many files a field holds that were not sent yet. */
const waiting = (field) => field.evaluate((input) => input.files.length);

/** The form posts from now on. */
function posts(page) {
    const seen = [];
    page.on('request', (request) => {
        if ('POST' === request.method()) seen.push(request);
    });

    return seen;
}

/**
 * Takes away what the page script knows about the limits. That is the page behind a server
 * with lower limits than PHP has: the script cannot stop the file, and the server turns it down.
 */
const forgetLimits = (field) =>
    field.evaluate((input) => {
        input.removeAttribute('data-sui-max');
        input.removeAttribute('data-sui-total');
    });

test.beforeEach(({ page }) => {
    watch(page);
});

test.afterEach(({ page }) => {
    expect(page.problems, 'script errors, blocked content and server errors').toEqual([]);
});

test('a chosen file is uploaded at once, and the tool shows what is in it', async ({ page }) => {
    await page.goto('/import');
    await markDocument(page);
    const sent = posts(page);

    await expect(page.locator('#l-orders-as-csv')).toHaveText('Allowed: CSV. Up to 1 MB.');
    await expect(orders(page)).toHaveAttribute('accept', '.csv');
    await orders(page).setInputFiles(file('orders.csv'));

    await expect(names(page)).toHaveText(['orders.csv']);
    await expect(page.locator('.sui-file-size')).toHaveText([`${Buffer.byteLength(ORDERS)} B`]);
    await expect(rows(page)).toHaveCount(2);
    await expect(page.locator('#b-import-2-orders')).toBeVisible();

    expect(await sameDocument(page), 'no page load').toBe(true);
    expect(sent, 'one request').toHaveLength(1);
    expect(await sent[0].headerValue('content-type'), 'sent the way files need').toMatch(/^multipart\/form-data/);
    await expect(orders(page), 'the focus is on the field').toBeFocused();
    expect(await waiting(orders(page)), 'the field is empty again: the file is on the server').toBe(0);

    // The form names the file by a handle. Nothing in the page says where the file lies.
    const handle = await page.locator('input[name="_file[orders-as-csv][]"]').inputValue();
    expect(handle).toMatch(/^[0-9a-f]{32}$/);
    expect(await page.content()).not.toMatch(/script-ui-(\d+-)?[0-9a-f]{12}/);
});

test('a page without a chosen file is sent the short way', async ({ page }) => {
    await page.goto('/test-files');
    const sent = posts(page);

    await page.locator('#f-owner').fill('Ava');
    await page.locator('#f-owner').blur();
    await expect(page.locator('.sui-note')).toHaveText('Owner: Ava');

    expect(sent).toHaveLength(1);
    expect(await sent[0].headerValue('content-type')).toMatch(/^application\/x-www-form-urlencoded/);
    expect(sent[0].postData(), 'the empty file field is left out').not.toContain('attachments');
});

test('a file that is too large is turned down before it is sent', async ({ page }) => {
    await page.goto('/import');
    const sent = posts(page);

    await orders(page).setInputFiles(sized('big.csv', MB + 1));

    await expect(complaint(page)).toHaveText('"big.csv" is larger than 1 MB.');
    await expect(complaint(page)).toHaveAttribute('role', 'alert');
    await expect(orders(page)).toHaveAttribute('aria-describedby', /(^| )e-orders-as-csv( |$)/);
    expect(sent, 'nothing was sent').toHaveLength(0);
    expect(await waiting(orders(page)), 'the file left the field').toBe(0);

    // A file that fits is taken, and the complaint goes away.
    await orders(page).setInputFiles(file('orders.csv'));
    await expect(names(page)).toHaveText(['orders.csv']);
    await expect(complaint(page)).toHaveCount(0);
});

test('the name of a file is shown as text, whatever it is', async ({ page }) => {
    await page.goto('/import');

    await orders(page).setInputFiles(sized('<img src=x onerror=alert(1)>$&.csv', MB + 1));

    await expect(complaint(page)).toHaveText('"<img src=x onerror=alert(1)>$&.csv" is larger than 1 MB.');
    await expect(complaint(page).locator('img')).toHaveCount(0);
});

test('the server checks the size and the kind again', async ({ page }) => {
    await page.goto('/import');

    // Dropped onto the field, a file gets past what the dialog offers.
    await orders(page).setInputFiles(file('notes.txt', 'hello'));
    await expect(complaint(page)).toHaveText('"notes.txt" is not allowed here. Allowed: CSV.');
    await expect(names(page)).toHaveCount(0);

    // More than the tool takes, less than PHP takes.
    await forgetLimits(orders(page));
    await orders(page).setInputFiles(sized('big.csv', MB + 1));
    await expect(complaint(page)).toHaveText('"big.csv" is larger than 1 MB.');
    await expect(names(page)).toHaveCount(0);

    // More than PHP takes for one file.
    await forgetLimits(orders(page));
    await orders(page).setInputFiles(sized('bigger.csv', 2 * MB + 100 * KB));
    await expect(complaint(page)).toHaveText('"bigger.csv" is larger than 1 MB.');
    await expect(names(page)).toHaveCount(0);
});

test('a request that the server throws away is explained on the page', async ({ page }) => {
    await page.goto('/import');
    await markDocument(page);
    await forgetLimits(orders(page));

    // More than PHP takes for a whole request: it reads neither the form nor the file.
    await orders(page).setInputFiles(sized('huge.csv', 3 * MB + 100 * KB));

    await expect(complaint(page)).toHaveText('This is more than the server takes at once. Choose a smaller file, or fewer files.');
    expect(await sameDocument(page), 'no page load').toBe(true);
    expect(await waiting(orders(page)), 'the file left the field, so the next request does not carry it again').toBe(0);
    await expect(orders(page)).toBeFocused();

    // The page is not stuck: the next file is taken.
    await orders(page).setInputFiles(file('orders.csv'));
    await expect(names(page)).toHaveText(['orders.csv']);

    // The browser writes the refused request on its console. That one is expected here.
    page.problems = page.problems.filter((problem) => !/\b413\b/.test(problem));
});

test('a file that did not get through leaves its field and is not sent again later', async ({ page }) => {
    await page.goto('/test-files');
    await markDocument(page);
    const sent = posts(page);

    // The connection breaks while the file is on its way.
    await page.route('**/test-files', (route) => ('POST' === route.request().method() ? route.abort('connectionreset') : route.continue()));
    await attachments(page).setInputFiles(file('a.txt', 'AAAA'));

    await expect(page.locator('#sui-offline')).toBeVisible();
    await expect(complaint(page)).toHaveText('"a.txt" did not arrive. Please try again.');
    expect(await waiting(attachments(page)), 'the file left the field').toBe(0);
    await expect(attachments(page), 'the page is open again, with the focus where it was').toBeFocused();
    await page.unroute('**/test-files');

    // Something else on the page changes. Only that is sent, not the file once more.
    await page.locator('#f-owner').fill('Ava');
    await page.locator('#f-owner').blur();
    await expect(page.locator('.sui-note')).toHaveText('Owner: Ava');
    expect(await sent.at(-1).headerValue('content-type')).toMatch(/^application\/x-www-form-urlencoded/);
    await expect(names(page)).toHaveCount(0);
    await expect(page.locator('#sui-offline')).toHaveCount(0);

    // The user chooses the file again, and now it arrives.
    await attachments(page).setInputFiles(file('a.txt', 'AAAA'));
    await expect(names(page)).toHaveText(['a.txt']);
    expect(await sameDocument(page), 'no page load').toBe(true);

    // The browser writes the broken request on its console. That one is expected here.
    page.problems = page.problems.filter((problem) => !/ERR_CONNECTION_RESET|Failed to load resource/.test(problem));
});

test('the answer to an upload does not wait for a pressed mouse button', async ({ page }) => {
    await page.goto('/test-files');

    // Other answers wait while a mouse button is down, so that the click is not lost. This one
    // must not: the page was locked, and an answer that waits can be dropped by the next request.
    // The file would be on the server and nowhere on the page.
    await page.mouse.move(600, 20);
    await page.mouse.down();
    const [answer] = await Promise.all([
        page.waitForResponse((response) => 'POST' === response.request().method()),
        attachments(page).setInputFiles(file('a.txt', 'AAAA')),
    ]);
    await answer.finished();

    await expect(names(page), 'shown while the button is still down').toHaveText(['a.txt'], { timeout: 400 });
    expect(await waiting(attachments(page)), 'and no longer in the field, where it would be sent again').toBe(0);

    // The button comes up over something that starts a request. The file is still there afterwards.
    await page.mouse.up();
    await page.locator('#b-count-them').click();
    await expect(notice(page)).toHaveText('Counted 1 for "".');
    await expect(names(page)).toHaveText(['a.txt']);
});

test('Remove takes the file away, and a new file takes the place of the old one', async ({ page }) => {
    await page.goto('/import');
    await orders(page).setInputFiles(file('first.csv'));
    await expect(names(page)).toHaveText(['first.csv']);
    const first = await page.locator('input[name="_file[orders-as-csv][]"]').inputValue();

    await orders(page).setInputFiles(file('second.csv', 'email,items,total\nlina@example.com,Desk lamp,39.9\n'));
    await expect(names(page)).toHaveText(['second.csv']);
    await expect(rows(page)).toHaveCount(1);
    expect(await page.locator('input[name="_file[orders-as-csv][]"]').inputValue()).not.toBe(first);

    const remove = page.getByRole('button', { name: 'Remove second.csv' });
    await expect(remove).toHaveText('Remove');
    await remove.click();

    await expect(names(page)).toHaveCount(0);
    await expect(page.locator('#b-download-an-example-file')).toBeVisible();
    await expect(orders(page), 'the button is gone, the focus goes to the field').toBeFocused();
});

test('a field for several files adds to what it holds', async ({ page }) => {
    await page.goto('/test-files');
    await markDocument(page);

    await attachments(page).setInputFiles([file('a.txt', 'AAAA'), file('b.txt', 'BB')]);
    await expect(names(page)).toHaveText(['a.txt', 'b.txt']);

    await attachments(page).setInputFiles(file('c.txt', 'C'));
    await expect(names(page)).toHaveText(['a.txt', 'b.txt', 'c.txt']);
    await expect(rows(page)).toHaveCount(3);
    await expect(attachments(page)).toHaveAttribute('data-sui-room', '17');

    await page.getByRole('button', { name: 'Remove b.txt' }).click();
    await expect(names(page)).toHaveText(['a.txt', 'c.txt']);
    await expect(page.getByRole('button', { name: 'Remove c.txt' }), 'the focus stays in the list').toBeFocused();

    // A button gets the files that are there.
    await page.locator('#b-count-them').click();
    await expect(notice(page)).toHaveText('Counted 2 for "".');
    await expect(names(page)).toHaveText(['a.txt', 'c.txt']);
    expect(await sameDocument(page), 'no page load').toBe(true);
});

test('more files than fit are turned down before they are sent', async ({ page }) => {
    await page.goto('/test-files');
    const sent = posts(page);

    await attachments(page).setInputFiles(Array.from({ length: 21 }, (unused, index) => file(`${index + 1}.txt`, 'x')));
    await expect(complaint(page)).toHaveText('No more than 20 files fit here.');
    expect(sent, 'nothing was sent').toHaveLength(0);

    // Files that are small enough one by one, and too much for one request together.
    await attachments(page).setInputFiles(Array.from({ length: 16 }, (unused, index) => sized(`${index + 1}.bin`, 199_000)));
    await expect(complaint(page)).toHaveText('This is more than the server takes at once. Choose a smaller file, or fewer files.');
    expect(sent, 'nothing was sent').toHaveLength(0);
    expect(await waiting(attachments(page))).toBe(0);

    await attachments(page).setInputFiles([sized('fits.bin', 199_000), sized('too-large.bin', 200_001)]);
    await expect(complaint(page)).toHaveText('"too-large.bin" is larger than 195.3 KB.');
    expect(sent, 'nothing was sent').toHaveLength(0);
});

test('a file chosen in a dialog that was open while the page changed still arrives', async ({ page }) => {
    await page.goto('/test-files');
    await page.locator('#f-owner').click();
    await page.keyboard.type('Ava');

    // Straight from the text field to the file field. Leaving the text field runs the page
    // again, and the answer replaces the file field while its dialog is open.
    const [dialog] = await Promise.all([page.waitForEvent('filechooser'), attachments(page).click()]);
    await expect(page.locator('.sui-note')).toHaveText('Owner: Ava');
    await settled(page);

    await dialog.setFiles(file('a.txt', 'AAAA'));

    await expect(names(page)).toHaveText(['a.txt']);
    await expect(page.locator('#f-owner')).toHaveValue('Ava');
});

test('text typed just before a file is chosen travels with it', async ({ page }) => {
    await page.goto('/test-files');
    const sent = posts(page);

    // Typed and not left yet: no request so far.
    await page.locator('#f-owner').click();
    await page.keyboard.type('Omar');
    await attachments(page).setInputFiles(file('a.txt', 'AAAA'));

    await expect(names(page)).toHaveText(['a.txt']);
    await expect(page.locator('.sui-note')).toHaveText('Owner: Omar');
    await settled(page);
    expect(sent, 'the file and the text in one request').toHaveLength(1);
});

test('a screen reader is told which files a field holds after it changed', async ({ page }) => {
    await page.goto('/test-files');
    const said = page.locator('#sui-status');

    await attachments(page).setInputFiles([file('a.txt', 'AAAA'), file('b.txt', 'BB')]);
    await expect(said).toHaveText('a.txt b.txt');

    await page.getByRole('button', { name: 'Remove a.txt' }).click();
    await expect(said).toHaveText('b.txt');

    await page.getByRole('button', { name: 'Remove b.txt' }).click();
    await expect(names(page)).toHaveCount(0);
    await expect(said).toHaveText('');
});

test('a dragged file lights up its field, and cannot be dropped next to it', async ({ page }) => {
    await page.goto('/import');
    await page.locator('#f-orders-as-csv').waitFor();

    // The browser would open a file that misses the field in place of the page.
    const drag = (selector, kind) =>
        page.evaluate(([target, type]) => {
            const carried = new DataTransfer();
            carried.items.add(new File(['x'], 'orders.csv'));
            const event = new DragEvent(type, { dataTransfer: carried, bubbles: true, cancelable: true });
            document.querySelector(target).dispatchEvent(event);

            return { refused: event.defaultPrevented, effect: carried.dropEffect };
        }, [selector, kind]);

    expect(await drag('h1', 'dragover')).toEqual({ refused: true, effect: 'none' });
    await expect(page.locator('html'), 'the field shows that it takes the file').toHaveClass(/sui-dragging/);
    expect((await drag('h1', 'drop')).refused, 'dropped next to the field: nothing happens').toBe(true);
    await expect(page.locator('html')).not.toHaveClass(/sui-dragging/);

    // Over the field the browser does what it always does with a file.
    expect((await drag('#f-orders-as-csv', 'dragover')).refused).toBe(false);
    await expect(page.locator('html')).toHaveClass(/sui-dragging/);
    await expect(page.locator('html'), 'the drag left the window').not.toHaveClass(/sui-dragging/);

    // Text that is dragged is none of this.
    const text = await page.evaluate(() => {
        const carried = new DataTransfer();
        carried.setData('text/plain', 'hello');
        const event = new DragEvent('dragover', { dataTransfer: carried, bubbles: true, cancelable: true });
        document.querySelector('h1').dispatchEvent(event);

        return event.defaultPrevented;
    });
    expect(text).toBe(false);
});

test('a file that is really dragged: next to the field nothing opens, on the field it is uploaded', async ({ page }, testInfo) => {
    const path = testInfo.outputPath('dragged.csv');
    writeFileSync(path, ORDERS);

    await page.goto('/import');
    await markDocument(page);

    // The browser is told to drag a file from the disk, the way a user does it.
    const browser = await page.context().newCDPSession(page);
    const data = { items: [], files: [path], dragOperationsMask: 1 };
    const drag = (type, at) => browser.send('Input.dispatchDragEvent', { type, x: Math.round(at.x), y: Math.round(at.y), data });
    const middle = async (locator) => {
        const box = await locator.boundingBox();

        return { x: box.x + box.width / 2, y: box.y + box.height / 2 };
    };

    // Without the page script this browser opens the file in a new tab. Others open it in place of the page.
    const beside = await middle(page.locator('h1'));
    await drag('dragEnter', beside);
    await drag('dragOver', beside);
    await expect(page.locator('html')).toHaveClass(/sui-dragging/);
    await drag('drop', beside);
    await expect(page.locator('html')).not.toHaveClass(/sui-dragging/);
    await page.waitForTimeout(500);
    expect(page.context().pages(), 'no new tab').toHaveLength(1);
    expect(await sameDocument(page), 'the page is still there').toBe(true);
    await expect(names(page)).toHaveCount(0);

    const field = await middle(orders(page));
    await drag('dragEnter', field);
    await drag('dragOver', field);
    await drag('drop', field);
    await expect(names(page)).toHaveText(['dragged.csv']);
    await expect(rows(page)).toHaveCount(2);
});

test('an Arabic page speaks Arabic about its files', async ({ page }) => {
    await page.goto('/test-files-ar');
    const report = page.locator('#f-report');

    await expect(page.locator('#l-report')).toHaveText('المسموح: PDF. حتى 1.5 كيلوبايت.');

    await report.setInputFiles(sized('كبير.pdf', 2000));
    await expect(complaint(page)).toHaveText('«كبير.pdf» أكبر من 1.5 كيلوبايت.');

    await report.setInputFiles(sized('تقرير.pdf', 700));
    await expect(names(page)).toHaveText(['تقرير.pdf']);
    await expect(page.locator('.sui-file-size')).toHaveText(['700 بايت']);
    await expect(page.getByRole('button', { name: 'إزالة تقرير.pdf' })).toHaveText('إزالة');
});

test('a file field fits a phone', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 800 });
    await page.goto('/test-files');

    await attachments(page).setInputFiles([file('a-file-with-a-very-long-name-that-does-not-fit-into-one-line-of-a-phone-screen.txt', 'A'), file('b.txt', 'B')]);
    await expect(names(page)).toHaveCount(2);

    expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1), 'nothing sticks out').toBe(true);
    // A finger needs something to hit.
    const remove = await page.getByRole('button', { name: 'Remove b.txt' }).boundingBox();
    expect(remove.height).toBeGreaterThanOrEqual(24);
    expect(remove.width).toBeGreaterThanOrEqual(24);
});

test('a file as a German Excel saves it is read like any other', async ({ page }) => {
    await page.goto('/import');

    // A mark at the start, semicolons, Windows line ends, a comma in the amount, an empty line at the end.
    await orders(page).setInputFiles(file('bestellungen.csv', '\uFEFFEmail;Items;Total\r\nava@example.com;Notizbuch;24,50\r\nomar@example.com;Lampe;89\r\n\r\n'));

    await expect(names(page)).toHaveText(['bestellungen.csv']);
    await expect(rows(page)).toHaveCount(2);
    await expect(rows(page).first()).toContainText('Notizbuch');
    await expect(rows(page).first()).toContainText('24.5');
    await expect(notice(page)).toHaveCount(0);
});

test('a file without the columns the tool needs is turned down with the reason', async ({ page }) => {
    await page.goto('/import');

    await orders(page).setInputFiles(file('people.csv', 'name,city\nAva,London\n'));

    await expect(notice(page).first()).toContainText('Missing: email, items, total.');
    await expect(notice(page).last()).toHaveText('No order could be read from people.csv.');
    await expect(page.locator('#sui-body table')).toHaveCount(0);
});

test('orders from a file are added after a look at them, and the file is gone afterwards', async ({ page }) => {
    await page.goto('/ship');
    await page.locator('#f-table\\:find').fill('Pen holder');
    await expect(page.locator('.sui-empty')).toHaveText('No rows match the search.');

    await page.goto('/import');
    await orders(page).setInputFiles(file('new-orders.csv', 'email,items,total\nava@example.com,Pen holder,12\nnobody@example.com,Lamp,10\nomar@example.com,Pen holder,abc\n'));

    // What cannot be added is said, and what can is shown.
    await expect(notice(page)).toContainText('Row 2: no customer has the email "nobody@example.com".');
    await expect(notice(page)).toContainText('Row 3: "abc" is not an amount.');
    await expect(rows(page)).toHaveCount(1);

    await page.locator('#b-import-1-order').click();
    await expect(page.locator('[data-confirm]')).toContainText('Add 1 order to the shop?');
    await page.locator('#b-import-1-order\\:yes').click();

    await expect(notice(page)).toHaveText('Added 1 order from new-orders.csv.');
    await expect(names(page), 'the tool forgot the file').toHaveCount(0);
    await expect(page.locator('#b-download-an-example-file')).toBeVisible();

    await page.goto('/ship');
    await page.locator('#f-table\\:find').fill('Pen holder');
    await expect(rows(page)).toHaveCount(1);
    await expect(rows(page).first()).toContainText('Ava Morgan');

    // The log says which file it was.
    await page.goto('/audit');
    const entry = rows(page).first();
    await expect(entry).toContainText('import');
    await expect(entry).toContainText('new-orders.csv');
    await expect(entry).toContainText('sha256');
});

test.describe('without JavaScript', () => {
    test.use({ javaScriptEnabled: false });

    test('a chosen file travels with the next button', async ({ page }) => {
        await page.goto('/import');
        await expect(page.locator('#sui-form')).toHaveAttribute('enctype', 'multipart/form-data');

        await orders(page).setInputFiles(file('orders.csv'));
        await expect(names(page), 'nothing happens until a button is pressed').toHaveCount(0);
        await page.getByRole('button', { name: 'Update' }).click();

        await expect(names(page)).toHaveText(['orders.csv']);
        await expect(rows(page)).toHaveCount(2);

        await page.getByRole('button', { name: 'Remove orders.csv' }).click();
        await expect(names(page)).toHaveCount(0);
    });

    test('a button gets the files that arrive with its own request', async ({ page }) => {
        await page.goto('/test-files');

        await page.locator('#f-owner').fill('Lina');
        await attachments(page).setInputFiles([file('a.txt', 'AAAA'), file('b.txt', 'BB')]);
        await page.locator('#b-count-them').click();

        await expect(notice(page)).toHaveText('Counted 2 for "Lina".');
        await expect(names(page)).toHaveText(['a.txt', 'b.txt']);
    });

    test('the server says what is wrong with a file', async ({ page }) => {
        await page.goto('/import');

        await orders(page).setInputFiles(sized('big.csv', MB + 1));
        await page.getByRole('button', { name: 'Update' }).click();

        await expect(complaint(page)).toHaveText('"big.csv" is larger than 1 MB.');
        await expect(names(page)).toHaveCount(0);
    });

    test('a request that the server throws away gets a page that says so', async ({ page }) => {
        await page.goto('/import');

        await orders(page).setInputFiles(sized('huge.csv', 3 * MB + 100 * KB));
        const [response] = await Promise.all([
            page.waitForResponse((answer) => 'POST' === answer.request().method()),
            page.getByRole('button', { name: 'Update' }).click(),
        ]);

        expect(response.status()).toBe(413);
        await expect(page.locator('body')).toContainText('Too large');
        await expect(page.locator('body')).toContainText('This is more than the server takes at once.');

        await page.getByRole('link', { name: 'Back to the page' }).click();
        await expect(orders(page)).toBeVisible();

        page.problems = page.problems.filter((problem) => !/\b413\b/.test(problem));
    });
});
