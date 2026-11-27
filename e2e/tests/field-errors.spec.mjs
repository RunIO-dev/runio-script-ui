import { test, expect } from '@playwright/test';
import { PHONE, markDocument, noHorizontalOverflow, sameDocument, settled, watch } from './support.mjs';

// The page of these tests is e2e/tools/test-field-errors.php: a form with every kind of
// field, one of them in an expander and one behind a tab. "Save" checks them all, and says
// under each field what is wrong with it.
const field = (page, label) => page.getByLabel(label, { exact: true });
const wrong = (page, key) => page.locator(`#e-${key}`);
const notes = (page) => page.locator('#sui-body .sui-field-error');
const save = (page) => page.getByRole('button', { name: 'Save', exact: true });
const saved = (page) => page.locator('#sui-body .sui-success');
const tab = (page, name) => page.getByRole('tab', { name, exact: true });
const box = (page, name) => page.getByRole('checkbox', { name, exact: true });
const radio = (page, name) => page.getByRole('radio', { name, exact: true });
const delivery = (page) => page.locator('details.sui-expander');
const report = (page) => page.locator('input[type="file"]');

/** Types into a field and leaves it, which runs the page. */
async function type(page, label, value) {
    await field(page, label).fill(value);
    await field(page, label).blur();
    await settled(page);
}

/** Fills the form in so that nothing is wrong with it, apart from the parts that are left out. */
async function fillIn(page, { except = [] } = {}) {
    const left = new Set(except);

    if (!left.has('name')) await type(page, 'Name', 'Ava');
    if (!left.has('email')) await type(page, 'Email', 'ava@example.com');
    if (!left.has('amount')) await type(page, 'Amount', '5');
    if (!left.has('days')) {
        await box(page, 'Mon').check();
        await settled(page);
    }
    if (!left.has('terms')) {
        await box(page, 'I accept the terms').check();
        await settled(page);
    }
    if (!left.has('street')) {
        // A press on "Save" may have opened the box already. Another click would close it.
        if (!(await delivery(page).evaluate((details) => details.open))) await delivery(page).locator('summary').click();
        await type(page, 'Street', 'Main Street 1');
    }
    if (!left.has('vat')) {
        await tab(page, 'Billing').click();
        await type(page, 'VAT number', 'DE123');
    }
}

test.beforeEach(({ page }) => {
    watch(page);
});

test.afterEach(({ page }) => {
    expect(page.problems, 'script errors, blocked content and server errors').toEqual([]);
});

test('a pressed button says under each field what is wrong with it', async ({ page }) => {
    await page.goto('/test-field-errors');
    await markDocument(page);

    await save(page).click();

    await expect(wrong(page, 'name')).toHaveText('Error: Enter a name.');
    await expect(wrong(page, 'email')).toHaveText('Error: Enter an email address.');
    await expect(wrong(page, 'amount')).toHaveText('Error: Enter an amount above 0.');
    await expect(wrong(page, 'days')).toHaveText('Error: Tick at least one day.');
    await expect(wrong(page, 'i-accept-the-terms')).toHaveText('Error: Accept the terms to go on.');
    await expect(wrong(page, 'street')).toHaveText('Error: Enter a street.');
    await expect(wrong(page, 'vat-number')).toHaveText('Error: Enter a VAT number.');
    await expect(notes(page), 'nothing is wrong with the plan and the report').toHaveCount(7);
    await expect(saved(page), 'the work is not done').toHaveCount(0);

    // Every note can be seen, and it stands right under its field.
    for (const note of await notes(page).all()) await expect(note).toBeVisible();
    const name = await field(page, 'Name').boundingBox();
    const said = await wrong(page, 'name').boundingBox();
    expect(said.y).toBeGreaterThanOrEqual(name.y + name.height);
    expect(said.y - (name.y + name.height), 'right under it').toBeLessThan(12);
    expect(Math.abs(said.x - name.x), 'in line with it').toBeLessThan(2);

    expect(await sameDocument(page), 'the page was updated in place').toBe(true);
});

test('the first wrong field on the page gets the keyboard focus', async ({ page }) => {
    await page.goto('/test-field-errors');

    await save(page).click();
    await expect(field(page, 'Name')).toBeFocused();
    await expect(field(page, 'Name'), 'the user sees where they are').toBeInViewport();

    // The name is right now, so the next one is the first.
    await field(page, 'Name').fill('Ava');
    await save(page).click();
    await expect(notes(page)).toHaveCount(6);
    await expect(field(page, 'Email')).toBeFocused();

    await field(page, 'Email').fill('ava@example.com');
    await field(page, 'Amount').fill('5');
    await save(page).click();
    await expect(notes(page)).toHaveCount(4);
    await expect(box(page, 'Mon'), 'in a group of tick boxes it is the first box').toBeFocused();
});

test('in a group of radio buttons the focus goes to the one that is chosen', async ({ page }) => {
    await page.goto('/test-field-errors');
    await fillIn(page);
    await radio(page, 'Company').check();
    await settled(page);

    await save(page).click();

    await expect(wrong(page, 'plan')).toHaveText('Error: The Company plan is sold out.');
    await expect(notes(page)).toHaveCount(1);
    await expect(radio(page, 'Company')).toBeFocused();
    await expect(radio(page, 'Company'), 'the choice is kept').toBeChecked();

    // Every option of the group has a red ring, and the ring is as round as the button.
    for (const plan of ['Free', 'Team', 'Company']) {
        const look = await radio(page, plan).evaluate((option) => {
            const style = getComputedStyle(option);

            return `${style.boxShadow} ${style.borderTopLeftRadius}`;
        });
        expect(look, plan).toBe('rgb(180, 42, 43) 0px 0px 0px 2px 50%');
    }

    // The arrow keys still walk through the group from there.
    await page.keyboard.press('ArrowUp');
    await expect(radio(page, 'Team')).toBeChecked();
    await settled(page);
    await save(page).click();
    await expect(saved(page)).toHaveText('Saved for Ava.');
});

test('when everything is right the work is done, and no note is left', async ({ page }) => {
    await page.goto('/test-field-errors');
    await save(page).click();
    await expect(notes(page)).toHaveCount(7);

    await fillIn(page);
    await save(page).click();

    await expect(saved(page)).toHaveText('Saved for Ava.');
    await expect(notes(page)).toHaveCount(0);
    await expect(page.locator('#sui-body [aria-invalid]')).toHaveCount(0);
    await expect(page.locator('#sui-body .sui-field-invalid')).toHaveCount(0);
    await expect(save(page), 'nothing took the focus away from the button').toBeFocused();
});

test('a field that is wrong says so to software that reads the page out', async ({ page }) => {
    await page.goto('/test-field-errors');
    await save(page).click();

    await expect(field(page, 'Name')).toHaveAttribute('aria-invalid', 'true');
    // What is wrong is read before the help text.
    await expect(field(page, 'Name')).toHaveAccessibleDescription('Error: Enter a name. As on the invoice.');
    await expect(field(page, 'Email')).toHaveAccessibleDescription('Error: Enter an email address.');
    await expect(field(page, 'Check'), 'a field that is right says nothing').not.toHaveAttribute('aria-invalid');

    // A group is described by its note, and every tick box says that it is wrong.
    await expect(page.getByRole('group', { name: 'Days' })).toHaveAccessibleDescription('Error: Tick at least one day.');
    for (const day of ['Mon', 'Tue', 'Wed']) await expect(box(page, day)).toHaveAttribute('aria-invalid', 'true');
    await expect(box(page, 'I accept the terms')).toHaveAccessibleDescription('Error: Accept the terms to go on.');

    // Of the answers to the button one is read out by itself: that of the field with the
    // focus. Seven at once would be noise, and the others are read in their fields.
    await expect(field(page, 'Name')).toBeFocused();
    await expect(wrong(page, 'name')).toHaveAttribute('role', 'alert');
    await expect(page.locator('#sui-body .sui-field-error[role]')).toHaveCount(1);

    // The word in front is there to be read out. It takes no room on the screen.
    const word = await wrong(page, 'name').locator('.sui-sr').boundingBox();
    expect(word.width).toBeLessThanOrEqual(1);
    expect(word.height).toBeLessThanOrEqual(1);
});

test('the note is red with a sign in front of it, and so is the frame of the field', async ({ page }) => {
    await page.goto('/test-field-errors');
    await save(page).click();
    await expect(notes(page)).toHaveCount(7);

    const look = await page.evaluate(() => {
        const style = (id, part) => getComputedStyle(document.getElementById(id), part);

        return {
            note: style('e-name').color,
            // Colour alone says nothing to somebody who cannot tell red from grey.
            sign: style('e-name', '::before').content,
            signColour: style('e-name', '::before').backgroundColor,
            frame: style('f-name').borderTopColor,
            rightField: style('f-check').borderTopColor,
            tickBox: style('f-i-accept-the-terms').boxShadow,
            // The ring of the keyboard focus is still there, around the red frame.
            ring: `${style('f-name').outlineStyle} ${style('f-name').outlineColor}`,
        };
    });

    expect(look).toEqual({
        note: 'rgb(180, 42, 43)',
        sign: '"×" / ""',
        signColour: 'rgb(220, 60, 61)',
        frame: 'rgb(180, 42, 43)',
        rightField: 'rgb(138, 138, 138)',
        tickBox: 'rgb(180, 42, 43) 0px 0px 0px 2px',
        ring: 'solid rgb(27, 100, 190)',
    });
});

test('a note that a button asked for is gone when the page runs again, and the focus stays with the user', async ({ page }) => {
    await page.goto('/test-field-errors');
    await save(page).click();
    await expect(notes(page)).toHaveCount(7);
    await expect(field(page, 'Name')).toBeFocused();

    // The user corrects the field and goes on. The page runs, and this time nobody
    // pressed the button: nothing is checked, and nothing throws them back.
    await page.keyboard.type('Ava');
    await page.keyboard.press('Tab');
    await settled(page);

    await expect(notes(page)).toHaveCount(0);
    await expect(field(page, 'Email')).toBeFocused();
    await expect(field(page, 'Name')).toHaveValue('Ava');
    // What the press opened stays open.
    await expect(delivery(page)).toHaveAttribute('open', '');
    await expect(tab(page, 'Billing')).toHaveAttribute('aria-selected', 'true');
});

test('checked while the user types, a note comes and goes without taking the focus', async ({ page }) => {
    await page.goto('/test-field-errors');
    await field(page, 'Check').selectOption('while typing');
    await settled(page);
    await type(page, 'Name', 'Ava');

    await field(page, 'Email').fill('ava');
    await page.keyboard.press('Tab');
    await settled(page);

    await expect(wrong(page, 'email')).toHaveText('Error: Enter an email address.');
    await expect(field(page, 'Email')).toHaveAttribute('aria-invalid', 'true');
    await expect(field(page, 'Amount'), 'the user is in the next field already').toBeFocused();
    // Nobody asked: it is not read out over what the user does now. They hear it in the field.
    await expect(wrong(page, 'email')).not.toHaveAttribute('role');
    await expect(field(page, 'Email')).toHaveAccessibleDescription('Error: Enter an email address.');

    // It stays while other fields change, because it is checked on every run.
    await field(page, 'Amount').fill('5');
    await page.keyboard.press('Tab');
    await settled(page);
    await expect(wrong(page, 'email')).toBeVisible();

    // The button says it the same way, once. Now it is the answer to a press, and
    // the first thing that is wrong: the user is taken there, and it is read out.
    await save(page).click();
    await expect(wrong(page, 'email')).toHaveText('Error: Enter an email address.');
    await expect(field(page, 'Email')).toBeFocused();
    await expect(wrong(page, 'email')).toHaveAttribute('role', 'alert');

    await field(page, 'Email').fill('ava@example.com');
    await field(page, 'Email').blur();
    await settled(page);
    await expect(wrong(page, 'email')).toHaveCount(0);
    await expect(field(page, 'Email')).not.toHaveAttribute('aria-invalid');
});

test('a closed expander opens for the field inside it', async ({ page }) => {
    await page.goto('/test-field-errors');
    await fillIn(page, { except: ['street'] });
    await expect(delivery(page), 'the user never opened it').not.toHaveAttribute('open');
    await expect(field(page, 'Street')).toBeHidden();

    await save(page).click();

    await expect(delivery(page)).toHaveAttribute('open', '');
    await expect(wrong(page, 'street')).toBeVisible();
    await expect(notes(page)).toHaveCount(1);
    await expect(field(page, 'Street')).toBeFocused();
    await expect(field(page, 'Street')).toBeInViewport();

    // The box stays open when the page is drawn again, and when it is loaded again.
    await page.keyboard.type('Main Street 1');
    await page.keyboard.press('Tab');
    await settled(page);
    await expect(delivery(page)).toHaveAttribute('open', '');
    await page.reload();
    await expect(delivery(page)).toHaveAttribute('open', '');
    await expect(field(page, 'Street')).toHaveValue('Main Street 1');
});

test('the tab that holds the wrong field comes into view', async ({ page }) => {
    await page.goto('/test-field-errors');
    await fillIn(page, { except: ['vat'] });
    await expect(tab(page, 'Contact')).toHaveAttribute('aria-selected', 'true');
    await expect(field(page, 'VAT number')).toBeHidden();

    await save(page).click();

    await expect(tab(page, 'Billing')).toHaveAttribute('aria-selected', 'true');
    await expect(field(page, 'Phone')).toBeHidden();
    await expect(wrong(page, 'vat-number')).toBeVisible();
    await expect(field(page, 'VAT number')).toBeFocused();

    // The user can still look at the other tab. The next press brings this one back.
    await tab(page, 'Contact').click();
    await expect(field(page, 'Phone')).toBeVisible();
    await save(page).click();
    await expect(tab(page, 'Billing')).toHaveAttribute('aria-selected', 'true');
    await expect(field(page, 'VAT number')).toBeFocused();

    await page.keyboard.type('DE123');
    await save(page).click();
    await expect(saved(page)).toHaveText('Saved for Ava.');
    await expect(tab(page, 'Billing'), 'and it stays in view').toHaveAttribute('aria-selected', 'true');
});

test('a file field is marked like any other, and a file that is turned down takes the place of the note', async ({ page }) => {
    await page.goto('/test-field-errors');
    await fillIn(page);
    await report(page).setInputFiles({ name: 'notes.txt', mimeType: 'text/plain', buffer: Buffer.from('hello') });
    await expect(page.locator('.sui-file-name')).toHaveText('notes.txt');

    await save(page).click();

    await expect(wrong(page, 'report')).toHaveText('Error: The report has to be a CSV file.');
    await expect(report(page)).toHaveAttribute('aria-invalid', 'true');
    await expect(report(page)).toHaveAccessibleDescription('Error: The report has to be a CSV file. Up to 1 KB.');
    await expect(report(page)).toBeFocused();

    // The page script turns a file down before it is sent. It says so in the same place,
    // in the same way: with the word in front, and before what the field takes.
    await report(page).setInputFiles({ name: 'big.csv', mimeType: 'text/csv', buffer: Buffer.alloc(2048, 'a') });
    await expect(wrong(page, 'report')).toHaveText('Error: "big.csv" is larger than 1 KB.');
    await expect(wrong(page, 'report').locator('.sui-sr')).toHaveText('Error:');
    await expect(wrong(page, 'report')).toHaveAttribute('role', 'alert');
    await expect(report(page)).toHaveAttribute('aria-describedby', 'e-report l-report');
    await expect(notes(page)).toHaveCount(1);
});

test('a note of the tool that was not read out is read out when the page script writes into it', async ({ page }) => {
    await page.goto('/test-field-errors');
    await field(page, 'Check').selectOption('while typing');
    await settled(page);

    // The file arrives and the tool does not like it. No button was pressed: the note is not read out.
    await report(page).setInputFiles({ name: 'notes.txt', mimeType: 'text/plain', buffer: Buffer.from('hello') });
    await expect(wrong(page, 'report')).toHaveText('Error: The report has to be a CSV file.');
    await expect(wrong(page, 'report')).not.toHaveAttribute('role');

    // The next file is turned down by the page script. That is news.
    await report(page).setInputFiles({ name: 'big.csv', mimeType: 'text/csv', buffer: Buffer.alloc(2048, 'a') });
    await expect(wrong(page, 'report')).toHaveText('Error: "big.csv" is larger than 1 KB.');
    await expect(wrong(page, 'report')).toHaveAttribute('role', 'alert');
    await expect(notes(page)).toHaveCount(1);
});

test('a file that the page script turns down is described before the limits of the field', async ({ page }) => {
    await page.goto('/test-field-errors');
    await expect(report(page)).toHaveAttribute('aria-describedby', 'l-report');

    await report(page).setInputFiles({ name: 'big.csv', mimeType: 'text/csv', buffer: Buffer.alloc(2048, 'a') });

    await expect(wrong(page, 'report')).toHaveText('Error: "big.csv" is larger than 1 KB.');
    await expect(report(page)).toHaveAttribute('aria-describedby', 'e-report l-report');
    await expect(report(page)).toHaveAccessibleDescription('Error: "big.csv" is larger than 1 KB. Up to 1 KB.');
    // The field looks, and says, what a field does that the server found wrong.
    const frame = page.locator('.sui-field-file');
    await expect(report(page)).toHaveAttribute('aria-invalid', 'true');
    await expect(frame).toHaveClass('sui-field sui-field-file sui-field-invalid');
    expect(await report(page).evaluate((input) => getComputedStyle(input).borderTopColor)).toBe('rgb(180, 42, 43)');

    // Said twice, it is still one note and named once.
    await report(page).setInputFiles({ name: 'bigger.csv', mimeType: 'text/csv', buffer: Buffer.alloc(4096, 'a') });
    await expect(wrong(page, 'report')).toHaveText('Error: "bigger.csv" is larger than 1 KB.');
    await expect(report(page)).toHaveAttribute('aria-describedby', 'e-report l-report');
    await expect(frame).toHaveClass('sui-field sui-field-file sui-field-invalid');

    // A file that fits is taken, and the mark goes with the note.
    await report(page).setInputFiles({ name: 'small.csv', mimeType: 'text/csv', buffer: Buffer.from('a,b') });
    await expect(page.locator('.sui-file-name')).toHaveText('small.csv');
    await expect(notes(page)).toHaveCount(0);
    await expect(report(page)).not.toHaveAttribute('aria-invalid');
    await expect(frame).toHaveClass('sui-field sui-field-file');
});

test('on a phone the wrong field is brought into view, and nothing is wider than the screen', async ({ page }) => {
    await page.setViewportSize(PHONE);
    await page.goto('/test-field-errors');
    await save(page).scrollIntoViewIfNeeded();
    await expect(field(page, 'Name'), 'the form is longer than the screen').not.toBeInViewport();

    await save(page).click();

    await expect(field(page, 'Name')).toBeFocused();
    await expect(field(page, 'Name')).toBeInViewport();
    expect(await noHorizontalOverflow(page)).toBe(true);
});

test('on a page from right to left the sign stands at the right, where the note starts', async ({ page }) => {
    await page.goto('/test-field-errors-ar');
    await radio(page, 'الرياض').check();
    await settled(page);

    await page.getByRole('button', { name: 'حفظ', exact: true }).click();

    await expect(wrong(page, 'الاسم')).toHaveText('خطأ: اكتب الاسم.');
    await expect(wrong(page, 'المدينة')).toHaveText('خطأ: لا نوصّل إلى الرياض بعد.');
    await expect(field(page, 'الاسم')).toBeFocused();
    await expect(page.getByRole('group', { name: 'المدينة' })).toHaveAccessibleDescription('خطأ: لا نوصّل إلى الرياض بعد.');

    const look = await wrong(page, 'الاسم').evaluate((note) => {
        const style = getComputedStyle(note);
        const sign = getComputedStyle(note, '::before');

        return { direction: style.direction, room: style.paddingRight, otherSide: style.paddingLeft, sign: sign.right };
    });
    expect(look).toEqual({ direction: 'rtl', room: '22px', otherSide: '0px', sign: '0px' });
});

test.describe('without JavaScript', () => {
    test.use({ javaScriptEnabled: false });

    test('the browser puts the focus on the first wrong field, and every wrong field is in sight', async ({ page }) => {
        await page.goto('/test-field-errors');
        await expect(field(page, 'Street')).toBeHidden();

        await save(page).click();

        await expect(notes(page)).toHaveCount(7);
        await expect(field(page, 'Name')).toBeFocused();
        await expect(field(page, 'Name')).toHaveAttribute('aria-invalid', 'true');
        await expect(delivery(page)).toHaveAttribute('open', '');
        await expect(wrong(page, 'street')).toBeVisible();
        await expect(tab(page, 'Billing')).toHaveAttribute('aria-selected', 'true');
        await expect(wrong(page, 'vat-number')).toBeVisible();

        // Filled in and sent again, the first that is still wrong has the focus.
        await field(page, 'Name').fill('Ava');
        await field(page, 'Email').fill('ava@example.com');
        await save(page).click();
        await expect(notes(page)).toHaveCount(5);
        await expect(field(page, 'Amount')).toBeFocused();
        await expect(field(page, 'Name')).toHaveValue('Ava');
    });

    test('a file that the server turns down holds the work back, like anything else that is wrong', async ({ page }) => {
        await page.goto('/test-field-errors');
        await field(page, 'Name').fill('Ava');
        await field(page, 'Email').fill('ava@example.com');
        await field(page, 'Amount').fill('5');
        await box(page, 'Mon').check();
        await box(page, 'I accept the terms').check();
        await delivery(page).locator('summary').click();
        await field(page, 'Street').fill('Main Street 1');
        // A header of the tabs sends the form, and the page comes back with that tab.
        await tab(page, 'Billing').click();
        await field(page, 'VAT number').fill('DE123');

        // Nothing checks the file before it is sent. It travels with the button, and
        // the user believes that it is attached.
        await report(page).setInputFiles({ name: 'big.csv', mimeType: 'text/csv', buffer: Buffer.alloc(2048, 'a') });
        await save(page).click();

        await expect(wrong(page, 'report')).toHaveText('Error: "big.csv" is larger than 1 KB.');
        await expect(notes(page)).toHaveCount(1);
        await expect(saved(page), 'nothing is saved without the file the user sent').toHaveCount(0);
        await expect(report(page)).toHaveAttribute('aria-invalid', 'true');
        await expect(page.locator('.sui-field-file')).toHaveClass('sui-field sui-field-file sui-field-invalid');
        await expect(report(page)).toBeFocused();

        // The user read it, and sends the form without the file.
        await save(page).click();
        await expect(saved(page)).toHaveText('Saved for Ava.');
        await expect(notes(page)).toHaveCount(0);
    });
});
