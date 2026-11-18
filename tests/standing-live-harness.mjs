// Runs assets/standing_live.js - the live part of the public standing page (competition toolkit v2 S4) - through its
// scenarios with a fake clock, timers, fragment fetch and Mercure stream, and prints {scenario: "ok" | error} as JSON.
// tests/StandingLiveScriptTest.php asserts every one is "ok".

import assert from 'node:assert/strict';
import { isSignal, JITTER_MS, LIVE_POLL_MS, POLL_MS, POLL_SPREAD, pollDelay, SIGNAL_TYPE, StandingLive, VISIBLE_JITTER_MS } from '../assets/standing_live.js';

const HUB = 'https://myspeedpuzzling.test/.well-known/mercure';
const TOPIC = '/competition-standings/018d0020-0000-0000-0000-000000000001';
const SIGNAL = { type: SIGNAL_TYPE };

async function settle() {
    for (let i = 0; i < 10; i++) {
        await new Promise((resolve) => setImmediate(resolve));
    }
}

function fakeClock() {
    let now = 0;
    let nextId = 1;
    const timers = new Map();

    return {
        now: () => now,
        schedule(task, ms) {
            const id = nextId++;
            timers.set(id, { at: now + ms, task, ms });

            return id;
        },
        cancel(id) {
            timers.delete(id);
        },
        delays: () => [...timers.values()].map((timer) => timer.ms),
        pending: () => timers.size,
        async advance(ms) {
            const until = now + ms;

            for (;;) {
                const due = [...timers.entries()].filter(([, timer]) => timer.at <= until).sort((a, b) => a[1].at - b[1].at)[0];

                if (due === undefined) {
                    break;
                }

                timers.delete(due[0]);
                now = due[1].at;
                due[1].task();
                await settle();
            }

            now = until;
            await settle();
        },
    };
}

/**
 * A page with a fake stream: `live` says whether the stream is open, `push()` delivers a message.
 */
function page({ random = () => 1, visible = true, live = true, answers = null, hubUrl = HUB } = {}) {
    const clock = fakeClock();
    const state = { visible, live, fetched: 0, applied: [], updated: 0, created: null, closed: false, pending: [] };
    let version = 0;

    const standing = new StandingLive({
        hubUrl,
        topic: TOPIC,
        fetchTable: () => {
            state.fetched += 1;

            if (answers !== null) {
                return answers(state.fetched);
            }

            // A fetch that the test resolves itself when `hold` is set
            if (state.hold) {
                return new Promise((resolve) => state.pending.push(resolve));
            }

            version += 1;

            return Promise.resolve({ status: 'ok', html: `<table>${version}</table>` });
        },
        apply: (html) => {
            const changed = state.applied[state.applied.length - 1] !== html;
            state.applied.push(html);

            return changed;
        },
        updated: () => {
            state.updated += 1;
        },
        isVisible: () => state.visible,
        now: clock.now,
        schedule: clock.schedule,
        cancel: clock.cancel,
        random,
        createEvents: (options) => {
            state.created = options;

            return {
                start() {},
                close() {
                    state.closed = true;
                },
                isLive: () => state.live,
            };
        },
    });

    return { standing, clock, state, push: (message) => state.created.onMessage(message) };
}

const scenarios = {
    async 'subscribes anonymously to the event topic'() {
        const { standing, state } = page();
        standing.start();

        assert.deepEqual(state.created.subscription, { url: HUB, topics: [TOPIC], public: true });
        assert.equal(state.fetched, 0, 'the page came with its table - nothing fetched at the start');
    },

    async 'a signal fetches after a random 0-4 s'() {
        const { standing, clock, state, push } = page({ random: () => 0.5 });
        standing.start();

        push(SIGNAL);
        assert.ok(clock.delays().includes(JITTER_MS / 2), 'waits random × 4 s');
        await clock.advance(JITTER_MS / 2 - 1);
        assert.equal(state.fetched, 0);
        await clock.advance(1);
        assert.equal(state.fetched, 1);
        assert.equal(state.updated, 1);
    },

    async 'the jitter never exceeds 4 s'() {
        for (const r of [0, 0.25, 0.999999]) {
            const { standing, clock, state, push } = page({ random: () => r });
            standing.start();
            push(SIGNAL);
            await clock.advance(JITTER_MS);
            assert.equal(state.fetched, 1, `random ${r}`);
        }
    },

    async 'other messages are ignored'() {
        const { standing, clock, state, push } = page();
        standing.start();

        push({ type: 'display.signal' });
        push(null);
        push('standing.signal');
        await clock.advance(JITTER_MS);
        assert.equal(state.fetched, 0);
        assert.ok(isSignal(SIGNAL));
        assert.ok(!isSignal({}));
    },

    async 'signals during the wait coalesce into one fetch'() {
        const { standing, clock, state, push } = page({ random: () => 1 });
        standing.start();

        push(SIGNAL);
        await clock.advance(1000);
        push(SIGNAL);
        push(SIGNAL);
        await clock.advance(JITTER_MS);
        assert.equal(state.fetched, 1);
    },

    async 'a signal during a fetch asks once more after it, never two at once'() {
        const { standing, clock, state, push } = page({ random: () => 0 });
        standing.start();
        state.hold = true;

        push(SIGNAL);
        await clock.advance(0);
        assert.equal(state.fetched, 1);

        // Three signals while the fetch runs: one more fetch, after it
        push(SIGNAL);
        await clock.advance(0);
        push(SIGNAL);
        await clock.advance(0);
        assert.equal(state.fetched, 1, 'one fetch at a time');

        state.hold = false;
        state.pending.shift()({ status: 'ok', html: '<table>a</table>' });
        await settle();
        await clock.advance(0);
        assert.equal(state.fetched, 2);
        await clock.advance(JITTER_MS);
        assert.equal(state.fetched, 2, 'and no more');
    },

    async 'an unchanged table is not swapped as an update'() {
        const { standing, clock, state, push } = page({ random: () => 0, answers: () => Promise.resolve({ status: 'ok', html: '<table>same</table>' }) });
        standing.start();

        push(SIGNAL);
        await clock.advance(0);
        push(SIGNAL);
        await clock.advance(0);
        assert.equal(state.fetched, 2);
        assert.equal(state.updated, 1, 'only the first answer changed what the page shows');
    },

    async 'hidden: a signal fetches nothing, shown again fetches within 0-1 s'() {
        const { standing, clock, state, push } = page({ random: () => 1, visible: false });
        standing.start();

        push(SIGNAL);
        push(SIGNAL);
        await clock.advance(JITTER_MS * 2);
        assert.equal(state.fetched, 0);

        state.visible = true;
        standing.onVisible();
        await clock.advance(VISIBLE_JITTER_MS);
        assert.equal(state.fetched, 1);

        // Shown again with nothing new: nothing fetched
        standing.onVisible();
        await clock.advance(VISIBLE_JITTER_MS);
        assert.equal(state.fetched, 1);
    },

    async 'a live stream: only a safety fetch every 5 minutes'() {
        const { standing, clock, state } = page({ live: true, random: () => 0.5 });
        standing.start();

        await clock.advance(LIVE_POLL_MS - 1);
        assert.equal(state.fetched, 0, 'no minute poll while live');
        await clock.advance(1);
        assert.equal(state.fetched, 1, 'a lost signal heals within 5 minutes');
        await clock.advance(LIVE_POLL_MS);
        assert.equal(state.fetched, 2);
    },

    async 'a live stream: a signal fetch postpones the safety fetch'() {
        const { standing, clock, state, push } = page({ live: true, random: () => 0.5 });
        standing.start();

        await clock.advance(LIVE_POLL_MS - 2 * POLL_MS);
        push(SIGNAL);
        await clock.advance(JITTER_MS);
        assert.equal(state.fetched, 1);
        await clock.advance(LIVE_POLL_MS - JITTER_MS - POLL_MS);
        assert.equal(state.fetched, 1, 'the signal\'s fetch counts as the last one');
        await clock.advance(2 * POLL_MS);
        assert.equal(state.fetched, 2);
    },

    async 'a live stream: the safety fetch is visible only'() {
        const { standing, clock, state } = page({ live: true, random: () => 0.5, visible: false });
        standing.start();

        await clock.advance(LIVE_POLL_MS * 3);
        assert.equal(state.fetched, 0);
        state.visible = true;
        standing.onVisible();
        await clock.advance(VISIBLE_JITTER_MS);
        assert.equal(state.fetched, 1);
    },

    async 'the stream dropping is polled within a minute'() {
        const { standing, clock, state } = page({ live: true, random: () => 0.5 });
        standing.start();

        await clock.advance(POLL_MS * 2);
        assert.equal(state.fetched, 0);
        state.live = false;
        await clock.advance(POLL_MS);
        assert.equal(state.fetched, 1);
    },

    async 'stream refused or down: the table every 60 s while visible'() {
        const { standing, clock, state } = page({ live: false, random: () => 0.5 });
        standing.start();

        await clock.advance(POLL_MS - 1);
        assert.equal(state.fetched, 0);
        await clock.advance(1);
        assert.equal(state.fetched, 1);
        await clock.advance(POLL_MS);
        assert.equal(state.fetched, 2);

        // Hidden: no poll, but the page is dirty - shown again, it fetches
        state.visible = false;
        await clock.advance(POLL_MS * 3);
        assert.equal(state.fetched, 2);
        state.visible = true;
        standing.onVisible();
        await clock.advance(VISIBLE_JITTER_MS);
        assert.equal(state.fetched, 3);
    },

    async 'the poll is spread ±20 %'() {
        assert.equal(pollDelay(() => 0), Math.round(POLL_MS * (1 - POLL_SPREAD)));
        assert.equal(pollDelay(() => 1), Math.round(POLL_MS * (1 + POLL_SPREAD)));
        assert.equal(pollDelay(() => 0.5), POLL_MS);
        assert.equal(pollDelay(() => 0, true), Math.round(LIVE_POLL_MS * (1 - POLL_SPREAD)));
        assert.equal(pollDelay(() => 1, true), Math.round(LIVE_POLL_MS * (1 + POLL_SPREAD)));
    },

    async 'no hub: no stream, polling only'() {
        const { standing, clock, state } = page({ hubUrl: '', random: () => 0.5 });
        standing.start();

        assert.equal(state.created, null);
        await clock.advance(POLL_MS);
        assert.equal(state.fetched, 1);
    },

    async 'the stream opening again asks once within 0-4 s'() {
        const { standing, clock, state } = page({ random: () => 1 });
        standing.start();

        const kind = await state.created.refresh();
        assert.equal(kind, 'ok');
        await clock.advance(JITTER_MS);
        assert.equal(state.fetched, 1);
    },

    async '404: everything stops'() {
        const { standing, clock, state, push } = page({ random: () => 0, live: false, answers: () => Promise.resolve({ status: 'gone' }) });
        standing.start();

        push(SIGNAL);
        await clock.advance(0);
        assert.equal(state.fetched, 1);
        assert.ok(state.closed, 'the stream is closed');
        assert.equal(clock.pending(), 0, 'no timer left');

        push(SIGNAL);
        await clock.advance(POLL_MS * 3);
        assert.equal(state.fetched, 1);
    },

    async 'a failed fetch keeps the table and is asked again on the next signal'() {
        let calls = 0;
        const { standing, clock, state, push } = page({
            random: () => 0,
            answers: () => {
                calls += 1;

                return calls === 1 ? Promise.reject(new Error('offline')) : Promise.resolve({ status: 'ok', html: '<table>x</table>' });
            },
        });
        standing.start();

        push(SIGNAL);
        await clock.advance(0);
        assert.equal(state.applied.length, 0);
        push(SIGNAL);
        await clock.advance(0);
        assert.deepEqual(state.applied, ['<table>x</table>']);
    },

    async 'close stops every timer and the stream'() {
        const { standing, clock, state, push } = page({ random: () => 1, live: false });
        standing.start();
        push(SIGNAL);
        standing.close();

        assert.ok(state.closed);
        assert.equal(clock.pending(), 0);
        await clock.advance(POLL_MS * 2);
        assert.equal(state.fetched, 0);
    },
};

const results = {};

for (const [name, scenario] of Object.entries(scenarios)) {
    try {
        await scenario();
        results[name] = 'ok';
    } catch (error) {
        results[name] = String(error?.stack ?? error);
    }
}

process.stdout.write(JSON.stringify(results));
