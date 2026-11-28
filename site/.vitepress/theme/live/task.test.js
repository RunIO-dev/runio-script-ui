import assert from 'node:assert/strict';
import { test } from 'node:test';
import { EVERY, TOTAL, fresh, shows, start, stop, tick } from './task.js';

// Time in steps of a tenth of a second, the way the page lets it pass.
const pass = (state, ms) => {
    let next = state;
    for (let left = ms; left > 0; left -= 100) {
        next = tick(next, Math.min(100, left));
    }
    return next;
};

test('nothing happens before Start', () => {
    const state = pass(fresh(), 10_000);

    assert.equal(state.row.state, 'none');
    assert.equal(state.page.state, 'none');
    assert.equal(state.reads, 0);
    assert.equal(state.readAt, null);
});

test('Start writes the row, and the page shows it at once', () => {
    const state = start(fresh());

    assert.deepEqual(state.row, { state: 'waiting', done: 0, stop: false });
    assert.deepEqual(state.page, { state: 'waiting', done: 0 });
    assert.equal(state.reads, 1);
    assert.equal(state.readAt, EVERY);
});

test('a worker takes the task, and the row runs ahead of the page', () => {
    const state = pass(start(fresh()), 3_900);

    assert.equal(state.row.state, 'running');
    assert.equal(state.page.state, 'running');
    assert.ok(state.row.done > state.page.done, `the row has ${state.row.done}, the page shows ${state.page.done}`);
});

test('at the same moment the worker goes first, and the page reads what was just written', () => {
    // Two seconds in, the worker takes its first step and the page takes its first look.
    const state = tick(start(fresh()), 2_000);

    assert.equal(state.row.done, 75);
    assert.equal(state.page.done, 75);
});

test('the page looks every two seconds while the task waits or runs', () => {
    let state = start(fresh());
    const looks = [];
    for (let ms = 0; ms < 8_000; ms += 100) {
        const before = state.reads;
        state = tick(state, 100);
        if (state.reads > before) looks.push(state.now);
    }

    assert.deepEqual(looks, [2_000, 4_000, 6_000, 8_000]);
});

test('the task ends at the total, and the page says so at its next look and then stands still', () => {
    const state = pass(start(fresh()), 60_000);

    assert.deepEqual(state.row, { state: 'finished', done: TOTAL, stop: false });
    assert.deepEqual(state.page, { state: 'finished', done: TOTAL });
    assert.equal(state.readAt, null);
    assert.equal(state.workAt, null);

    const later = pass(state, 10_000);
    assert.equal(later.reads, state.reads);
});

test('Stop is a wish: the row keeps running until the worker looks', () => {
    const running = pass(start(fresh()), 5_000);
    const asked = stop(running);

    assert.equal(asked.row.state, 'running');
    assert.equal(asked.row.stop, true);
    assert.equal(asked.reads, running.reads + 1, 'the page runs again after the press');

    const ended = pass(asked, 600);
    assert.equal(ended.row.state, 'stopped');
    assert.equal(ended.row.done, asked.row.done, 'the worker did no more work');

    const seen = pass(ended, 2_000);
    assert.deepEqual(seen.page, { state: 'stopped', done: ended.row.done });
    assert.equal(seen.readAt, null);
});

test('a task that still waits is stopped at once', () => {
    const state = stop(pass(start(fresh()), 300));

    assert.equal(state.row.state, 'stopped');
    assert.equal(state.page.state, 'stopped');
    assert.equal(state.workAt, null);
    assert.equal(state.readAt, null);
});

test('Stop on a page that is behind only makes it look', () => {
    // The task ended a moment ago, and the page has not looked since.
    let state = start(fresh());
    while (state.row.state !== 'finished') {
        state = tick(state, 100);
    }
    assert.equal(state.page.state, 'running');

    const after = stop(state);
    assert.equal(after.row.state, 'finished');
    assert.equal(after.page.state, 'finished');
});

test('a button that is not on the page does nothing', () => {
    const idle = fresh();
    assert.equal(stop(idle), idle);

    const running = pass(start(fresh()), 3_000);
    assert.equal(start(running), running);
});

test('a new export starts from zero', () => {
    const again = start(pass(start(fresh()), 60_000));

    assert.deepEqual(again.row, { state: 'waiting', done: 0, stop: false });
    assert.deepEqual(again.page, { state: 'waiting', done: 0 });
});

test('one big jump in time gives the same as many small ones', () => {
    const { lit: inSteps, ...small } = pass(start(fresh()), 9_700);
    const { lit: atOnce, ...big } = tick(start(fresh()), 9_700);

    assert.deepEqual(big, small);
    assert.deepEqual(atOnce, inSteps);
});

test('time does not run backwards and the states are never changed in place', () => {
    const state = start(fresh());
    const frozen = structuredClone(state);

    tick(state, 5_000);
    stop(state);
    assert.deepEqual(state, frozen);
    assert.equal(tick(state, -50), state);
    assert.equal(tick(state, 0), state);
});

test('the page says in words what the tool of the demo says', () => {
    assert.deepEqual(shows({ state: 'none', done: 0 }), { kind: 'note', bar: null, text: 'An export of all orders takes a while. A worker writes it.', button: 'Start the export' });
    assert.deepEqual(shows({ state: 'waiting', done: 0 }), { kind: 'progress', bar: null, text: 'Waiting for a worker', button: 'Stop the export' });
    assert.deepEqual(shows({ state: 'running', done: 900 }), { kind: 'progress', bar: 0.375, text: '900 of 2,400 orders', button: 'Stop the export' });
    assert.deepEqual(shows({ state: 'finished', done: 2400 }), { kind: 'success', bar: null, text: '2,400 orders are exported.', button: 'Start a new export' });
    assert.deepEqual(shows({ state: 'stopped', done: 1200 }), { kind: 'warning', bar: null, text: 'The export was stopped at 1,200 of 2,400 orders.', button: 'Start a new export' });
});
