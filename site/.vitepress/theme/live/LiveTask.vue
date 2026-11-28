<script setup>
// A task that takes long, running in front of the visitor: a tool, one row in the storage and
// a worker. The buttons of the small page are the buttons of the tool, and task.js says what
// each of them does and what the passing time changes.
import { computed, onBeforeUnmount, onMounted, ref, shallowRef } from 'vue';
import { EVERY, TOTAL, fresh, shows, start, stop, tick } from './task.js';

// For a reader without JavaScript.
const fallback = '<div class="live-noscript"><p>This picture moves with JavaScript. '
    + '<a href="/tutorials/long-task">A task that takes long</a> shows the same as a drawing.</p></div>';

// A way between two parts lights up for this long after it was used.
const GLOW = 700;

const alive = ref(false);    // the script of this page runs: the page can be used
const used = ref(false);     // a button was pressed: from now on a changed value shows that it changed
const state = shallowRef(fresh());

let clock = null;
let last = 0;

const row = computed(() => state.value.row);
const page = computed(() => shows(state.value.page));
const stops = computed(() => state.value.page.state === 'waiting' || state.value.page.state === 'running');
const lit = (way) => state.value.lit[way] !== null && state.value.now - state.value.lit[way] < GLOW;
// How much of the two seconds until the next look has passed.
const until = computed(() => (state.value.readAt === null ? 0 : 1 - (state.value.readAt - state.value.now) / EVERY));
const number = (value) => String(value).replace(/\B(?=(\d{3})+(?!\d))/g, ',');

const worker = computed(() => {
    const taken = state.value.lit.pick !== null && state.value.lit.pick >= (state.value.lit.start ?? 0);
    switch (row.value.state) {
        case 'waiting':
            return { turns: false, does: 'Has not taken the task yet.' };
        case 'running':
            return { turns: true, does: row.value.stop ? 'Writes orders. It has not seen the wish yet.' : 'Writes orders, and notes in the row how far it is.' };
        case 'finished':
            return { turns: false, does: 'Done. All orders are written.' };
        case 'stopped':
            return { turns: false, does: taken ? 'Stopped: it found the wish in the row.' : 'Never started: the task was stopped while it waited.' };
        default:
            return { turns: false, does: 'Waits for a task.' };
    }
});

const says = computed(() => {
    const seen = state.value.page.state;
    switch (row.value.state) {
        case 'waiting':
            return 'The tool wrote one row and ran again. The page shows a bar already, and no work is done yet.';
        case 'running':
            return row.value.stop
                ? 'The wish to stop stands in the row. The worker finds it between two steps.'
                : 'The worker writes into the row how far it is. The page reads the row every two seconds, so its bar jumps.';
        case 'finished':
            return seen === 'finished'
                ? 'The task is over. The script no longer reaches refresh(2), and the page stands still.'
                : 'The worker is done. The page does not know it yet: it looks again in a moment.';
        case 'stopped':
            return seen === 'stopped'
                ? 'The export stopped where it was, and the page stands still again.'
                : 'The worker stopped. The page finds out at its next look.';
        default:
            return 'Press "Start the export". The tool hands the work over and comes back at once.';
    }
});

// Something still moves: the page looks, the worker works, or a way has not gone dark yet.
const busy = () => {
    const { now, readAt, workAt } = state.value;

    return readAt !== null || workAt !== null || Object.values(state.value.lit).some((time) => time !== null && now - time < GLOW + 200);
};

const beat = () => {
    const time = performance.now();
    // A tab in the background gets no beats, and the time it was away does not count.
    state.value = tick(state.value, Math.min(time - last, 250));
    last = time;

    if (!busy()) {
        clearInterval(clock);
        clock = null;
    }
};

const press = () => {
    used.value = true;
    state.value = stops.value ? stop(state.value) : start(state.value);

    if (clock === null) {
        last = performance.now();
        clock = setInterval(beat, 100);
    }
};

onMounted(() => {
    alive.value = true;
});

onBeforeUnmount(() => {
    clearInterval(clock);
});
</script>

<template>
    <figure class="live-figure live-task">
        <div class="live">
            <div class="live-stage">
                <div class="live-part" :class="{ on: lit('read') }">
                    <p class="live-name">Your tool</p>
                    <div class="live-window">
                        <p class="live-chrome" aria-hidden="true"><i /><i /><i /><span>localhost:8765/export</span></p>
                        <div class="live-page">
                            <p class="live-title">Export orders</p>
                            <template v-if="page.kind === 'progress'">
                                <p class="live-label">{{ page.bar === null ? page.text : 'Exporting orders' }}</p>
                                <div class="live-bar" :class="{ unknown: page.bar === null }" aria-hidden="true">
                                    <i :style="page.bar === null ? null : { width: page.bar * 100 + '%' }" />
                                </div>
                                <p v-if="page.bar !== null" class="live-text strong">{{ page.text }}</p>
                            </template>
                            <p v-else-if="page.kind === 'note'" class="live-text">{{ page.text }}</p>
                            <p v-else class="live-callout" :class="page.kind === 'success' ? 'good' : 'warn'">{{ page.text }}</p>
                            <button type="button" class="live-btn" :class="{ primary: !stops }" :disabled="!alive" @click="press">{{ page.button }}</button>
                        </div>
                    </div>
                </div>

                <div class="live-ways">
                    <span class="live-way" :class="{ on: lit('start') || lit('stop') }">
                        {{ stops ? 'Stop: writes the wish' : 'Start: writes the row' }}
                        <span class="live-arrow" aria-hidden="true"><i /></span>
                    </span>
                    <span class="live-way left" :class="{ on: lit('read') }">
                        every 2 seconds: reads the row
                        <span class="live-arrow" aria-hidden="true"><i /></span>
                        <span class="live-until" aria-hidden="true"><i :style="{ width: until * 100 + '%' }" /></span>
                    </span>
                </div>

                <div class="live-part" :class="{ on: lit('start') || lit('stop') || lit('write') }">
                    <p class="live-name">Your storage <span>one row</span></p>
                    <div class="live-box">
                        <dl class="live-row">
                            <dt>state</dt>
                            <dd :key="'state-' + row.state" :class="{ changed: used }">{{ row.state }}</dd>
                            <dt>done</dt>
                            <dd :key="'done-' + row.done" :class="{ changed: used }">{{ number(row.done) }}</dd>
                            <dt>total</dt>
                            <dd>{{ number(TOTAL) }}</dd>
                            <dt>stop</dt>
                            <dd :key="'stop-' + row.stop" :class="{ changed: used }">{{ row.stop ? 'asked' : 'no' }}</dd>
                        </dl>
                    </div>
                </div>

                <div class="live-ways">
                    <span class="live-way" :class="{ on: lit('pick') }">
                        picks the task up
                        <span class="live-arrow" aria-hidden="true"><i /></span>
                    </span>
                    <span class="live-way left" :class="{ on: lit('write') }">
                        writes how far it is
                        <span class="live-arrow" aria-hidden="true"><i /></span>
                    </span>
                </div>

                <div class="live-part" :class="{ on: worker.turns }">
                    <p class="live-name">A worker</p>
                    <div class="live-box">
                        <p class="live-does"><span class="live-gear" :class="{ turns: worker.turns }" aria-hidden="true" />{{ worker.does }}</p>
                        <p class="live-does">A queue job, a cron job or a command. It is yours: the library brings none.</p>
                    </div>
                </div>
            </div>

            <div class="live-foot">
                <div class="live-says">
                    <!-- Only this sentence is read out when it changes. The counter under it
                         changes every two seconds, and nobody wants to hear that. -->
                    <p class="live-step" aria-live="polite">{{ says }}</p>
                    <p class="live-hint">
                        The script of the tool ran <b>{{ state.reads }}</b> {{ state.reads === 1 ? 'time' : 'times' }}, each for a fraction of a second.
                    </p>
                </div>
            </div>
        </div>

        <figcaption>
            A task that takes long. The page never does the work: it looks at it every two seconds. This picture is a
            simulation in your browser: a clock stands in for the worker, and nothing is sent.
        </figcaption>
        <!-- Written as one piece of text: a browser that runs scripts keeps what stands in here as text. -->
        <noscript v-html="fallback" />
    </figure>
</template>
