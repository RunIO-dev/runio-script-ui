<script setup>
// One click, played slowly: the small browser on the left is a page that the visitor can use,
// and every use of it starts a run of the steps in click.js.
import { computed, onBeforeUnmount, onMounted, ref, shallowRef, useId } from 'vue';
import { run, view } from './click.js';
import { data as lines } from './hello.data.js';

// The name that the picture types when it plays by itself. The screenshots use the same one.
const DEMO = 'Ava';
const QUESTIONS = ['who is asking', 'may they open this tool', 'is the form ours'];
const PARTS = { title: 'the title', field: 'the field', button: 'the button', message: 'the message' };

const fieldId = useId();
const browser = ref(null);

const alive = ref(false);       // the script of this page runs: the page can be used
const typed = ref('');          // what the field holds
const made = shallowRef(null);  // the run that is shown
const at = ref(0);              // the step of it
const playing = ref(false);
const demoing = ref(false);     // the picture is typing the name by itself
const pressing = ref(false);    // ... and holds the button down
const before = shallowRef({ name: '', greeting: null });   // the page as it was before the run
// The visitor did something themselves. Until then the picture may play by itself, and the
// steps of a run that nobody asked for are not read out by software that reads the page aloud.
const asked = ref(false);
// What such software announces: the step, once the visitor has asked for a run.
const spoken = computed(() => (asked.value && made.value !== null ? `Step ${at.value + 1} of ${count.value}. ${says.value}` : ''));

let timer = null;
let watcher = null;
let dirty = false;              // the field was changed since the last run
let form = null;                // what the browser sent for the run that is shown
let playedAtPress = null;       // whether a run was playing when the mouse went down on "Play"

const now = computed(() => view(made.value, at.value));
const count = computed(() => (made.value === null ? 0 : made.value.steps.length));
const atEnd = computed(() => made.value !== null && at.value >= count.value - 1);
const step = computed(() => (made.value === null ? null : made.value.steps[at.value]));
// The browser keeps the old page until the new one has arrived.
const shown = computed(() => (now.value.swapped ? made.value.page : before.value));

const says = computed(() => (step.value === null
    ? 'This is the page as it opens. The script ran once: nobody typed, and nobody pressed.'
    : step.value.says));

const hint = computed(() => {
    if (made.value === null) {
        return 'Type a name and press Greet, or press Play and watch.';
    }
    if (!atEnd.value) {
        return '';
    }
    if (made.value.page.greeting !== null) {
        return 'Now change the name and press Enter. Watch what button() returns.';
    }

    return form.pressed ? 'Type a name and press Greet again.' : 'Press Greet, and the greeting is back.';
});

const playLabel = computed(() => {
    if (playing.value || demoing.value) {
        return 'Pause';
    }

    return atEnd.value ? 'Play again' : 'Play';
});

// For a reader without JavaScript: the steps of the run that the picture plays by itself, as a list.
// The link names the file of the page. Nothing rewrites a link that is written here, as it does
// for the links of a markdown page, and the web server knows no page without ".html".
const escaped = (text) => text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;');
const fallback = '<div class="live-noscript"><p>This picture moves with JavaScript. Without it, this is what happens after somebody'
    + ` typed "${DEMO}" and pressed Greet:</p><ol>`
    + run({ name: DEMO, pressed: true }).steps.map((told) => `<li>${escaped(told.says)}</li>`).join('')
    + '</ol><p><a href="/guide/how-it-works.html">How it works</a> shows the same steps as a drawing.</p></div>';

// A step stays for as long as its sentence takes to read.
const pace = (sentence) => 1400 + 16 * sentence.length;

const go = (to) => {
    at.value = Math.min(Math.max(to, 0), count.value - 1);
    // The new page is in the browser. Its field holds what the script made of the typed text,
    // unless the visitor has typed on in the meantime.
    if (atEnd.value && typed.value === form.name) {
        typed.value = made.value.page.name;
    }
};

const wait = () => {
    clearTimeout(timer);
    if (!playing.value) {
        return;
    }
    if (atEnd.value) {
        playing.value = false;
        return;
    }

    timer = setTimeout(() => {
        go(at.value + 1);
        wait();
    }, pace(step.value.says));
};

const halt = () => {
    clearTimeout(timer);
    playing.value = false;
    demoing.value = false;
    pressing.value = false;
};

const begin = (pressed) => {
    // A real run takes a few milliseconds. The one that is still shown here ended long ago.
    halt();
    if (made.value !== null) {
        go(count.value - 1);
    }

    before.value = shown.value;
    form = { name: typed.value, pressed };
    dirty = false;
    made.value = run(form);
    at.value = 0;
    playing.value = true;
    wait();
};

// The picture uses the page by itself: it types a name and presses the button.
const demo = () => {
    halt();
    demoing.value = true;
    typed.value = '';

    let letters = 0;
    const act = () => {
        if (letters < DEMO.length) {
            letters += 1;
            typed.value = DEMO.slice(0, letters);
            timer = setTimeout(act, 180);
        } else if (!pressing.value) {
            pressing.value = true;
            timer = setTimeout(act, 420);
        } else {
            begin(true);
        }
    };
    timer = setTimeout(act, 500);
};

// The visitor takes over: the picture stops typing for them.
const take = () => {
    asked.value = true;
    if (demoing.value) {
        halt();
    }
};

const onInput = () => {
    take();
    dirty = true;
};

// Enter sends the form, and no button counts as pressed. Not the Enter that ends a character
// which is being put together from several keys.
const onEnter = (event) => {
    if (event.isComposing) {
        return;
    }

    event.preventDefault();
    take();
    begin(false);
};

// A changed field reruns the script when the visitor leaves it.
const onLeave = () => {
    if (dirty) {
        begin(false);
    }
};

const onGreet = () => {
    take();
    begin(true);
};

// The mouse goes down on "Play" or "Pause". A press takes the cursor out of the field, and for a
// changed field that alone starts a run, between this moment and the click.
const onPress = () => {
    playedAtPress = playing.value || demoing.value;
};

const play = () => {
    const startedByThisPress = playedAtPress === false && playing.value;
    playedAtPress = null;

    asked.value = true;
    if (demoing.value) {
        halt();
    } else if (made.value === null) {
        demo();
    } else if (playing.value) {
        // Whoever pressed "Play" does not want the run paused that their press has just started.
        if (!startedByThisPress) {
            halt();
        }
    } else {
        if (atEnd.value) {
            at.value = 0;
        }
        playing.value = true;
        wait();
    }
};

const back = () => {
    asked.value = true;
    halt();
    go(at.value - 1);
};

const next = () => {
    asked.value = true;
    halt();
    go(at.value + 1);
};

onMounted(() => {
    alive.value = true;

    // Once, when half of the small browser is in view, the picture plays by itself. Not for
    // whoever asked their system for less motion.
    if (typeof IntersectionObserver === 'undefined' || window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        return;
    }

    watcher = new IntersectionObserver((entries) => {
        if (!entries.some((entry) => entry.isIntersecting && entry.intersectionRatio >= 0.5)) {
            return;
        }

        watcher.disconnect();
        watcher = null;
        if (!asked.value && made.value === null && !demoing.value) {
            demo();
        }
    }, { threshold: 0.5 });
    watcher.observe(browser.value);
});

onBeforeUnmount(() => {
    clearTimeout(timer);
    if (watcher !== null) {
        watcher.disconnect();
    }
});
</script>

<template>
    <figure class="live-figure live-click">
        <div class="live" :class="{ 'is-playing': playing }">
            <div class="live-stage">
                <div class="live-part" :class="{ on: now.where === 'browser' && made !== null }">
                    <p class="live-name">The browser</p>
                    <div ref="browser" class="live-window" :class="{ fresh: now.swapped }">
                        <p class="live-chrome" aria-hidden="true"><i /><i /><i /><span>localhost:8765/hello</span></p>
                        <div class="live-page">
                            <p class="live-title">Hello</p>
                            <label class="live-label" :for="fieldId">Your name</label>
                            <input
                                :id="fieldId"
                                v-model="typed"
                                class="live-input"
                                type="text"
                                maxlength="30"
                                autocomplete="off"
                                spellcheck="false"
                                :disabled="!alive"
                                @input="onInput"
                                @keydown.enter="onEnter"
                                @change="onLeave"
                            >
                            <button type="button" class="live-btn" :class="{ pressed: pressing }" :disabled="!alive" @click="onGreet">Greet</button>
                            <p v-if="shown.greeting !== null" class="live-callout good">{{ shown.greeting }}</p>
                        </div>
                    </div>
                </div>

                <div class="live-wire">
                    <div class="live-lane out" :class="{ on: now.where === 'out', done: now.sent }">
                        <p class="live-lane-name">the whole form</p>
                        <ul class="live-chips">
                            <li v-for="sent in made === null ? [] : made.sent" :key="sent.name" :title="sent.name + ': ' + sent.value">
                                {{ sent.name }}: <b>{{ sent.value }}</b>
                            </li>
                        </ul>
                        <span class="live-arrow" aria-hidden="true"><i /></span>
                    </div>

                    <div class="live-gate" :class="{ on: now.where === 'checks', done: now.checked }">
                        <p class="live-gate-name">Script UI</p>
                        <ul>
                            <li v-for="question in QUESTIONS" :key="question">{{ question }}</li>
                        </ul>
                    </div>

                    <div class="live-lane back" :class="{ on: now.where === 'back', done: now.back }">
                        <span class="live-arrow" aria-hidden="true"><i /></span>
                        <p class="live-lane-name">the new page</p>
                        <ul class="live-chips">
                            <li v-for="part in now.parts" :key="part">{{ PARTS[part] }}</li>
                        </ul>
                    </div>
                </div>

                <div class="live-part" :class="{ on: now.where === 'script' }">
                    <p class="live-name">Your script <span>tools/hello.php</span></p>
                    <div class="live-code">
                        <div class="live-lines">
                            <div
                                v-for="(line, index) in lines"
                                :key="index"
                                class="live-line"
                                :class="{ now: line.is !== null && line.is === now.line, skipped: now.skipped.includes(line.is) }"
                            >
                                <span class="live-no" aria-hidden="true">{{ index + 1 }}</span>
                                <span class="live-src" v-html="line.html" />
                                <span v-if="line.is !== null && now.returns[line.is] !== undefined" class="live-returns">returns <b>{{ now.returns[line.is] }}</b></span>
                                <span v-else-if="now.skipped.includes(line.is)" class="live-returns off">does not run</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="live-foot">
                <div class="live-says">
                    <!-- Two forms of the same sentence. The one to see is always there. The one that
                         is read out stays empty until the visitor asks for a run, and from then on
                         it stands for the first, so nobody hears a sentence twice. -->
                    <p class="live-step" :aria-hidden="spoken === '' ? undefined : 'true'">
                        <span v-if="made !== null" class="live-count">Step {{ at + 1 }} of {{ count }}</span>
                        {{ says }}
                    </p>
                    <p class="visually-hidden" aria-live="polite">{{ spoken }}</p>
                    <p class="live-hint">{{ hint }}</p>
                </div>
                <div v-if="alive" class="live-controls" role="group" aria-label="The steps of the run">
                    <button type="button" :disabled="made === null || at === 0" @click="back">Back</button>
                    <button type="button" class="main" @pointerdown="onPress" @click="play">{{ playLabel }}</button>
                    <button type="button" :disabled="made === null || atEnd" @click="next">Next</button>
                </div>
            </div>
        </div>

        <figcaption>
            One click, played slowly. In a real tool every use of the page sends the whole form, and the script runs
            again from the top. This picture is a simulation in your browser: it sends nothing.
        </figcaption>
        <!-- Written as one piece of text: a browser that runs scripts keeps what stands in here as text. -->
        <noscript v-html="fallback" />
    </figure>
</template>
