import { expect, test } from '@playwright/test';
import { run } from '../../../site/.vitepress/theme/live/click.js';
import { fresh, shows, start, stop, tick } from '../../../site/.vitepress/theme/live/task.js';
import { markDocument, noHorizontalOverflow, PHONE, sameDocument, settled, watch } from '../../tests/support.mjs';
import { SAMPLES_URL } from '../support.mjs';

// Every page is watched: a script error or a message on the error console fails the test.
test.beforeEach(({ page }) => {
    watch(page);
});
test.afterEach(({ page }) => {
    expect(page.problems).toEqual([]);
});

test.describe('what the picture of one click says, against the real library', () => {
    // What a visitor does, one thing after the other, on the same page.
    const uses = [
        { does: 'types a name and presses Greet', name: 'Ava', how: 'press' },
        { does: 'changes the name and presses Enter', name: 'Omar', how: 'enter' },
        { does: 'presses Greet again', name: 'Omar', how: 'press' },
        { does: 'changes the name and leaves the field', name: 'Lea', how: 'leave' },
        { does: 'leaves blanks around the name', name: '  Mona  ', how: 'press' },
        { does: 'presses Greet with nothing but blanks', name: '   ', how: 'press' },
        { does: 'types markup', name: '<b>"x"</b>', how: 'press' },
    ];

    test('the same uses give the same page, and send what the picture says they send', async ({ page }) => {
        const forms = [];
        page.on('request', (request) => {
            if (request.method() === 'POST') forms.push(new URLSearchParams(request.postData() ?? ''));
        });

        await page.goto(`${SAMPLES_URL}/hello`);
        await markDocument(page);
        const field = page.getByLabel('Your name');
        const greeting = page.locator('#sui-body .sui-success');

        for (const use of uses) {
            await test.step(use.does, async () => {
                const pressed = use.how === 'press';
                const told = run({ name: use.name, pressed });

                await field.fill(use.name);
                if (pressed) {
                    await page.getByRole('button', { name: 'Greet' }).click();
                } else if (use.how === 'enter') {
                    await field.press('Enter');
                } else {
                    await field.blur();
                }

                // The page that comes back is the one that the picture ends with.
                if (told.page.greeting === null) {
                    await expect(greeting).toHaveCount(0);
                } else {
                    await expect(greeting).toHaveText(told.page.greeting);
                }
                await settled(page);
                await expect(field).toHaveValue(told.page.name);

                // "The browser sends the whole form, and which button was pressed": the fields of
                // the real form have the names that the picture shows.
                const form = forms.at(-1);
                const shown = Object.fromEntries(told.sent.map((field) => [field.name, field.value]));
                expect(Object.keys(shown).sort()).toEqual([...form.keys()].sort());
                expect(shown['your-name'], 'the field travels as it was typed').toBe(`"${form.get('your-name')}"`);
                expect(form.get('_click') ?? undefined, 'the button that counts as pressed').toBe(shown._click);
                expect(form.get('_token')).toMatch(/^[0-9a-f]{64}$/);
            });
        }

        // "The browser swaps the content of the page. Nothing is loaded again."
        expect(await sameDocument(page)).toBe(true);
    });

    test('a form without the token of the page is refused before the script runs', async ({ page }) => {
        // "Script UI asks first: ... is the form ours?"
        await page.goto(`${SAMPLES_URL}/hello`);
        const answer = await page.request.post(`${SAMPLES_URL}/hello`, { form: { 'your-name': 'Mallory', _click: 'greet' } });

        expect(answer.status()).toBe(419);
        expect(await answer.text()).not.toContain('Hello, Mallory.');

        // The page was told that somebody answered 419. That is what this test asked for.
        page.problems.length = 0;
    });
});

test.describe('the picture of one click on the first page', () => {
    const scene = (page) => page.locator('.live-click');
    const stepText = (page) => scene(page).locator('.live-step');

    // Whoever asked for less motion gets no picture that plays by itself. The tests that
    // step through a run by hand ask for the same, so nothing moves under them.
    const open = async (page) => {
        await page.emulateMedia({ reducedMotion: 'reduce' });
        await page.goto('/');
        await expect(scene(page).getByRole('button', { name: 'Play', exact: true })).toBeVisible();
    };

    // Uses the small page, then goes through the run step by step and checks each step.
    const walk = async (page, name, how) => {
        const told = run({ name, pressed: how === 'press' });
        const field = scene(page).getByLabel('Your name');

        await field.fill(name);
        if (how === 'press') {
            await scene(page).getByRole('button', { name: 'Greet' }).click();
        } else {
            await field.press('Enter');
        }

        for (const [at, step] of told.steps.entries()) {
            if (at > 0) await scene(page).getByRole('button', { name: 'Next' }).click();
            await expect(scene(page).locator('.live-count')).toHaveText(`Step ${at + 1} of ${told.steps.length}`);
            await expect(stepText(page)).toContainText(step.says);
        }

        return told;
    };

    test('a press greets, and the picture shows why', async ({ page }) => {
        await open(page);
        await expect(stepText(page)).toContainText('This is the page as it opens.');
        await expect(scene(page).locator('.live-line')).toHaveCount(12);

        const field = scene(page).getByLabel('Your name');
        await field.fill('Mona');
        await scene(page).getByRole('button', { name: 'Greet' }).click();
        const next = scene(page).getByRole('button', { name: 'Next' });

        // Step 1: the form is on its way, and the browser still shows the old page.
        await expect(scene(page).locator('.live-lane.out li')).toHaveText(['your-name: "Mona"', '_click: greet', '_token: \u2026']);
        await expect(scene(page).locator('.live-callout')).toHaveCount(0);

        // Step 5: the line of the button is the one that runs, and both functions have returned.
        for (let i = 0; i < 4; i += 1) await next.click();
        await expect(scene(page).locator('.live-line.now')).toContainText("button('Greet')");
        await expect(scene(page).locator('.live-returns')).toHaveText(["returns 'Mona'", 'returns true']);
        await expect(scene(page).locator('.live-lane.back li')).toHaveText(['the title', 'the field', 'the button']);
        await expect(scene(page).locator('.live-callout')).toHaveCount(0);

        // Step 8: the new page is in the browser.
        for (let i = 0; i < 3; i += 1) await next.click();
        await expect(scene(page).locator('.live-callout')).toHaveText('Hello, Mona.');
        await expect(next).toBeDisabled();
        await expect(scene(page).getByRole('button', { name: 'Play again' })).toBeVisible();
        await expect(scene(page).locator('.live-hint')).toContainText('change the name and press Enter');
    });

    test('every step of a run says what the steps of the model say', async ({ page }) => {
        await open(page);

        const pressed = await walk(page, 'Ava', 'press');
        await expect(scene(page).locator('.live-callout')).toHaveText(pressed.page.greeting);

        // A changed name and Enter: no button was pressed, so the greeting goes.
        await walk(page, 'Omar', 'enter');
        await expect(scene(page).locator('.live-lane.out li')).toHaveText(['your-name: "Omar"', '_token: \u2026']);
        await expect(scene(page).locator('.live-returns')).toHaveText(["returns 'Omar'", 'returns false', 'does not run']);
        await expect(scene(page).locator('.live-line.skipped')).toContainText('success(');
        await expect(scene(page).locator('.live-callout')).toHaveCount(0);
        await expect(scene(page).locator('.live-hint')).toContainText('Press Greet');

        // Blanks around a name go, as text() of the library takes them away.
        await walk(page, '  Lea ', 'press');
        await expect(scene(page).getByLabel('Your name')).toHaveValue('Lea');
        await expect(scene(page).locator('.live-callout')).toHaveText('Hello, Lea.');
    });

    test('Back shows the old page again, and a run can be played again', async ({ page }) => {
        await open(page);
        await walk(page, 'Ava', 'press');

        await scene(page).getByRole('button', { name: 'Back' }).click();
        await expect(scene(page).locator('.live-count')).toHaveText('Step 7 of 8');
        await expect(scene(page).locator('.live-callout')).toHaveCount(0);

        await scene(page).getByRole('button', { name: 'Next' }).click();
        await expect(scene(page).locator('.live-callout')).toHaveText('Hello, Ava.');

        await scene(page).getByRole('button', { name: 'Play again' }).click();
        await expect(scene(page).locator('.live-count')).toHaveText('Step 1 of 8');
        await expect(scene(page).getByRole('button', { name: 'Pause' })).toBeVisible();
        await scene(page).getByRole('button', { name: 'Pause' }).click();
        await expect(scene(page).getByRole('button', { name: 'Play', exact: true })).toBeVisible();
    });

    test('the keyboard is enough', async ({ page }) => {
        await open(page);

        await scene(page).getByLabel('Your name').focus();
        await page.keyboard.type('Lea');
        await page.keyboard.press('Enter');
        await expect(scene(page).locator('.live-count')).toHaveText('Step 1 of 8');
        await expect(stepText(page)).toContainText('No button was pressed');

        // From the field, the tab key goes to Greet and then to the buttons of the steps.
        await page.keyboard.press('Tab');
        await expect(scene(page).getByRole('button', { name: 'Greet' })).toBeFocused();
        await page.keyboard.press('Space');
        await expect(stepText(page)).toContainText('You pressed Greet');

        await scene(page).getByRole('button', { name: 'Next' }).focus();
        await page.keyboard.press('Enter');
        await expect(scene(page).locator('.live-count')).toHaveText('Step 2 of 8');
    });

    test('a changed field reruns the script when the visitor leaves it', async ({ page }) => {
        await open(page);
        const field = scene(page).getByLabel('Your name');

        await field.fill('Lea');
        await field.blur();
        await expect(scene(page).locator('.live-count')).toHaveText('Step 1 of 8');
        await expect(stepText(page)).toContainText('You changed the field.');
        await expect(scene(page).locator('.live-lane.out li')).toHaveText(['your-name: "Lea"', '_token: \u2026']);

        // A field that was left as it is starts nothing.
        await scene(page).getByRole('button', { name: 'Pause' }).click();
        await field.focus();
        await field.blur();
        await expect(scene(page).getByRole('button', { name: 'Play', exact: true })).toBeVisible();
        await expect(scene(page).locator('.live-count')).toHaveText('Step 1 of 8');

        // A press on "Play" with the cursor in a changed field: leaving the field starts the run,
        // and the press does not pause it again.
        await field.fill('Mo');
        await scene(page).getByRole('button', { name: 'Play', exact: true }).click();
        await expect(scene(page).getByRole('button', { name: 'Pause' })).toBeVisible();
        await expect(scene(page).locator('.live-lane.out li')).toHaveText(['your-name: "Mo"', '_token: \u2026']);
    });

    test('the steps are read out only when the visitor asked for them', async ({ page }) => {
        await page.clock.install();
        await page.goto('/');
        await scene(page).locator('.live-window').scrollIntoViewIfNeeded();
        await expect(scene(page).getByRole('button', { name: 'Pause' })).toBeVisible();
        await page.clock.runFor(4_000);

        // The picture plays by itself. Nobody asked, so the part that is read out stays empty,
        // and the sentence to see can still be read by whoever goes there.
        const readOut = scene(page).locator('[aria-live="polite"]');
        await expect(scene(page).locator('.live-count')).toHaveText('Step 1 of 8');
        await expect(readOut).toHaveCount(1);
        await expect(readOut).toHaveText('');
        await expect(stepText(page)).not.toHaveAttribute('aria-hidden');

        // The visitor asks for the next step: it is read out, once.
        await scene(page).getByRole('button', { name: 'Next' }).click();
        await expect(readOut).toHaveText('Step 2 of 8. Script UI asks first: who is asking, may they open this tool, is the form ours?');
        await expect(stepText(page)).toHaveAttribute('aria-hidden', 'true');
    });

    test('whoever asked for less motion starts every run themselves', async ({ page }) => {
        await page.clock.install();
        await page.emulateMedia({ reducedMotion: 'reduce' });
        await page.goto('/');
        await expect(scene(page).getByRole('button', { name: 'Play', exact: true })).toBeVisible();
        await scene(page).locator('.live-window').scrollIntoViewIfNeeded();
        await page.clock.runFor(10_000);

        await expect(scene(page).getByLabel('Your name')).toHaveValue('');
        await expect(scene(page).locator('.live-count')).toHaveCount(0);
        await expect(scene(page).getByRole('button', { name: 'Play', exact: true })).toBeVisible();
    });

    test('it plays by itself once, when it comes into view', async ({ page }) => {
        await page.clock.install();
        await page.goto('/');
        await scene(page).locator('.live-window').scrollIntoViewIfNeeded();

        // The picture types the name itself and presses the button.
        await expect(scene(page).getByRole('button', { name: 'Pause' })).toBeVisible();
        await page.clock.runFor(2_500);
        await expect(scene(page).getByLabel('Your name')).toHaveValue('Ava');
        await expect(scene(page).locator('.live-count')).toHaveText('Step 1 of 8');

        await page.clock.runFor(40_000);
        await expect(scene(page).locator('.live-count')).toHaveText('Step 8 of 8');
        await expect(scene(page).locator('.live-callout')).toHaveText('Hello, Ava.');
        await expect(scene(page).getByRole('button', { name: 'Play again' })).toBeVisible();
    });

    test('whoever types first is not interrupted', async ({ page }) => {
        await page.clock.install();
        await page.goto('/');
        await expect(scene(page).getByRole('button', { name: 'Play', exact: true })).toBeVisible();

        // The field is filled before the picture is in view.
        await scene(page).getByLabel('Your name').evaluate((input) => {
            input.value = 'Mo';
            input.dispatchEvent(new Event('input', { bubbles: true }));
        });
        await scene(page).locator('.live-window').scrollIntoViewIfNeeded();
        await page.clock.runFor(10_000);

        await expect(scene(page).getByLabel('Your name')).toHaveValue('Mo');
        await expect(scene(page).locator('.live-count')).toHaveCount(0);
    });

    test('at the width of a phone nothing sticks out', async ({ page }) => {
        await page.setViewportSize(PHONE);
        await open(page);
        await walk(page, 'Ava', 'press');

        expect(await noHorizontalOverflow(page)).toBe(true);
        await expect(scene(page).locator('.live-callout')).toHaveText('Hello, Ava.');
    });
});

test.describe('the picture of a long task on the first page', () => {
    const scene = (page) => page.locator('.live-task');
    const row = (page) => scene(page).locator('.live-row dd');
    const number = (value) => String(value).replace(/\B(?=(\d{3})+(?!\d))/g, ',');

    // What the three parts must show for a state of the model.
    const agrees = async (page, state) => {
        const told = shows(state.page);
        await expect(row(page)).toHaveText([state.row.state, number(state.row.done), '2,400', state.row.stop ? 'asked' : 'no']);
        await expect(scene(page).locator('.live-page')).toContainText(told.text);
        await expect(scene(page).locator('.live-btn')).toHaveText(told.button);
        await expect(scene(page).locator('.live-hint b')).toHaveText(String(state.reads));
    };

    test.beforeEach(async ({ page }) => {
        // The clock of the page is in the hands of the test.
        await page.clock.install();
        await page.emulateMedia({ reducedMotion: 'reduce' });
        await page.goto('/');
        await expect(scene(page).getByRole('button', { name: 'Start the export' })).toBeEnabled();
    });

    test('Start hands the work over, and the page is behind the row', async ({ page }) => {
        let state = fresh();
        await agrees(page, state);

        await scene(page).getByRole('button', { name: 'Start the export' }).click();
        state = start(state);
        await agrees(page, state);
        await expect(scene(page).locator('.live-page')).toContainText('Waiting for a worker');

        await page.clock.runFor(3_900);
        state = tick(state, 3_900);
        expect(state.row.done, 'the row is ahead of the page').toBeGreaterThan(state.page.done);
        await agrees(page, state);
        await expect(scene(page).locator('.live-does').first()).toContainText('Writes orders');

        // The sentence is read out when it changes. The counter, which changes every two seconds, is not.
        const readOut = scene(page).locator('[aria-live="polite"]');
        await expect(readOut).toHaveText(/The worker writes into the row how far it is\./);
        await expect(readOut).not.toContainText('ran');
    });

    test('Stop is a wish that the worker finds, and the page finds out at its next look', async ({ page }) => {
        await scene(page).getByRole('button', { name: 'Start the export' }).click();
        await page.clock.runFor(5_300);
        let state = tick(start(fresh()), 5_300);

        await scene(page).getByRole('button', { name: 'Stop the export' }).click();
        state = stop(state);
        expect(state.row).toMatchObject({ state: 'running', stop: true });
        await agrees(page, state);

        await page.clock.runFor(600);
        state = tick(state, 600);
        expect(state.row.state).toBe('stopped');
        expect(state.page.state).toBe('running');
        await agrees(page, state);

        await page.clock.runFor(2_000);
        state = tick(state, 2_000);
        await agrees(page, state);
        await expect(scene(page).locator('.live-callout')).toContainText('The export was stopped at');
        await expect(scene(page).getByRole('button', { name: 'Start a new export' })).toBeVisible();
    });

    test('the task runs to its end, and then the page stands still', async ({ page }) => {
        await scene(page).getByRole('button', { name: 'Start the export' }).click();
        await page.clock.runFor(30_000);
        const state = tick(start(fresh()), 30_000);

        expect(state.row.state).toBe('finished');
        await agrees(page, state);
        await expect(scene(page).locator('.live-callout')).toHaveText('2,400 orders are exported.');

        // Nothing looks at the row any more.
        await page.clock.runFor(10_000);
        await agrees(page, state);
    });

    test('at the width of a phone nothing sticks out', async ({ page }) => {
        await page.setViewportSize(PHONE);
        await scene(page).getByRole('button', { name: 'Start the export' }).click();
        await page.clock.runFor(4_500);

        expect(await noHorizontalOverflow(page)).toBe(true);
        await expect(scene(page).locator('.live-page')).toContainText('of 2,400 orders');
    });
});

test.describe('the first page in other settings', () => {
    test('the small pages follow the dark theme', async ({ browser }) => {
        const look = async (colorScheme) => {
            const context = await browser.newContext({ colorScheme, reducedMotion: 'reduce' });
            const page = await context.newPage();
            await page.goto('/');
            const colour = await page.locator('.live-click .live-window').evaluate((el) => getComputedStyle(el).backgroundColor);
            await context.close();

            return colour;
        };

        expect(await look('light')).toBe('rgb(249, 249, 249)');
        expect(await look('dark')).toBe('rgb(11, 11, 11)');
    });

    test('without JavaScript the script and a way to the drawings are there', async ({ browser }) => {
        const context = await browser.newContext({ javaScriptEnabled: false });
        const page = await context.newPage();
        await page.goto('/');

        await expect(page.locator('.live-click .live-line')).toHaveCount(12);
        await expect(page.locator('.live-click .live-src').nth(7)).toHaveText("$name = text('Your name');");
        await expect(page.locator('.live-click').getByLabel('Your name')).toBeDisabled();
        await expect(page.locator('.live-click .live-controls')).toHaveCount(0);
        // The note stands in a <noscript>, which shows only now. It leads to the drawing.
        const steps = run({ name: 'Ava', pressed: true }).steps;
        await expect(page.locator('.live-click .live-noscript')).toBeVisible();
        await expect(page.locator('.live-click .live-noscript li')).toHaveText(steps.map((step) => step.says));
        await expect(page.locator('.live-click .live-noscript a')).toHaveAttribute('href', '/guide/how-it-works.html');
        await expect(page.locator('.live-task').getByRole('button', { name: 'Start the export' })).toBeDisabled();
        await expect(page.locator('.live-task .live-noscript a')).toHaveAttribute('href', '/tutorials/long-task.html');
        await context.close();
    });
});
