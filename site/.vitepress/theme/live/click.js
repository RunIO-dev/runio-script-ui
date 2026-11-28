// What one click does in site/samples/hello.php, as plain data.
//
// The picture on the first page plays these steps. Nothing here draws anything, so the same
// steps can be tested without a browser, and e2e/site compares them with the real library.

/** The lines of the sample that a step points at. The page finds each of them by its text. */
export const LINES = Object.freeze({
    title: "title('Hello');",
    text: "$name = text('Your name');",
    button: "if (button('Greet') && $name !== '') {",
    success: "success('Hello, ' . $name . '.');",
});

/** What the picture shows while no run is on its way. */
export const REST = Object.freeze({
    where: 'browser',
    line: null,
    ran: [],
    skipped: [],
    returns: {},
    parts: [],
    sent: false,
    checked: false,
    back: false,
    swapped: false,
});

/**
 * What text() makes of a typed line. The same as Page::oneLine() of the library: control
 * characters become a blank, and the blanks at both ends go.
 *
 * @param {string} typed
 * @returns {string}
 */
export function tidy(typed) {
    return typed.replace(/[\x00-\x1F\x7F]+/g, ' ').replace(/^ +| +$/g, '');
}

// A value the way PHP writes it: 'Ava', and 'O\'Hara' for a name with a quote in it.
const php = (value) => (typeof value === 'string' ? `'${value.replace(/\\/g, '\\\\').replace(/'/g, "\\'")}'` : String(value));

/**
 * One run of the script, for the form that the browser sent.
 *
 * @param {{ name: string, pressed: boolean }} form what the field holds, and whether "Greet" was pressed
 * @returns {{
 *     sent: { name: string, value: string }[],
 *     steps: { id: string, where: string, says: string, line?: string, draws?: string, returns?: string, skipped?: boolean }[],
 *     page: { name: string, greeting: string | null },
 * }}
 */
export function run(form) {
    const name = tidy(form.name);
    const pressed = form.pressed === true;
    const greets = pressed && name !== '';

    const steps = [
        {
            id: 'send',
            where: 'out',
            says: pressed
                ? 'You pressed Greet. The browser sends the whole form, and which button was pressed.'
                : 'You changed the field. The browser sends the whole form. No button was pressed.',
        },
        {
            id: 'checks',
            where: 'checks',
            says: 'Script UI asks first: who is asking, may they open this tool, is the form ours?',
        },
        {
            id: 'title',
            where: 'script',
            line: 'title',
            draws: 'title',
            says: 'Your script runs from the top. title() puts the title on the new page.',
        },
        {
            id: 'text',
            where: 'script',
            line: 'text',
            draws: 'field',
            returns: php(name),
            says: 'text() puts the field on the page and returns what was typed.',
        },
        {
            id: 'button',
            where: 'script',
            line: 'button',
            draws: 'button',
            returns: php(pressed),
            says: pressed
                ? 'button() puts the button on the page and returns true, in this run only.'
                : 'button() puts the button on the page. Nobody pressed it, so it returns false.',
        },
        greets
            ? {
                id: 'success',
                where: 'script',
                line: 'success',
                draws: 'message',
                says: 'The if is true. success() puts the message on the page.',
            }
            : {
                id: 'skip',
                where: 'script',
                line: 'success',
                skipped: true,
                says: pressed
                    ? 'The button was pressed, but the name is empty. The line inside the if does not run.'
                    : 'The if is false, so the line inside it does not run. No greeting this time.',
            },
        {
            id: 'end',
            where: 'back',
            says: 'The script is at its end. Script UI sends the new page back.',
        },
        {
            id: 'swap',
            where: 'browser',
            says: 'The browser swaps the content of the page. Nothing is loaded again.',
        },
    ];

    return {
        // The fields of the real form, by their names. The field goes as it was typed, and the
        // script is what tidies it. The button goes only when it was pressed. The token is a
        // long random text that the page carries: it answers "is the form ours?".
        sent: [
            { name: 'your-name', value: `"${form.name}"` },
            ...(pressed ? [{ name: '_click', value: 'greet' }] : []),
            { name: '_token', value: '\u2026' },
        ],
        steps,
        page: { name, greeting: greets ? `Hello, ${name}.` : null },
    };
}

/**
 * What the picture shows at one step of a run: the step itself, and what the steps before it
 * left behind. A step outside the run counts as the nearest one.
 *
 * @param {ReturnType<typeof run> | null} made a run, or null while nothing runs
 * @param {number} at the step, counted from 0
 * @returns {typeof REST}
 */
export function view(made, at) {
    if (made === null) {
        return REST;
    }

    const last = Math.min(Math.max(at, 0), made.steps.length - 1);
    const done = made.steps.slice(0, last + 1);
    const now = made.steps[last];
    const has = (id) => done.some((step) => step.id === id);

    return {
        where: now.where,
        line: now.line ?? null,
        ran: done.filter((step) => step.line !== undefined && step.skipped !== true).map((step) => step.line),
        skipped: done.filter((step) => step.skipped === true).map((step) => step.line),
        returns: Object.fromEntries(done.filter((step) => step.returns !== undefined).map((step) => [step.line, step.returns])),
        parts: done.filter((step) => step.draws !== undefined).map((step) => step.draws),
        sent: true,
        checked: has('checks'),
        back: has('end'),
        swapped: has('swap'),
    };
}
