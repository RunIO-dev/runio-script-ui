import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { LINES, REST, run, tidy, view } from './click.js';

const sample = readFileSync(new URL('../../../samples/hello.php', import.meta.url), 'utf8');
const ids = (steps) => steps.map((step) => step.id);

test('every line that a step points at stands in the sample, once', () => {
    const lines = sample.split('\n').map((line) => line.trim());
    for (const [name, text] of Object.entries(LINES)) {
        assert.equal(lines.filter((line) => line === text).length, 1, `the line "${name}" of the sample`);
    }
});

test('a press with a name greets', () => {
    const made = run({ name: 'Ava', pressed: true });

    assert.deepEqual(made.page, { name: 'Ava', greeting: 'Hello, Ava.' });
    assert.deepEqual(ids(made.steps), ['send', 'checks', 'title', 'text', 'button', 'success', 'end', 'swap']);
    assert.equal(made.steps[3].returns, "'Ava'");
    assert.equal(made.steps[4].returns, 'true');
});

test('a changed field without a press does not greet, and button() returns false', () => {
    const made = run({ name: 'Omar', pressed: false });

    assert.deepEqual(made.page, { name: 'Omar', greeting: null });
    assert.deepEqual(ids(made.steps), ['send', 'checks', 'title', 'text', 'button', 'skip', 'end', 'swap']);
    assert.equal(made.steps[4].returns, 'false');
    assert.match(made.steps[0].says, /No button/);
});

test('a press with an empty name does not greet, though button() returns true', () => {
    const made = run({ name: '   ', pressed: true });

    assert.deepEqual(made.page, { name: '', greeting: null });
    assert.equal(made.steps[3].returns, "''");
    assert.equal(made.steps[4].returns, 'true');
    assert.equal(made.steps[5].id, 'skip');
    assert.match(made.steps[5].says, /empty/);
});

test('text() returns the typed line the way the library tidies it', () => {
    assert.equal(tidy('  Ava \t'), 'Ava');
    assert.equal(tidy('Ava\tMaria'), 'Ava Maria');
    assert.equal(tidy(' Ava'), ' Ava', 'only what PHP trims is trimmed');
    assert.equal(run({ name: ' Ava ', pressed: true }).page.greeting, 'Hello, Ava.');
});

test('what is typed is shown as PHP would write it, also quotes and markup', () => {
    const made = run({ name: '<b>"x"</b>', pressed: true });

    assert.equal(made.steps[3].returns, `'<b>"x"</b>'`);
    assert.equal(made.page.greeting, 'Hello, <b>"x"</b>.');
    assert.equal(run({ name: "O'Hara\\", pressed: true }).steps[3].returns, "'O\\'Hara\\\\'");
});

test('the form that goes out names the field, and the button only when it was pressed', () => {
    assert.deepEqual(run({ name: 'Ava', pressed: true }).sent, [
        { name: 'your-name', value: '"Ava"' },
        { name: '_click', value: 'greet' },
        { name: '_token', value: '\u2026' },
    ]);
    assert.deepEqual(run({ name: ' Ava ', pressed: false }).sent, [
        { name: 'your-name', value: '" Ava "' },
        { name: '_token', value: '\u2026' },
    ]);
});

test('the picture gathers what the steps before did', () => {
    const made = run({ name: 'Ava', pressed: true });

    assert.deepEqual(view(made, 0), { ...REST, where: 'out', sent: true });

    const atButton = view(made, 4);
    assert.equal(atButton.where, 'script');
    assert.equal(atButton.line, 'button');
    assert.deepEqual(atButton.ran, ['title', 'text', 'button']);
    assert.deepEqual(atButton.returns, { text: "'Ava'", button: 'true' });
    assert.deepEqual(atButton.parts, ['title', 'field', 'button']);
    assert.equal(atButton.checked, true);
    assert.equal(atButton.back, false);

    const atEnd = view(made, 7);
    assert.deepEqual(atEnd.parts, ['title', 'field', 'button', 'message']);
    assert.equal(atEnd.where, 'browser');
    assert.equal(atEnd.swapped, true);
});

test('a line that did not run is marked as skipped and draws nothing', () => {
    const made = run({ name: 'Omar', pressed: false });
    const after = view(made, 5);

    assert.deepEqual(after.skipped, ['success']);
    assert.deepEqual(after.ran, ['title', 'text', 'button']);
    assert.deepEqual(after.parts, ['title', 'field', 'button']);
});

test('the browser shows the old page until the last step', () => {
    const made = run({ name: 'Ava', pressed: true });
    const swaps = made.steps.map((step, at) => view(made, at).swapped);

    assert.deepEqual(swaps, [false, false, false, false, false, false, false, true]);
});

test('without a run the picture is at rest, and a step outside the run is the nearest one', () => {
    const made = run({ name: 'Ava', pressed: true });

    assert.deepEqual(view(null, 3), REST);
    assert.deepEqual(view(made, 99), view(made, 7));
    assert.deepEqual(view(made, -5), view(made, 0));
});

test('every step says something short', () => {
    for (const form of [{ name: 'Ava', pressed: true }, { name: 'Ava', pressed: false }, { name: '', pressed: true }]) {
        for (const step of run(form).steps) {
            assert.ok(step.says.length >= 30 && step.says.length <= 96, `${step.id}: "${step.says}" has ${step.says.length} characters`);
        }
    }
});
