// A task that takes long, as plain data: a tool, one row in the storage, and a worker.
//
// The tool and the worker never talk to each other. The worker writes into the row how far it
// is, and the page reads the row every two seconds. Nothing here draws anything or reads a
// clock: the picture on the first page tells it how much time has passed.

/** How many orders the export has. */
export const TOTAL = 2400;
/** The page looks at the row this often, in milliseconds: refresh(2). */
export const EVERY = 2000;
/** A worker takes a waiting task after this long. */
export const PICK_UP = 1500;
/** The worker writes how far it is this often. */
export const STEP = 500;
/** How many orders the worker writes between two looks at the row. */
export const PER_STEP = 75;

const active = (holder) => holder.state === 'waiting' || holder.state === 'running';

/**
 * The start: no export yet, and a page that stands still.
 *
 * `row` is what the storage holds and `page` is what the page read at its last run. `lit` says
 * when each way between the three was last used, so the picture can let it light up.
 */
export function fresh() {
    return {
        now: 0,
        row: { state: 'none', done: 0, stop: false },
        page: { state: 'none', done: 0 },
        readAt: null,
        workAt: null,
        reads: 0,
        lit: { start: null, pick: null, write: null, read: null, stop: null },
    };
}

/**
 * The button "Start the export": the tool writes the row and runs again at once. The button is
 * only on the page while no task waits or runs.
 */
export function start(state) {
    if (active(state.page)) {
        return state;
    }

    return {
        ...state,
        row: { state: 'waiting', done: 0, stop: false },
        page: { state: 'waiting', done: 0 },
        readAt: state.now + EVERY,
        workAt: state.now + PICK_UP,
        reads: state.reads + 1,
        lit: { ...state.lit, start: state.now, read: state.now },
    };
}

/**
 * The button "Stop the export": the tool writes the wish down and runs again at once. A task
 * that no worker has taken yet is stopped right away. One that runs goes on until the worker
 * looks at the row the next time.
 */
export function stop(state) {
    if (!active(state.page)) {
        return state;
    }

    let { row, workAt, lit } = state;
    if (row.state === 'waiting') {
        row = { ...row, state: 'stopped', stop: true };
        workAt = null;
        lit = { ...lit, stop: state.now };
    } else if (row.state === 'running') {
        row = { ...row, stop: true };
        lit = { ...lit, stop: state.now };
    }

    return {
        ...state,
        row,
        workAt,
        page: { state: row.state, done: row.done },
        readAt: active(row) ? state.now + EVERY : null,
        reads: state.reads + 1,
        lit: { ...lit, read: state.now },
    };
}

/**
 * Lets time pass. The worker takes its steps and the page takes its looks in the order of
 * the clock, so one big jump gives the same as many small ones.
 *
 * @param {ReturnType<typeof fresh>} state
 * @param {number} ms how much time has passed
 */
export function tick(state, ms) {
    if (!(ms > 0)) {
        return state;
    }

    const until = state.now + ms;
    let { row, page, readAt, workAt, reads, lit } = state;

    for (;;) {
        const work = workAt !== null && workAt <= until ? workAt : null;
        const read = readAt !== null && readAt <= until ? readAt : null;
        if (work === null && read === null) {
            break;
        }

        // At the same moment the worker goes first: the page then reads what was just written.
        if (work !== null && (read === null || work <= read)) {
            if (row.state === 'waiting') {
                row = { ...row, state: 'running' };
                lit = { ...lit, pick: work };
                workAt = work + STEP;
            } else if (row.stop) {
                row = { ...row, state: 'stopped' };
                lit = { ...lit, write: work };
                workAt = null;
            } else {
                const done = Math.min(TOTAL, row.done + PER_STEP);
                row = { ...row, done, state: done === TOTAL ? 'finished' : 'running' };
                lit = { ...lit, write: work };
                workAt = done === TOTAL ? null : work + STEP;
            }
        } else {
            page = { state: row.state, done: row.done };
            reads += 1;
            lit = { ...lit, read };
            // When the task is over, the script no longer reaches refresh(2): the page stands still.
            readAt = active(row) ? read + EVERY : null;
        }
    }

    return { now: until, row, page, readAt, workAt, reads, lit };
}

// 2400 becomes "2,400", as number_format() of PHP writes it.
const count = (number) => String(number).replace(/\B(?=(\d{3})+(?!\d))/g, ',');

/**
 * What the page of the tool shows for what it read: the words, the bar and the button.
 *
 * @param {{ state: string, done: number }} page
 * @returns {{ kind: 'note' | 'progress' | 'success' | 'warning', bar: number | null, text: string, button: string }}
 */
export function shows(page) {
    switch (page.state) {
        case 'waiting':
            return { kind: 'progress', bar: null, text: 'Waiting for a worker', button: 'Stop the export' };
        case 'running':
            return { kind: 'progress', bar: page.done / TOTAL, text: `${count(page.done)} of ${count(TOTAL)} orders`, button: 'Stop the export' };
        case 'finished':
            return { kind: 'success', bar: null, text: `${count(page.done)} orders are exported.`, button: 'Start a new export' };
        case 'stopped':
            return { kind: 'warning', bar: null, text: `The export was stopped at ${count(page.done)} of ${count(TOTAL)} orders.`, button: 'Start a new export' };
        default:
            return { kind: 'note', bar: null, text: 'An export of all orders takes a while. A worker writes it.', button: 'Start the export' };
    }
}
