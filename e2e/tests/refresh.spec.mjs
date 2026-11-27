import { test, expect } from '@playwright/test';
import { PHONE, markDocument, noHorizontalOverflow, sameDocument, watch } from './support.mjs';
import { posts } from './reports.mjs';

// The page of these tests is e2e/tools/test-refresh.php. It runs again by itself every 5 seconds,
// and it stops when nobody was there for 60 seconds. A line on it says which run made it.
//
// The clock of the page stands still in every test: time passes when the test says so. What
// travels to the server and back takes the time it really takes.
const START = new Date('2026-03-02T09:00:00Z');

const line = (page) => page.locator('#sui-body .sui-refresh');
const pause = (page) => line(page).locator('button');
const paused = (page) => line(page).locator('[role="status"]');
const note = (page) => page.locator('#sui-body > .sui-note');
const owner = (page) => page.getByLabel('Owner');
const rate = (page) => page.getByLabel('Rate');
const retry = (page) => page.getByRole('button', { name: 'Retry' });
const serverAway = (page) => page.locator('#sui-offline');

/** A place on the page where nothing is: next to the column with the content. */
const ASIDE = [1200, 600];

async function open(page) {
    await page.clock.install({ time: new Date(START.getTime() - 10_000) });
    await page.clock.pauseAt(START);
    await page.goto('/test-refresh');

    return posts(page);
}

/** The number of the run that made the page. */
const runOf = async (page) => Number(/Run (\d+)\./.exec(await note(page).textContent())[1]);

/** The page that this run of the tool made is the one on the screen. */
const says = (page, run, who = 'nobody') => expect(note(page)).toHaveText(`Run ${run}. Owner: ${who}.`);

/** The runs that the page started by itself. */
const own = (sent) => sent.runs.filter((request) => 'refresh' === request.headers()['x-script-ui']);

/** Gives a run that should not start the time to show up, and the page the time to take in an answer. */
async function still(page, sent, count, why = 'the page started no run by itself') {
    await page.waitForTimeout(150);
    expect(own(sent).length, why).toBe(count);
}

/** Holds the answers to the page's own runs back, until the test opens the gate. */
async function gate(page) {
    const held = [];
    let shut = true;
    await page.route('**/test-refresh', async (route) => {
        if (shut && 'refresh' === route.request().headers()['x-script-ui']) {
            await new Promise((resolve) => { held.push(resolve); });
        }
        await route.continue();
    });

    return {
        /** Lets the answers through, and waits until the page has the one to this request. */
        async open(request) {
            shut = false;
            held.splice(0).forEach((resolve) => resolve());
            await (await request.response()).finished();
        },
    };
}

/** The answer of a tool that broke, as the server sends it to the page script. */
const BROKEN = {
    status: 500,
    contentType: 'text/html; charset=utf-8',
    headers: { 'X-Script-Ui-Fragment': '1' },
    body: '<p class="sui-note">Broken.</p>',
};

/** Takes the problems off the list that a test made happen on purpose. */
const forgive = (page, expected) => {
    page.problems = page.problems.filter((problem) => !expected.test(problem));
};

/** The page goes out of sight (another tab is looked at) or comes back. */
const lookAway = (page, away) => page.evaluate((hidden) => {
    Object.defineProperty(document, 'hidden', { configurable: true, get: () => hidden });
    Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => (hidden ? 'hidden' : 'visible') });
    document.dispatchEvent(new Event('visibilitychange'));
}, away);

/** A press on a field, as the page script hears it. A real one would open a list that no test can close. */
const press = (field) => field.evaluate((element) => {
    element.dispatchEvent(new PointerEvent('pointerdown', { bubbles: true }));
    element.dispatchEvent(new PointerEvent('pointerup', { bubbles: true }));
});

/** A key in a field, as the page script hears it. */
const key = (field, name) => field.evaluate((element, pressed) => {
    element.dispatchEvent(new KeyboardEvent('keydown', { key: pressed, bubbles: true }));
    element.dispatchEvent(new KeyboardEvent('keyup', { key: pressed, bubbles: true }));
}, name);

/** Marks the text of an element, as a user does to copy it. */
const mark = (locator) => locator.evaluate((element) => { document.getSelection().selectAllChildren(element); });

/** A file that is larger than its field takes. The page script turns it down and says so. */
const chooseLargeFile = (page) => page.locator('input[type="file"]')
    .setInputFiles({ name: 'large.csv', mimeType: 'text/csv', buffer: Buffer.alloc(2048, 'a') });
const complaint = (page) => page.getByText('"large.csv" is larger than 1 KB.');

test.beforeEach(({ page }) => {
    watch(page);
});

test.afterEach(({ page }) => {
    expect(page.problems, 'script errors, blocked content and server errors').toEqual([]);
});

test('the page runs again by itself when the wait is over, and then waits anew', async ({ page }) => {
    const sent = await open(page);
    await markDocument(page);
    const first = await runOf(page);
    await expect(pause(page)).toHaveText('Pause');
    await expect(paused(page)).toHaveText('');
    await owner(page).focus();

    await page.clock.runFor(4_900);
    await still(page, sent, 0, 'not before the five seconds are over');

    await page.clock.runFor(100);
    await says(page, first + 1);
    expect(sent.runs.length, 'one request, and the page started it').toBe(1);
    expect(own(sent).length).toBe(1);

    // It is a run like any other, with the whole form. But it presses nothing.
    const fields = new URLSearchParams(own(sent)[0].postData());
    expect(fields.get('_token')).toBeTruthy();
    expect(fields.get('rate')).toBe('5 seconds');
    expect(fields.has('_click')).toBe(false);

    // The page was changed in place, and the user is where they were.
    expect(await sameDocument(page)).toBe(true);
    await expect(owner(page)).toBeFocused();
    await expect(pause(page)).toHaveText('Pause');

    // The next wait starts with the answer.
    await page.clock.runFor(4_900);
    await still(page, sent, 1);
    await page.clock.runFor(100);
    await says(page, first + 2);
});

test.describe('for a reader in another time zone', () => {
    test.use({ timezoneId: 'Asia/Tokyo' });

    test('the line says when the page was made, on the clock of the reader', async ({ page }) => {
        await open(page);

        const time = line(page).locator('time');
        const made = new Date(await time.getAttribute('datetime'));
        const there = new Intl.DateTimeFormat('en-GB', {
            timeZone: 'Asia/Tokyo', hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23',
        }).format(made);

        await expect(time).toHaveText(there);
        await expect(line(page).locator('.sui-refresh-time')).toHaveText(`Updated ${there}`);
    });
});

test('it does not run while nobody can see the page, and catches up when the page is back', async ({ page }) => {
    const sent = await open(page);
    const first = await runOf(page);

    await lookAway(page, true);
    await page.clock.runFor(30_000);
    await still(page, sent, 0);

    await lookAway(page, false);
    await says(page, first + 1);
    expect(own(sent).length).toBe(1);

    // A page that was out of sight for a moment goes on as planned.
    await page.clock.runFor(1_000);
    await lookAway(page, true);
    await page.clock.runFor(1_000);
    await lookAway(page, false);
    await still(page, sent, 1, 'nothing was due yet');
    await page.clock.runFor(3_000);
    await says(page, first + 2);
});

test('it does not run while typed text waits to be sent', async ({ page }) => {
    const sent = await open(page);
    const first = await runOf(page);

    await owner(page).fill('Av');
    await page.clock.runFor(30_000);
    await still(page, sent, 0);
    expect(sent.runs.length, 'the half word is not sent either').toBe(0);
    await expect(owner(page)).toHaveValue('Av');

    // Leaving the field sends the text. That is a run of the user, and the wait starts anew.
    await owner(page).fill('Ava');
    await owner(page).press('Tab');
    await says(page, first + 1, 'Ava');
    expect(own(sent).length).toBe(0);

    await page.clock.runFor(4_900);
    await still(page, sent, 0);
    await page.clock.runFor(100);
    await says(page, first + 2, 'Ava');
});

test('it does not run while a question waits for its answer', async ({ page }) => {
    const sent = await open(page);
    const first = await runOf(page);

    await page.getByRole('button', { name: 'Delete' }).click();
    const question = page.locator('.sui-confirm');
    await expect(question).toContainText('Delete everything?');
    await says(page, first + 1);

    await page.clock.runFor(30_000);
    await still(page, sent, 0, 'the question is not taken away');
    await expect(question).toBeVisible();

    await question.getByRole('button', { name: 'Cancel' }).click();
    await expect(question).toBeHidden();
    await says(page, first + 2);
    await page.clock.runFor(5_000);
    await says(page, first + 3);
    expect(own(sent).length).toBe(1);
});

for (const [what, act] of [
    ['moves the mouse', (page) => page.mouse.move(ASIDE[0] - 40, ASIDE[1])],
    ['presses a key', (page) => page.keyboard.press('Shift')],
    ['turns the wheel', (page) => page.mouse.wheel(0, 40)],
    ['presses a mouse button', async (page) => { await page.mouse.down(); await page.mouse.up(); }],
]) {
    test(`it waits for a hand that is busy: somebody ${what}`, async ({ page }) => {
        const sent = await open(page);
        const first = await runOf(page);
        await page.mouse.move(...ASIDE);

        await page.clock.runFor(4_000);
        await act(page);
        // The turn of a wheel reaches the page a moment after it was made.
        await page.waitForTimeout(100);
        await page.clock.runFor(1_900);
        await still(page, sent, 0, 'two seconds of rest come first');

        await page.clock.runFor(600);
        await says(page, first + 1);
    });
}

test('it waits while a mouse button is held down, and while a character is being put together', async ({ page }) => {
    const sent = await open(page);
    const first = await runOf(page);

    await page.mouse.move(...ASIDE);
    await page.mouse.down();
    await page.clock.runFor(10_000);
    await still(page, sent, 0, 'a press is not over until the button comes up');
    await page.mouse.up();
    await page.clock.runFor(600);
    await says(page, first + 1);

    // Some keyboards put a character together from several keys.
    await owner(page).dispatchEvent('compositionstart');
    await page.clock.runFor(10_000);
    await still(page, sent, 1);
    await owner(page).dispatchEvent('compositionend');
    await page.clock.runFor(600);
    await says(page, first + 2);
});

test('an answer that comes in under a busy hand waits until the hand rests', async ({ page }) => {
    const sent = await open(page);
    const first = await runOf(page);
    const answers = await gate(page);

    await page.clock.runFor(5_000);
    await expect.poll(() => own(sent).length).toBe(1);
    // Nobody asked for this run, so the page does not look busy: no bar, and nothing that is read out.
    await page.clock.runFor(200);
    await expect(page.locator('#sui-body')).not.toHaveAttribute('aria-busy', 'true');
    await expect(page.locator('html')).not.toHaveClass(/sui-busy/);

    await page.mouse.move(...ASIDE);
    await answers.open(own(sent)[0]);
    await still(page, sent, 1);
    await says(page, first);

    await page.clock.runFor(1_900);
    await still(page, sent, 1);
    await says(page, first);
    await page.clock.runFor(350);
    await says(page, first + 1);

    // The wait for the next run starts when the page was changed.
    await page.clock.runFor(4_600);
    await still(page, sent, 1);
    await page.clock.runFor(200);
    await says(page, first + 2);
});

test('a run of the user drops an answer that waits: theirs is the newer one', async ({ page }) => {
    const sent = await open(page);
    const first = await runOf(page);
    const answers = await gate(page);

    await page.clock.runFor(5_000);
    await expect.poll(() => own(sent).length).toBe(1);
    // The user starts to type while the answer is on its way. It is not put in over their text.
    await owner(page).fill('Av');
    await answers.open(own(sent)[0]);
    await still(page, sent, 1);
    await says(page, first);
    await expect(owner(page)).toHaveValue('Av');

    await owner(page).fill('Ava');
    await owner(page).press('Tab');
    await says(page, first + 2, 'Ava');
    await page.clock.runFor(3_000);
    await still(page, sent, 1);
    await says(page, first + 2, 'Ava');
});

test('it does not close a list of options under the user, for a minute at most', async ({ page }) => {
    const sent = await open(page);
    const first = await runOf(page);
    // This rate never stops by itself, so the whole minute can pass.
    await rate(page).selectOption('for ever');
    await says(page, first + 1);

    await press(rate(page));
    await page.clock.runFor(59_000);
    await still(page, sent, 0, 'the list stays open');
    await page.clock.runFor(1_500);
    await says(page, first + 2);

    // The list is closed now. A hand that comes back finds a page that goes on as before.
    await page.mouse.move(...ASIDE);
    await page.clock.runFor(5_000);
    await says(page, first + 3);
});

test('a choice from the list ends the waiting for it', async ({ page }) => {
    const sent = await open(page);
    const first = await runOf(page);

    await press(rate(page));
    await rate(page).selectOption('for ever');
    await says(page, first + 1);
    await page.clock.runFor(5_000);
    await says(page, first + 2);
    expect(own(sent).length).toBe(1);
});

test('a list that the keyboard opened is left alone too, until a key closes it', async ({ page }) => {
    const sent = await open(page);
    const first = await runOf(page);

    await rate(page).focus();
    await key(rate(page), ' ');
    await page.clock.runFor(30_000);
    await still(page, sent, 0);

    await key(rate(page), 'Escape');
    await page.clock.runFor(1_900);
    await still(page, sent, 0, 'the key is a touch like any other');
    await page.clock.runFor(600);
    await says(page, first + 1);
});

test('a calendar is left alone as well, until a press somewhere else closes it', async ({ page }) => {
    const sent = await open(page);
    const first = await runOf(page);

    await press(page.getByLabel('Day'));
    await page.clock.runFor(30_000);
    await still(page, sent, 0);

    await page.mouse.move(...ASIDE);
    await page.mouse.down();
    await page.mouse.up();
    await page.clock.runFor(1_900);
    await still(page, sent, 0);
    await page.clock.runFor(600);
    await says(page, first + 1);
});

test('it does not take text away that is marked, to be copied for example', async ({ page }) => {
    const sent = await open(page);
    const first = await runOf(page);
    await rate(page).selectOption('for ever');
    await says(page, first + 1);

    await mark(note(page));
    await page.clock.runFor(59_000);
    await still(page, sent, 0);
    expect(await page.evaluate(() => String(document.getSelection()))).toContain(`Run ${first + 1}.`);

    // Text that was marked a minute ago is given up.
    await page.clock.runFor(1_500);
    await says(page, first + 2);
});

test('text that is marked outside the content holds nothing back', async ({ page }) => {
    await open(page);
    const first = await runOf(page);

    await mark(page.locator('.sui-top'));
    await page.clock.runFor(5_000);
    await says(page, first + 1);
});

test('"Pause" stops it, and "Resume" runs the page at once and lets it go on', async ({ page }) => {
    const sent = await open(page);
    const first = await runOf(page);

    await pause(page).focus();
    await page.keyboard.press('Enter');
    await expect(pause(page)).toHaveText('Resume');
    await expect(paused(page)).toHaveText('Paused');
    await page.clock.runFor(120_000);
    await still(page, sent, 0);
    expect(sent.runs.length).toBe(0);
    await says(page, first);

    await page.keyboard.press('Enter');
    await says(page, first + 1);
    expect(own(sent).length, 'this run the user asked for').toBe(0);
    await expect(pause(page)).toHaveText('Pause');
    await expect(paused(page)).toHaveText('');
    await expect(pause(page), 'the button keeps the focus').toBeFocused();

    await page.clock.runFor(5_000);
    await says(page, first + 2);
    expect(own(sent).length).toBe(1);
});

test('a page that was paused stays paused when the user changes something', async ({ page }) => {
    const sent = await open(page);
    const first = await runOf(page);

    await pause(page).click();
    await owner(page).fill('Ava');
    await owner(page).press('Tab');
    await says(page, first + 1, 'Ava');
    await expect(pause(page)).toHaveText('Resume');
    await expect(paused(page)).toHaveText('Paused');

    await page.clock.runFor(30_000);
    await still(page, sent, 0);
});

test('"Pause" also keeps an answer out that was on its way', async ({ page }) => {
    const sent = await open(page);
    const first = await runOf(page);
    const answers = await gate(page);

    await page.clock.runFor(5_000);
    await expect.poll(() => own(sent).length).toBe(1);
    await pause(page).click();
    await answers.open(own(sent)[0]);
    await still(page, sent, 1);

    await page.clock.runFor(3_000);
    await still(page, sent, 1);
    await says(page, first);
    await expect(paused(page)).toHaveText('Paused');
});

test('and an answer that had arrived already, and waited for the hand to rest', async ({ page }) => {
    const sent = await open(page);
    const first = await runOf(page);
    const answers = await gate(page);

    await page.clock.runFor(5_000);
    await expect.poll(() => own(sent).length).toBe(1);
    await page.mouse.move(...ASIDE);
    await answers.open(own(sent)[0]);
    await still(page, sent, 1);
    await says(page, first);

    await pause(page).click();
    await page.clock.runFor(3_000);
    await still(page, sent, 1);
    await says(page, first);
});

test('it stops by itself when nobody was there for a minute, and says so', async ({ page }) => {
    const sent = await open(page);
    const first = await runOf(page);

    for (let n = 1; n <= 11; n++) {
        await page.clock.runFor(5_000);
        await says(page, first + n);
    }

    await page.clock.runFor(5_000);
    await expect(paused(page)).toHaveText('Paused');
    await expect(pause(page)).toHaveText('Resume');
    await page.clock.runFor(60_000);
    await still(page, sent, 11);
    await says(page, first + 11);

    await pause(page).click();
    await says(page, first + 12);
    await expect(pause(page)).toHaveText('Pause');
    await expect(paused(page)).toHaveText('');
    await page.clock.runFor(5_000);
    await says(page, first + 13);
});

test('somebody who is there keeps it going', async ({ page }) => {
    const sent = await open(page);
    const first = await runOf(page);

    for (let n = 1; n <= 11; n++) {
        await page.clock.runFor(5_000);
        await says(page, first + n);
    }

    // A hand on the mouse, five seconds before the page would have stopped.
    await page.mouse.move(...ASIDE);
    await page.clock.runFor(5_000);
    await says(page, first + 12);
    await expect(paused(page)).toHaveText('');

    // Coming back to the page counts as well: it runs at once, and goes on.
    await lookAway(page, true);
    await page.clock.runFor(120_000);
    await still(page, sent, 12);
    await lookAway(page, false);
    await says(page, first + 13);
    await expect(paused(page)).toHaveText('');
});

test('a change that comes without a touch says that somebody is there, too', async ({ page }) => {
    await open(page);
    const first = await runOf(page);

    for (let n = 1; n <= 11; n++) {
        await page.clock.runFor(5_000);
        await says(page, first + n);
    }

    // Software that reads the page to its user fills in a field for them: no mouse, no key.
    await owner(page).fill('Ava');
    await owner(page).evaluate((field) => field.blur());
    await says(page, first + 12, 'Ava');

    await page.clock.runFor(5_000);
    await says(page, first + 13, 'Ava');
    await expect(paused(page)).toHaveText('');
});

test('a page that is told to go on for ever does', async ({ page }) => {
    await open(page);
    const first = await runOf(page);
    await rate(page).selectOption('for ever');
    await says(page, first + 1);

    for (let n = 2; n <= 15; n++) {
        await page.clock.runFor(5_000);
        await says(page, first + n);
    }
    await expect(paused(page)).toHaveText('');
    await expect(pause(page)).toHaveText('Pause');
});

test('what a button said stays until the user is back and had the time to read it', async ({ page }) => {
    const sent = await open(page);
    const first = await runOf(page);

    await retry(page).click();
    const news = page.getByText(`Retried in run ${first + 1}.`);
    await expect(news).toBeVisible();

    await page.clock.runFor(40_000);
    await still(page, sent, 0, 'nobody is there to read it');
    await expect(news).toBeVisible();

    // The user is back. They get ten seconds, although the page asks for five.
    // The ten seconds count from their first touch, not from every one.
    await page.mouse.move(...ASIDE);
    await page.clock.runFor(5_000);
    await page.mouse.move(ASIDE[0] - 40, ASIDE[1]);
    await page.clock.runFor(4_900);
    await still(page, sent, 0);
    await expect(news).toBeVisible();
    await page.clock.runFor(100);
    await says(page, first + 2);
    await expect(news).toBeHidden();

    // From here on the page runs at its own rate again.
    await page.clock.runFor(5_000);
    await says(page, first + 3);
});

test('news that the user has seen does not hold the page back a second time', async ({ page }) => {
    const sent = await open(page);
    const first = await runOf(page);

    await retry(page).click();
    await expect(page.getByText(`Retried in run ${first + 1}.`)).toBeVisible();
    await page.mouse.move(...ASIDE);

    // The server is away when the page asks again. It tries once more, and nobody has to touch it for that.
    await page.route('**/test-refresh', (route) => route.abort());
    await page.clock.runFor(10_000);
    await expect(serverAway(page)).toBeVisible();
    expect(own(sent).length).toBe(1);

    await page.clock.runFor(10_000);
    await expect.poll(() => own(sent).length).toBe(2);
    forgive(page, /ERR_FAILED|Failed to load resource/);
});

test('news that is gone holds nothing back', async ({ page }) => {
    await open(page);
    const first = await runOf(page);

    await retry(page).click();
    await expect(page.getByText(`Retried in run ${first + 1}.`)).toBeVisible();

    // The user goes on without a touch: software that reads the page to them changes a field.
    await rate(page).selectOption('for ever');
    await says(page, first + 2);

    // A touch now is a touch like any other. It does not start ten seconds of reading.
    await page.clock.runFor(3_000);
    await page.mouse.move(...ASIDE);
    await page.clock.runFor(2_100);
    await says(page, first + 3);
});

test('a run of the user that breaks is news too: the page waits for them', async ({ page }) => {
    const sent = await open(page);
    const first = await runOf(page);
    await page.route('**/test-refresh', (route) => ('fragment' === route.request().headers()['x-script-ui']
        ? route.fulfill(BROKEN)
        : route.continue()));

    await retry(page).click();
    await expect(page.getByText('Broken.')).toBeVisible();
    await page.clock.runFor(40_000);
    await still(page, sent, 0, 'what went wrong is still there to be read');

    await page.mouse.move(...ASIDE);
    await page.clock.runFor(9_900);
    await still(page, sent, 0);
    await page.clock.runFor(100);
    await says(page, first + 1);
    forgive(page, /HTTP 500|Failed to load resource/);
});

test('when the server is away it tries again, and waits longer every time', async ({ page }) => {
    const sent = await open(page);
    const first = await runOf(page);
    await rate(page).selectOption('for ever');
    await says(page, first + 1);

    let away = true;
    await page.route('**/test-refresh', (route) => (away ? route.abort() : route.continue()));

    await page.clock.runFor(5_000);
    await expect(serverAway(page)).toBeVisible();
    await says(page, first + 1);

    // Twice the wait after every failure, and five minutes at most.
    let tries = 1;
    for (const seconds of [10, 20, 40, 80, 160, 300, 300]) {
        await page.clock.runFor(seconds * 1_000 - 100);
        await still(page, sent, tries, `not before ${seconds} seconds are over`);
        await page.clock.runFor(100);
        tries += 1;
        await expect.poll(() => own(sent).length).toBe(tries);
        await expect.poll(() => own(sent)[tries - 1].failure(), 'the page hears that nobody answered').not.toBeNull();
        await page.waitForTimeout(100);
    }

    // The server is back. The page shows what is new, and goes on at its own rate.
    away = false;
    await page.clock.runFor(300_000);
    await says(page, first + 2);
    await expect(serverAway(page)).toHaveCount(0);
    await page.clock.runFor(5_000);
    await says(page, first + 3);
    forgive(page, /ERR_FAILED|Failed to load resource/);
});

test('a failure never makes a slow page ask sooner', async ({ page }) => {
    const sent = await open(page);
    const first = await runOf(page);
    await rate(page).selectOption('10 minutes');
    await says(page, first + 1);
    await page.route('**/test-refresh', (route) => route.abort());

    await page.clock.runFor(600_000);
    await expect(serverAway(page)).toBeVisible();
    expect(own(sent).length).toBe(1);

    // Failures add up to five minutes of waiting at most. This page asked for ten.
    await page.clock.runFor(599_900);
    await still(page, sent, 1);
    await page.clock.runFor(100);
    await expect.poll(() => own(sent).length).toBe(2);
    forgive(page, /ERR_FAILED|Failed to load resource/);
});

test('a run of its own that breaks is tried again, although the broken page asks for nothing', async ({ page }) => {
    const sent = await open(page);
    const first = await runOf(page);
    let broken = true;
    await page.route('**/test-refresh', (route) => (broken && 'refresh' === route.request().headers()['x-script-ui']
        ? route.fulfill(BROKEN)
        : route.continue()));

    await page.clock.runFor(5_000);
    await expect(page.getByText('Broken.')).toBeVisible();
    await expect(line(page)).toHaveCount(0);

    broken = false;
    await page.clock.runFor(9_900);
    await still(page, sent, 1);
    await page.clock.runFor(100);
    await says(page, first + 1);
    await expect(pause(page)).toHaveText('Pause');
    forgive(page, /HTTP 500|Failed to load resource/);
});

test('a server in front of the tool that is in trouble does not end it', async ({ page }) => {
    const sent = await open(page);
    await markDocument(page);
    const first = await runOf(page);
    // Not the tool answers, but a server that stands in front of it: the page has no word of the tool in it.
    let trouble = true;
    await page.route('**/test-refresh', (route) => (trouble && 'refresh' === route.request().headers()['x-script-ui']
        ? route.fulfill({ status: 502, contentType: 'text/html', body: '<h1>502 Bad Gateway</h1>' })
        : route.continue()));

    await page.clock.runFor(5_000);
    await expect(serverAway(page)).toBeVisible();
    // Loaded anew, the page would be the message of that server, with nothing on it that tries again.
    expect(await sameDocument(page), 'the page is not loaded anew').toBe(true);
    await says(page, first);

    trouble = false;
    await page.clock.runFor(9_900);
    await still(page, sent, 1);
    await page.clock.runFor(100);
    await says(page, first + 1);
    await expect(serverAway(page)).toHaveCount(0);
    forgive(page, /HTTP 502|Failed to load resource/);
});

test('an answer that is kept out still says that the server is back', async ({ page }) => {
    const sent = await open(page);
    const first = await runOf(page);
    let away = true;
    let release = null;
    await page.route('**/test-refresh', async (route) => {
        if (away) {
            await route.abort();
            return;
        }
        await new Promise((resolve) => { release = resolve; });
        await route.continue();
    });

    await page.clock.runFor(5_000);
    await expect(serverAway(page)).toBeVisible();

    // The server is back. Its answer is on its way when the user stops the page.
    away = false;
    await page.clock.runFor(10_000);
    await expect.poll(() => own(sent).length).toBe(2);
    await expect.poll(() => release).not.toBeNull();
    await pause(page).click();
    release();

    await expect(serverAway(page)).toHaveCount(0);
    await says(page, first);
    await expect(paused(page)).toHaveText('Paused');
    forgive(page, /ERR_FAILED|Failed to load resource/);
});

test('an answer that is kept out and comes from a server in trouble changes nothing', async ({ page }) => {
    const sent = await open(page);
    const first = await runOf(page);
    let away = true;
    let release = null;
    await page.route('**/test-refresh', async (route) => {
        if (away) {
            await route.abort();
            return;
        }
        await new Promise((resolve) => { release = resolve; });
        await route.fulfill({ status: 503, contentType: 'text/html', body: '<h1>503 Service Unavailable</h1>' });
    });

    await page.clock.runFor(5_000);
    await expect(serverAway(page)).toBeVisible();

    away = false;
    await page.clock.runFor(10_000);
    await expect.poll(() => own(sent).length).toBe(2);
    await expect.poll(() => release).not.toBeNull();
    await pause(page).click();
    release();
    await (await own(sent)[1].response()).finished();
    await still(page, sent, 2);

    await expect(serverAway(page), 'nobody said that the server is back').toBeVisible();
    await says(page, first);
    forgive(page, /ERR_FAILED|HTTP 503|Failed to load resource/);
});

test('when the session ran out, the page is loaded anew and says what happened', async ({ page }) => {
    await open(page);
    await markDocument(page);
    const first = await runOf(page);
    // The answer is a page of its own, not the content of the tool: "the page has expired", or a page to log in on.
    await page.route('**/test-refresh', (route) => ('refresh' === route.request().headers()['x-script-ui']
        ? route.fulfill({ status: 419, contentType: 'text/html', body: '<h1>The page has expired</h1>' })
        : route.continue()));

    const loaded = page.waitForEvent('load', { timeout: 5_000 });
    await page.clock.runFor(5_000);
    await loaded;
    // Loaded anew, this page comes from the server again. A real one would now say that it expired.
    expect(await sameDocument(page)).toBe(false);
    await says(page, first + 1);
    forgive(page, /419|Failed to load resource/);
});

test('a run of the user that meets a server in trouble still loads the page anew', async ({ page }) => {
    await open(page);
    await markDocument(page);
    const first = await runOf(page);
    // Somebody is there to read what that server has to say, and to try again.
    let trouble = true;
    await page.route('**/test-refresh', (route) => (trouble && 'POST' === route.request().method()
        ? route.fulfill({ status: 502, contentType: 'text/html', body: '<h1>502 Bad Gateway</h1>' })
        : route.continue()));

    const loaded = page.waitForEvent('load', { timeout: 5_000 });
    await rate(page).selectOption('for ever');
    await loaded;
    expect(await sameDocument(page)).toBe(false);
    await says(page, first + 1);
    forgive(page, /HTTP 502|Failed to load resource/);
});

test('a run that was missed is not made up for when news came in since', async ({ page }) => {
    const sent = await open(page);
    const first = await runOf(page);

    // The wait ends while the page is out of sight.
    await lookAway(page, true);
    await page.clock.runFor(5_000);
    await still(page, sent, 0);

    // A press that was made just before is answered now, and the answer is news.
    await retry(page).click();
    await expect(page.getByText(`Retried in run ${first + 1}.`)).toBeVisible();

    await lookAway(page, false);
    await page.clock.runFor(30_000);
    await still(page, sent, 0, 'the news is read first');
});

test('a page that no longer asks for it stops, and one that asks again starts', async ({ page }) => {
    const sent = await open(page);
    const first = await runOf(page);

    await rate(page).selectOption('off');
    await says(page, first + 1);
    await expect(line(page)).toHaveCount(0);
    await page.clock.runFor(60_000);
    await still(page, sent, 0);

    await page.mouse.move(...ASIDE);
    await rate(page).selectOption('5 seconds');
    await says(page, first + 2);
    await expect(pause(page)).toHaveText('Pause');
    await page.clock.runFor(5_000);
    await says(page, first + 3);
});

test('a run of its own carries no file', async ({ page }) => {
    const sent = await open(page);
    const first = await runOf(page);

    // A file is in its field without having been sent: the browser put it back, for example.
    await page.locator('input[type="file"]').evaluate((input) => {
        const files = new DataTransfer();
        files.items.add(new File(['a,b\n1,2\n'], 'report.csv', { type: 'text/csv' }));
        input.files = files.files;
    });

    await page.clock.runFor(5_000);
    await says(page, first + 1);
    const request = own(sent)[0];
    expect(request.headers()['content-type']).toContain('application/x-www-form-urlencoded');
    expect(request.postData()).not.toContain('report');
});

test('a file that the page turned down is news as well', async ({ page }) => {
    const sent = await open(page);
    const first = await runOf(page);

    await page.clock.runFor(1_000);
    await chooseLargeFile(page);
    await expect(complaint(page)).toBeVisible();
    await page.clock.runFor(40_000);
    await still(page, sent, 0);
    await expect(complaint(page)).toBeVisible();

    await page.mouse.move(...ASIDE);
    await page.clock.runFor(10_000);
    await says(page, first + 1);
    await expect(complaint(page)).toBeHidden();
});

test('an answer that is on its way does not take that news away either', async ({ page }) => {
    const sent = await open(page);
    const first = await runOf(page);
    const answers = await gate(page);

    await page.clock.runFor(5_000);
    await expect.poll(() => own(sent).length).toBe(1);
    await chooseLargeFile(page);
    await expect(complaint(page)).toBeVisible();
    await answers.open(own(sent)[0]);
    await still(page, sent, 1);

    await page.clock.runFor(40_000);
    await still(page, sent, 1);
    await expect(complaint(page)).toBeVisible();
    await says(page, first);
});

test('nor does an answer that waited already for the hand to rest', async ({ page }) => {
    const sent = await open(page);
    const first = await runOf(page);
    const answers = await gate(page);

    await page.clock.runFor(5_000);
    await expect.poll(() => own(sent).length).toBe(1);
    await page.mouse.move(...ASIDE);
    await answers.open(own(sent)[0]);
    await still(page, sent, 1);

    await chooseLargeFile(page);
    await expect(complaint(page)).toBeVisible();
    await page.clock.runFor(3_000);
    await expect(complaint(page)).toBeVisible();
    await says(page, first);
});

test('a run of its own never starts while a run of the user is on its way', async ({ page }) => {
    const sent = await open(page);
    const first = await runOf(page);

    // The page has news and waits for a touch.
    await retry(page).click();
    await expect(page.getByText(`Retried in run ${first + 1}.`)).toBeVisible();

    // The answers to the user's runs take their time from here on.
    let release = () => {};
    await page.route('**/test-refresh', async (route) => {
        if ('fragment' === route.request().headers()['x-script-ui']) await new Promise((resolve) => { release = resolve; });
        await route.continue();
    });
    await rate(page).selectOption('for ever');
    await expect.poll(() => sent.runs.length).toBe(2);

    // The hand comes back while that run is on its way, and the ten seconds pass.
    await page.mouse.move(...ASIDE);
    await page.clock.runFor(15_000);
    await still(page, sent, 0, 'the run of the user is not pushed aside');

    release();
    await says(page, first + 2);
});

test('on a phone the line fits, and its button is large enough for a finger', async ({ page }) => {
    await page.setViewportSize(PHONE);
    await open(page);

    await expect(pause(page)).toBeVisible();
    expect(await noHorizontalOverflow(page)).toBe(true);
    const size = await pause(page).boundingBox();
    expect(size.height).toBeGreaterThanOrEqual(24);
    expect(size.width).toBeGreaterThanOrEqual(24);
});

test.describe('without JavaScript', () => {
    test.use({ javaScriptEnabled: false });

    test('nothing runs by itself: the line says when the page was made, and there is nothing to pause', async ({ page }) => {
        await page.goto('/test-refresh');

        await expect(line(page)).toContainText(/Updated \d\d:\d\d:\d\d/);
        await expect(pause(page)).toBeHidden();
        await expect(page.locator('meta[http-equiv="refresh" i]')).toHaveCount(0);
    });
});
