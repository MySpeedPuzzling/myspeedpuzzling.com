// Runs assets/competitor_page_live.js - the live clock of the public competitor page (competition toolkit B5) - through
// its scenarios with a fake clock, timers, state fetch and Mercure stream, and prints {scenario: "ok" | error} as JSON.
// tests/CompetitorPageLiveScriptTest.php asserts every one is "ok".

import assert from 'node:assert/strict';
import {
    ACTIVE_POLL_MS,
    changedRounds,
    CompetitorPageLive,
    followedTopics,
    IDLE_POLL_MS,
    LIVE_POLL_MS,
    MAX_POLL_MS,
    MAX_TOPICS,
    nextChangeMs,
    pollDelay,
    PUSH_JITTER_MS,
    RECONNECT_JITTER_MS,
    REVEAL_GRACE_MS,
    REVEAL_JITTER_MS,
    roundView,
    wantsWakeLock,
} from '../assets/competitor_page_live.js';

const SERVER_NOW = 1_800_000_000_000;
const ROUND = '018d0020-0000-0000-0000-000000000101';
const OTHER = '018d0020-0000-0000-0000-000000000102';
const HUB = 'https://myspeedpuzzling.test/.well-known/mercure';

function stopwatch(status, { startedAtMs = null, stoppedAtMs = null, minutesLimit = 60, asOfMs = SERVER_NOW } = {}) {
    return { roundId: ROUND, status, startedAt: null, stoppedAt: null, minutesLimit, startedAtMs, stoppedAtMs, asOfMs, stateHash: 'h' };
}

function round(overrides = {}) {
    return {
        roundId: ROUND,
        name: 'Final',
        category: 'solo',
        startsAtMs: SERVER_NOW + 3600_000,
        minutesLimit: 60,
        tableNumber: 4,
        current: true,
        topic: `/round-stopwatch/${overrides.roundId ?? ROUND}`,
        stopwatch: stopwatch(null),
        published: false,
        puzzles: [],
        nextRevealAtMs: null,
        masked: false,
        entry: { isTeam: false, teamName: null, entrants: [{ name: 'Ann', country: 'cz' }], own: false, unpaired: false, result: null },
        ...overrides,
    };
}

function state(rounds, serverNowMs = SERVER_NOW) {
    return { serverNowMs, kind: 'p', id: 'x', rounds, mercure: { hubUrl: HUB, topics: rounds.map((r) => r.topic) } };
}

async function settle() {
    for (let i = 0; i < 10; i++) {
        await new Promise((resolve) => setImmediate(resolve));
    }
}

/**
 * The module with fakes. The device clock runs `skew` ms behind the server; `server.state` answers the fetches after
 * `server.rttMs`.
 */
function setup({ initial, skew = 0, random = () => 0.5 } = {}) {
    const device = { now: SERVER_NOW - skew };
    const timers = [];
    const server = { state: initial, status: 'ok', fetches: [], rttMs: 0 };
    const renders = [];
    const reloads = [];
    const wake = [];
    const events = { updates: [], live: false, suspended: false, closed: false, options: null };
    const page = { visible: true };

    const live = new CompetitorPageLive({
        state: initial,
        hubUrl: HUB,
        fetchState: async () => {
            const sentAtMs = device.now;
            server.fetches.push(device.now);
            await Promise.resolve();

            if (server.status !== 'ok') {
                return { status: server.status };
            }

            return {
                status: 'ok',
                state: { ...structuredClone(server.state), serverNowMs: device.now + skew },
                sentAtMs,
                receivedAtMs: sentAtMs + server.rttMs,
            };
        },
        render: (current, nowMs) => renders.push({ nowMs, views: current.rounds.map((r) => ({ roundId: r.roundId, ...roundView(r, nowMs) })) }),
        reloadContent: (changed) => reloads.push(changed),
        wakeLock: (on) => wake.push(on),
        isVisible: () => page.visible,
        now: () => device.now,
        schedule: (task, ms) => {
            const timer = { task, at: device.now + ms, ms };
            timers.push(timer);

            return timer;
        },
        cancel: (timer) => {
            const index = timers.indexOf(timer);

            if (index >= 0) {
                timers.splice(index, 1);
            }
        },
        random,
        createEvents: (options) => {
            events.options = options;

            return {
                start() {},
                update(subscription) {
                    events.updates.push(subscription);
                },
                suspend() {
                    events.suspended = true;
                },
                close() {
                    events.closed = true;
                },
                isLive: () => events.live,
            };
        },
    });

    async function advance(ms) {
        const until = device.now + ms;

        for (;;) {
            timers.sort((a, b) => a.at - b.at);
            const next = timers[0];

            if (next === undefined || next.at > until) {
                break;
            }

            timers.shift();
            device.now = Math.max(device.now, next.at);
            next.task();
            await settle();
        }

        device.now = until;
        await settle();
    }

    const lastView = (roundId = ROUND) => renders[renders.length - 1].views.find((view) => view.roundId === roundId);

    return { live, device, timers, server, renders, reloads, wake, events, page, advance, lastView };
}

const scenarios = {
    async 'phases from the state: idle, counting down, running with time left, time is up, stopped'() {
        const now = SERVER_NOW;
        assert.equal(roundView(round(), now).phase, 'idle');
        assert.equal(roundView(round(), now).remainingMs, null);

        const scheduled = roundView(round({ stopwatch: stopwatch('scheduled', { startedAtMs: now + 9500 }) }), now);
        assert.equal(scheduled.phase, 'scheduled');
        assert.equal(scheduled.countdownMs, 9500);
        assert.equal(scheduled.remainingMs, null);

        const running = roundView(round({ stopwatch: stopwatch('running', { startedAtMs: now - 61_000 }) }), now);
        assert.equal(running.phase, 'running');
        assert.equal(running.elapsedMs, 61_000);
        assert.equal(running.remainingMs, 3600_000 - 61_000);

        const up = roundView(round({ stopwatch: stopwatch('running', { startedAtMs: now - 3700_000 }) }), now);
        assert.equal(up.phase, 'times_up');
        assert.equal(up.elapsedMs, 3600_000);
        assert.equal(up.remainingMs, 0);

        const stopped = roundView(round({ stopwatch: stopwatch('stopped', { startedAtMs: now - 100_000, stoppedAtMs: now - 40_000 }) }), now);
        assert.equal(stopped.phase, 'stopped');
        assert.equal(stopped.elapsedMs, 60_000);

        // A scheduled start whose moment passed is running - by the page's own clock
        assert.equal(roundView(round({ stopwatch: stopwatch('scheduled', { startedAtMs: now - 1000 }) }), now).phase, 'running');
    },

    async 'the clock follows the server: hint from the page, then the shortest round trip; a tap reads it synchronously'() {
        // The device is 7 s behind the server
        const { live, device, server, advance } = setup({ initial: state([round()]), skew: 7000 });
        server.rttMs = 400;
        live.start();
        assert.equal(live.serverNow(), SERVER_NOW, 'the hint from the page');
        await settle();
        // First sample: server answered at sent + 7000 with rtt 400 → offset = 7000 - 200
        assert.equal(live.serverNow() - device.now, 6800);

        server.rttMs = 40;
        await advance(1500);
        assert.equal(live.serverNow() - device.now, 6980, 'the second, quicker sample wins');

        const before = live.serverNow();
        const tapped = live.tapNowMs();
        assert.equal(typeof tapped, 'number');
        assert.equal(tapped, before, 'no await between the tap and the reading');
    },

    async 'a countdown turns into the running clock at the moment, without any message or fetch'() {
        const start = SERVER_NOW + 5000;
        const { live, server, advance, lastView } = setup({ initial: state([round({ stopwatch: stopwatch('scheduled', { startedAtMs: start }) })]) });
        live.start();
        await settle();
        await advance(1500);
        const fetches = server.fetches.length;

        assert.equal(lastView().phase, 'scheduled');
        await advance(3400);
        assert.equal(live.roundView(ROUND).phase, 'scheduled');
        assert.equal(live.roundView(ROUND).countdownMs, 100);
        // The clock is drawn on its own timer right at the start
        await advance(200);
        assert.equal(lastView().phase, 'running');
        assert.ok(lastView().elapsedMs < 200);
        assert.equal(server.fetches.length, fetches, 'nothing fetched to start the clock');

        await advance(61_000);
        assert.equal(Math.floor(lastView().elapsedMs / 1000), 61);
    },

    async 'a stop push freezes the clock; an older push changes nothing'() {
        const running = stopwatch('running', { startedAtMs: SERVER_NOW - 30_000 });
        const { live, events, advance, lastView } = setup({ initial: state([round({ stopwatch: running })]) });
        live.start();
        await settle();

        events.options.onMessage(stopwatch('stopped', { startedAtMs: SERVER_NOW - 30_000, stoppedAtMs: SERVER_NOW + 2000, asOfMs: SERVER_NOW + 2000 }));
        assert.equal(lastView().phase, 'stopped');
        assert.equal(lastView().elapsedMs, 32_000);
        await advance(60_000);
        assert.equal(live.roundView(ROUND).elapsedMs, 32_000);

        // A late copy of the running state does not restart it
        events.options.onMessage({ ...running, asOfMs: SERVER_NOW - 1 });
        assert.equal(live.roundView(ROUND).phase, 'stopped');
    },

    async 'pushes ask for the state within 0-1 s - many pushes, one fetch'() {
        for (const r of [0, 0.999]) {
            const { live, events, server, advance } = setup({ initial: state([round()]), random: () => r });
            live.start();
            await advance(2000);
            const fetches = server.fetches.length;

            events.options.onMessage(stopwatch('running', { startedAtMs: SERVER_NOW + 2000, asOfMs: SERVER_NOW + 2000 }));
            events.options.onMessage(stopwatch('running', { startedAtMs: SERVER_NOW + 2000, asOfMs: SERVER_NOW + 2001 }));
            events.options.onMessage({ roundId: OTHER, status: 'running' });
            await advance(Math.floor(r * PUSH_JITTER_MS));
            assert.equal(server.fetches.length, fetches + 1, `random ${r}`);
            await advance(PUSH_JITTER_MS);
            assert.equal(server.fetches.length, fetches + 1);
        }
    },

    async 'the stream open again (or no stream): the state within 0-3 s, and the stream gets its answer'() {
        for (const r of [0, 0.5, 0.999]) {
            const { live, events, server, advance } = setup({ initial: state([round()]), random: () => r });
            live.start();
            await advance(2000);
            const fetches = server.fetches.length;
            let kind = null;
            events.options.refresh().then((answer) => {
                kind = answer;
            });

            if (r > 0) {
                await advance(Math.floor(r * RECONNECT_JITTER_MS) - 1);
                assert.equal(server.fetches.length, fetches, `not before the spread, random ${r}`);
            }

            await advance(2);
            assert.equal(server.fetches.length, fetches + 1);
            assert.equal(kind, 'ok');
        }
    },

    async 'a reveal moment asks for the state once (a moment, never a puzzle); the changed card is drawn again'() {
        const revealAt = SERVER_NOW + 60_000;
        const initial = state([round({ nextRevealAtMs: revealAt }), round({ roundId: OTHER, current: false })]);
        const { live, server, reloads, advance } = setup({ initial, random: () => 0.5 });
        live.start();
        await advance(2000);
        assert.equal(reloads.length, 0, 'same content - nothing drawn again');
        const fetches = server.fetches.length;

        // The server reveals at that moment
        server.state = state([round({ puzzles: [{ puzzleId: 'p', name: 'Cats', brand: null, piecesCount: 500, imageHidden: false }], nextRevealAtMs: null }), round({ roundId: OTHER, current: false })]);
        await advance(58_000 + REVEAL_GRACE_MS - 1);
        assert.equal(server.fetches.length, fetches);
        await advance(REVEAL_JITTER_MS * 0.5 + 2);
        assert.equal(server.fetches.length, fetches + 1);
        assert.deepEqual(reloads, [{ all: false, roundIds: [ROUND] }]);

        // No further reveal moment: nothing more planned for it
        await advance(10_000);
        assert.equal(server.fetches.length, fetches + 1);
    },

    async 'a scheduled start is checked 1 s after it (spread), cancelled starts too'() {
        const start = SERVER_NOW + 30_000;
        const { live, server, events, advance } = setup({ initial: state([round({ stopwatch: stopwatch('scheduled', { startedAtMs: start }) })]), random: () => 0.5 });
        // A live stream: only the 5-minute sample polls, so the fetch after the start is the start check
        events.live = true;
        live.start();
        await advance(2000);
        const fetches = server.fetches.length;
        await advance(28_000 + 1000 + REVEAL_JITTER_MS * 0.5 - 1);
        assert.equal(server.fetches.length, fetches);
        await advance(2);
        assert.equal(server.fetches.length, fetches + 1);
        assert.equal(live.roundView(ROUND).phase, 'running');
    },

    async 'polling: 5 min with a live stream, 15 s while a round runs, 60 s otherwise, doubled per error up to 5 min, ±20 %'() {
        assert.equal(pollDelay({ live: true, active: true, random: () => 0.5 }), LIVE_POLL_MS);
        assert.equal(pollDelay({ live: false, active: true, random: () => 0.5 }), ACTIVE_POLL_MS);
        assert.equal(pollDelay({ live: false, active: false, random: () => 0.5 }), IDLE_POLL_MS);
        assert.equal(pollDelay({ live: false, active: true, random: () => 0 }), ACTIVE_POLL_MS * 0.8);
        assert.equal(pollDelay({ live: false, active: true, random: () => 1 }), ACTIVE_POLL_MS * 1.2);
        assert.equal(pollDelay({ live: false, active: true, errors: 2, random: () => 0.5 }), ACTIVE_POLL_MS * 4);
        assert.equal(pollDelay({ live: false, active: true, errors: 30, random: () => 0.5 }), MAX_POLL_MS);

        // 300 phones, a server that fails: they back off and stay spread
        const { live, server, advance } = setup({ initial: state([round({ stopwatch: stopwatch('running', { startedAtMs: SERVER_NOW }) })]) });
        server.status = 'error';
        live.start();
        await advance(3600_000);
        assert.ok(server.fetches.length < 25, `fetches in an hour: ${server.fetches.length}`);
    },

    async 'a hidden page polls nothing and asks once it is shown again'() {
        const { live, page, server, advance } = setup({ initial: state([round({ stopwatch: stopwatch('running', { startedAtMs: SERVER_NOW }) })]) });
        live.start();
        await advance(2000);
        page.visible = false;
        const fetches = server.fetches.length;
        await advance(3600_000);
        assert.equal(server.fetches.length, fetches);

        page.visible = true;
        live.onVisible();
        await advance(600);
        assert.equal(server.fetches.length, fetches + 1);
    },

    async 'topics: the rounds within ±12 h and every started one, the nearest first, at most 12; the stream follows'() {
        const rounds = [
            round({ roundId: 'a', startsAtMs: SERVER_NOW - 13 * 3600_000 }),
            round({ roundId: 'b', startsAtMs: SERVER_NOW + 11 * 3600_000 }),
            round({ roundId: 'c', startsAtMs: SERVER_NOW - 20 * 3600_000, stopwatch: stopwatch('running', { startedAtMs: SERVER_NOW - 20 * 3600_000 }) }),
            round({ roundId: 'd', startsAtMs: SERVER_NOW + 13 * 3600_000 }),
        ];
        assert.deepEqual(followedTopics(state(rounds), SERVER_NOW), ['/round-stopwatch/c', '/round-stopwatch/b']);

        const many = Array.from({ length: 20 }, (_, i) => round({ roundId: `r${i}`, startsAtMs: SERVER_NOW + i * 60_000 }));
        const topics = followedTopics(state(many), SERVER_NOW);
        assert.equal(topics.length, MAX_TOPICS);
        assert.equal(topics[0], '/round-stopwatch/r0');

        const { live, events, server, advance } = setup({ initial: state([round()]) });
        live.start();
        assert.deepEqual(events.options.subscription, { url: HUB, topics: [`/round-stopwatch/${ROUND}`], public: true });
        server.state = state([round(), round({ roundId: OTHER })]);
        await advance(100);
        assert.deepEqual(events.updates[events.updates.length - 1].topics, [`/round-stopwatch/${ROUND}`, `/round-stopwatch/${OTHER}`]);
    },

    async 'a round came or went: every card is drawn again'() {
        assert.deepEqual(changedRounds(state([round()]), state([round(), round({ roundId: OTHER })])), { all: true, roundIds: [ROUND, OTHER] });
        assert.deepEqual(changedRounds(state([round()]), state([round({ stopwatch: stopwatch('running', { startedAtMs: 1 }) })])), { all: false, roundIds: [] }, 'the clock is not content');
        assert.deepEqual(changedRounds(state([round()]), state([round({ entry: { ...round().entry, result: { state: 'live', seconds: 1800 } } })])), { all: false, roundIds: [ROUND] });
    },

    async 'the screen stays on while the entrant plays (or starts within 5 minutes), never for a masked or empty entry'() {
        const running = { stopwatch: stopwatch('running', { startedAtMs: SERVER_NOW - 1000 }) };
        assert.equal(wantsWakeLock(state([round(running)]), SERVER_NOW), true);
        assert.equal(wantsWakeLock(state([round({ ...running, masked: true, entry: null })]), SERVER_NOW), false);
        assert.equal(wantsWakeLock(state([round({ ...running, entry: null })]), SERVER_NOW), false);
        assert.equal(wantsWakeLock(state([round({ stopwatch: stopwatch('scheduled', { startedAtMs: SERVER_NOW + 240_000 }) })]), SERVER_NOW), true);
        assert.equal(wantsWakeLock(state([round({ stopwatch: stopwatch('scheduled', { startedAtMs: SERVER_NOW + 600_000 }) })]), SERVER_NOW), false);
        assert.equal(wantsWakeLock(state([round()]), SERVER_NOW), false);

        const { live, events, wake } = setup({ initial: state([round(running)]) });
        live.start();
        assert.deepEqual(wake, [true]);
        events.options.onMessage(stopwatch('stopped', { startedAtMs: SERVER_NOW - 1000, stoppedAtMs: SERVER_NOW, asOfMs: SERVER_NOW + 1 }));
        assert.deepEqual(wake, [true, false]);
        live.onVisible();
        assert.deepEqual(wake, [true, false], 'not re-acquired when nothing runs');
    },

    async 'the clock is drawn at each whole second, never in between; nothing ticks while everything stands still'() {
        const now = SERVER_NOW;
        assert.equal(nextChangeMs(state([round()]), now), null);
        assert.equal(nextChangeMs(state([round({ stopwatch: stopwatch('running', { startedAtMs: now - 1250 }) })]), now), 750);
        assert.equal(nextChangeMs(state([round({ stopwatch: stopwatch('scheduled', { startedAtMs: now + 2300 }) })]), now), 300);
        assert.equal(nextChangeMs(state([round({ stopwatch: stopwatch('stopped', { startedAtMs: now - 5000, stoppedAtMs: now }) })]), now), null);

        const { live, renders, advance } = setup({ initial: state([round({ stopwatch: stopwatch('running', { startedAtMs: now }) })]) });
        live.start();
        const before = renders.length;
        await advance(10_000);
        const drawn = renders.length - before;
        assert.ok(drawn >= 10 && drawn <= 16, `renders in 10 s: ${drawn}`);
    },

    async 'pages switched off (404): no stream, no clock, a look every 5 minutes; close() leaves nothing behind'() {
        const { live, server, events, advance, timers, wake } = setup({ initial: state([round({ stopwatch: stopwatch('running', { startedAtMs: SERVER_NOW }) })]) });
        live.start();
        await settle();
        server.status = 'gone';
        await advance(1500);
        assert.equal(events.suspended, true);
        assert.equal(wake[wake.length - 1], false);
        const fetches = server.fetches.length;
        await advance(3600_000);
        assert.ok(server.fetches.length - fetches <= 12);

        live.close();
        assert.equal(timers.length, 0);
        assert.equal(events.closed, true);
    },
};

const results = {};

for (const [name, run] of Object.entries(scenarios)) {
    try {
        await run();
        results[name] = 'ok';
    } catch (error) {
        results[name] = String(error?.stack ?? error);
    }
}

process.stdout.write(JSON.stringify(results));
