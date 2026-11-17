// Runs assets/displays/runtime.js with a fake clock, timers, fetch and Mercure stream (competition toolkit D4,
// D-displays-overlays.md §11.2): signal throttling, one fetch in flight, polling while the stream is down, stopwatch
// pushes, refreshAtMs, the fetches around a scheduled start, the invalid link, the reload policy, design swaps, clock
// resync, wake lock - and a leak check (thousands of updates and design swaps keep the timers and DOM nodes constant).
// Prints {"<scenario>": "ok" | "<what failed>"} as JSON (DisplayRuntimeScriptTest).

import { createRequire } from 'node:module';
import { createRenderer } from '../assets/displays/render.js';
import {
    BUSY_DEFAULT_MS, BUSY_JITTER_MS, DisplayRuntime, DOWN_POLL_MS, INVALID_POLL_MS, MAX_UPTIME_MS, SAFETY_POLL_MS, SIGNAL_DEBOUNCE_MS,
} from '../assets/displays/runtime.js';

const require = createRequire(import.meta.url);
const { JSDOM } = require('jsdom');

const START = 1_763_380_000_000;
const results = {};

async function check(name, run) {
    try {
        const outcome = await run();
        results[name] = outcome === undefined || outcome === true ? 'ok' : String(outcome);
    } catch (error) {
        results[name] = `threw ${error?.stack ?? error}`;
    }
}

function assert(condition, message) {
    if (!condition) {
        throw new Error(message);
    }
}

function equal(actual, expected, message) {
    if (JSON.stringify(actual) !== JSON.stringify(expected)) {
        throw new Error(`${message}: ${JSON.stringify(actual)} !== ${JSON.stringify(expected)}`);
    }
}

const flush = async () => {
    for (let i = 0; i < 10; i += 1) {
        await new Promise((resolve) => setImmediate(resolve));
    }
};

function stopwatch(status, startedAtMs, asOfMs = START, stoppedAtMs = null) {
    return { roundId: 'r1', status, startedAtMs, stoppedAtMs, minutesLimit: 60, asOfMs };
}

function makeState(overrides = {}) {
    return {
        kind: 'screen',
        locale: 'en',
        designVersion: 1,
        rendererBuild: 'build-1',
        competition: { name: 'Cup' },
        round: { id: 'r1', name: 'Final', resultsPublished: false, minutesLimit: 60 },
        stopwatch: stopwatch(null, null),
        puzzles: [],
        anyPuzzleRevealed: false,
        refreshAtMs: null,
        results: null,
        images: {},
        qr: {},
        texts: { invalid: 'No longer valid' },
        mercure: { url: 'https://hub.example/.well-known/mercure', topics: ['/competition-display/c1'], token: 't1', expiresIn: 3600 },
        serverNowMs: START,
        ...overrides,
    };
}

/**
 * The fake world: a local clock (`now`), timers due on it, a server whose answers the scenario sets.
 */
function world({ state = makeState(), serverOffsetMs = 0, renderer = null, kind = 'screen' } = {}) {
    const env = {
        now: START,
        timers: new Map(),
        nextId: 1,
        fetches: 0,
        designFetches: 0,
        answer: null,
        designAnswer: { status: 'ok', designVersion: 2, design: { schema: 1, elements: [] } },
        reloads: 0,
        wakeLocks: 0,
        renders: [],
        designs: 0,
        invalid: [],
        events: null,
        serverOffsetMs,
        pending: null,
    };

    env.serverState = (overrides = {}) => ({ ...makeState({ kind }), ...overrides, serverNowMs: env.now + env.serverOffsetMs });

    const fakeRenderer = renderer ?? {
        setDesign() {
            env.designs += 1;
        },
        render(current, nowMs) {
            env.renders.push({ state: current, nowMs });
        },
        nextChangeMs(current) {
            return current.stopwatch?.status === 'running' ? 1000 : null;
        },
        showInvalid(text) {
            env.invalid.push(text);
        },
    };

    env.runtime = new DisplayRuntime({
        state: { ...state, kind },
        design: { schema: 1, elements: [] },
        renderer: fakeRenderer,
        now: () => env.now,
        schedule: (task, ms) => {
            const id = env.nextId;
            env.nextId += 1;
            env.timers.set(id, { at: env.now + ms, task });

            return id;
        },
        cancel: (id) => env.timers.delete(id),
        random: () => 0.5,
        requestWakeLock: () => {
            env.wakeLocks += 1;

            return Promise.resolve({});
        },
        reload: () => {
            env.reloads += 1;
        },
        fetchState: () => {
            env.fetches += 1;
            const sentAtMs = env.now;

            if (env.pending !== null) {
                return env.pending;
            }

            const answer = env.answer ?? { status: 'ok', state: env.serverState() };

            return Promise.resolve(answer.status === 'ok' ? { ...answer, sentAtMs, receivedAtMs: env.now } : answer);
        },
        fetchDesign: () => {
            env.designFetches += 1;

            return Promise.resolve(env.designAnswer);
        },
        createEvents: (options) => {
            env.events = {
                options,
                live: false,
                updates: 0,
                suspended: 0,
                closed: 0,
                start() {},
                update() {
                    this.updates += 1;
                },
                suspend() {
                    this.suspended += 1;
                },
                close() {
                    this.closed += 1;
                },
                isLive() {
                    return this.live;
                },
            };

            return env.events;
        },
    });

    // Moves the local clock, running due timers in order
    env.advance = async (ms) => {
        const until = env.now + ms;

        for (;;) {
            let next = null;

            for (const [id, timer] of env.timers) {
                if (timer.at <= until && (next === null || timer.at < next[1].at)) {
                    next = [id, timer];
                }
            }

            if (next === null) {
                break;
            }

            env.timers.delete(next[0]);
            env.now = Math.max(env.now, next[1].at);
            next[1].task();
            await flush();
        }

        env.now = until;
        await flush();
    };

    return env;
}

await check('signals are throttled: 40 signals in 10 s cost at most 8 fetches', async () => {
    const env = world();
    env.runtime.start();
    env.events.live = true;
    await flush();
    const before = env.fetches;

    for (let i = 0; i < 40; i += 1) {
        env.events.options.onMessage({ type: 'display.signal', what: 'results', roundId: 'r1' });
        await env.advance(250);
    }

    await env.advance(SIGNAL_DEBOUNCE_MS);
    const fetches = env.fetches - before;
    assert(fetches >= 5 && fetches <= 8, `fetches ${fetches}`);
});

await check('one fetch in flight: a signal meanwhile fetches once more after it', async () => {
    const env = world();
    env.runtime.start();
    env.events.live = true;
    let resolve;
    env.pending = new Promise((done) => {
        resolve = done;
    });
    const before = env.fetches;
    env.runtime.refresh();
    env.runtime.refresh();
    env.runtime.refresh();
    await flush();
    equal(env.fetches - before, 1, 'one in flight');
    env.pending = null;
    resolve({ status: 'ok', state: env.serverState(), sentAtMs: env.now, receivedAtMs: env.now });
    await flush();
    await env.advance(SIGNAL_DEBOUNCE_MS);
    equal(env.fetches - before, 2, 'once more after it');
});

await check('polling: every 5 s while the stream is down, every 60 s while live', async () => {
    const env = world();
    env.runtime.start();
    let before = env.fetches;
    await env.advance(DOWN_POLL_MS * 3 + 10);
    equal(env.fetches - before, 3, 'down');
    env.events.live = true;
    await env.advance(DOWN_POLL_MS);
    before = env.fetches;
    await env.advance(SAFETY_POLL_MS * 2 + 10);
    equal(env.fetches - before, 2, 'live');
});

await check('stopwatch push of the shown round applies at once, older ones are dropped, another round re-picks', async () => {
    const env = world();
    env.runtime.start();
    env.events.live = true;
    await flush();
    const before = env.fetches;
    env.events.options.onMessage(stopwatch('running', START - 5_000, START + 10));
    equal(env.renders.at(-1).state.stopwatch.status, 'running', 'applied');
    equal(env.fetches - before, 0, 'no fetch');
    env.events.options.onMessage(stopwatch('stopped', START - 5_000, START - 100, START - 200));
    equal(env.renders.at(-1).state.stopwatch.status, 'running', 'older dropped');
    env.events.options.onMessage({ ...stopwatch('running', START), roundId: 'r2' });
    await env.advance(SIGNAL_DEBOUNCE_MS + 1);
    equal(env.fetches - before, 1, 'another round: fetch');
    // A fetch older than the push keeps the push
    env.answer = { status: 'ok', state: env.serverState({ stopwatch: stopwatch('scheduled', START + 50_000, START - 5_000) }) };
    await env.runtime.refresh();
    equal(env.runtime.state.stopwatch.status, 'running', 'push kept over an older fetch');
});

await check('refreshAtMs: a fetch at the moment (+ jitter)', async () => {
    const env = world();
    env.runtime.start();
    env.events.live = true;
    env.answer = { status: 'ok', state: env.serverState({ refreshAtMs: START + 30_000 }) };
    await env.runtime.refresh();
    env.answer = null;
    const before = env.fetches;
    await env.advance(30_000);
    equal(env.fetches - before, 0, 'not before');
    await env.advance(200);
    equal(env.fetches - before, 1, 'at the moment + jitter');
});

await check('a scheduled start: fetches 2 s before and 1 s after', async () => {
    const env = world();
    env.runtime.start();
    env.events.live = true;
    env.answer = { status: 'ok', state: env.serverState({ stopwatch: stopwatch('scheduled', START + 20_000) }) };
    await env.runtime.refresh();
    const before = env.fetches;
    await env.advance(17_999);
    equal(env.fetches - before, 0, 'nothing yet');
    await env.advance(2);
    equal(env.fetches - before, 1, '2 s before');
    await env.advance(3_000);
    equal(env.fetches - before, 2, '1 s after');
});

await check('the link stops working: invalid view, no stream, a check every 5 minutes; back when it answers again', async () => {
    const env = world();
    env.runtime.start();
    env.answer = { status: 'gone' };
    const kind = await env.runtime.refresh();
    equal(kind, 'forbidden', 'the stream module suspends');
    equal(env.invalid, ['No longer valid'], 'invalid view');
    assert(env.events.suspended === 1, 'stream suspended');
    const before = env.fetches;
    await env.advance(INVALID_POLL_MS - 10);
    equal(env.fetches - before, 0, 'quiet');
    env.answer = null;
    await env.advance(20);
    equal(env.fetches - before, 1, 'checked after 5 min');
    equal(env.invalid.at(-1), null, 'valid again');
    assert(env.events.updates >= 1, 'stream resumed');
});

await check('a 429 keeps the picture, waits Retry-After (+ jitter) - signals and polls meanwhile fetch nothing', async () => {
    const env = world();
    env.runtime.start();
    env.events.live = true;
    await flush();
    const renders = env.renders.length;
    env.answer = { status: 'busy', retryAfterMs: 20_000 };
    equal(await env.runtime.refresh(), 'error', 'the stream module sees an error');
    equal(env.renders.length, renders, 'picture kept');
    equal(env.invalid, [], 'never the invalid view');
    env.answer = null;
    const before = env.fetches;

    for (let i = 0; i < 5; i += 1) {
        env.events.options.onMessage({ type: 'display.signal', what: 'results' });
        await env.advance(1_000);
    }

    equal(await env.runtime.refresh(), 'error', 'a refresh while waiting fetches nothing');
    // random() is 0.5 in the fake world: the wait is 20 s + half the jitter
    const wait = 20_000 + 0.5 * BUSY_JITTER_MS;
    await env.advance(wait - 5_000 - 10);
    equal(env.fetches - before, 0, 'nothing before Retry-After');
    await env.advance(20);
    equal(env.fetches - before, 1, 'one fetch when the wait is over');
    equal(env.runtime.busyUntil, null, 'not limited any more');
    await env.advance(SIGNAL_DEBOUNCE_MS);
    env.events.options.onMessage({ type: 'display.signal', what: 'results' });
    await env.advance(SIGNAL_DEBOUNCE_MS + 1);
    equal(env.fetches - before, 2, 'signals fetch again');
});

await check('a 429 without Retry-After waits the default; a hall of screens comes back spread out', async () => {
    const env = world();
    env.runtime.start();
    env.events.live = true;
    await flush();
    env.answer = { status: 'busy', retryAfterMs: null };
    await env.runtime.refresh();
    env.answer = null;
    const before = env.fetches;
    await env.advance(BUSY_DEFAULT_MS);
    equal(env.fetches - before, 0, 'still waiting');
    await env.advance(BUSY_JITTER_MS);
    equal(env.fetches - before, 1, 'back after the default wait + jitter');
});

await check('a failed fetch keeps the picture and answers error', async () => {
    const env = world();
    env.runtime.start();
    const renders = env.renders.length;
    env.answer = { status: 'error' };
    equal(await env.runtime.refresh(), 'error', 'error');
    equal(env.renders.length, renders, 'picture kept');
    equal(env.invalid, [], 'not invalid');
});

await check('reload after a deploy: never while counting down or running, never after a failed fetch', async () => {
    const env = world({ state: makeState({ stopwatch: stopwatch('running', START - 1_000) }) });
    env.runtime.start();
    env.answer = { status: 'ok', state: env.serverState({ rendererBuild: 'build-2', stopwatch: stopwatch('running', START - 1_000) }) };
    await env.runtime.refresh();
    equal(env.reloads, 0, 'running');
    env.answer = { status: 'ok', state: env.serverState({ rendererBuild: 'build-2', stopwatch: stopwatch('scheduled', env.now + 60_000) }) };
    await env.runtime.refresh();
    equal(env.reloads, 0, 'counting down');
    env.answer = { status: 'error' };
    await env.runtime.refresh();
    equal(env.reloads, 0, 'failed fetch');
    env.answer = { status: 'ok', state: env.serverState({ rendererBuild: 'build-2', stopwatch: stopwatch('stopped', START - 9_000, env.now, START - 1_000) }) };
    await env.runtime.refresh();
    equal(env.reloads, 1, 'stopped: reload');
    equal(env.timers.size, 0, 'everything stopped before the reload');
});

await check('reload after 20 hours, only at a calm moment', async () => {
    const env = world();
    env.runtime.start();
    env.events.live = true;
    env.answer = { status: 'ok', state: env.serverState({ stopwatch: stopwatch('running', START) }) };
    env.now += MAX_UPTIME_MS;
    env.answer = { status: 'ok', state: env.serverState({ stopwatch: stopwatch('running', env.now - 1_000) }) };
    await env.runtime.refresh();
    equal(env.reloads, 0, 'running');
    env.answer = { status: 'ok', state: env.serverState({ stopwatch: stopwatch(null, null) }) };
    await env.runtime.refresh();
    equal(env.reloads, 1, 'idle: reload');
});

await check('a newer design version fetches the design and rebuilds', async () => {
    const env = world();
    env.runtime.start();
    equal(env.designs, 1, 'built once');
    env.answer = { status: 'ok', state: env.serverState({ designVersion: 2 }) };
    await env.runtime.refresh();
    await flush();
    equal(env.designFetches, 1, 'design fetched');
    equal(env.designs, 2, 'rebuilt');
    await env.runtime.refresh();
    equal(env.designFetches, 1, 'same version: not again');
});

await check('the clock follows the server: every fetch is a sample, the shortest round trip wins', async () => {
    const env = world({ serverOffsetMs: 4_000 });
    env.runtime.start();
    await env.runtime.refresh();
    equal(env.runtime.serverNow() - env.now, 4_000, 'offset learnt');
    // A drifting clock: the older samples age out of the window (10 min), the newest one stays
    env.serverOffsetMs = 2_500;
    await env.advance(11 * 60 * 1000);
    await env.runtime.refresh();
    equal(env.renders.at(-1).nowMs - env.now, 2_500, 'drift followed');
});

await check('the stream gets every fresh subscription; a state kind the stream module understands', async () => {
    const env = world();
    env.runtime.start();
    equal(await env.events.options.refresh(), 'ok', 'ok');
    equal(env.events.updates, 1, 'subscription passed on');
});

await check('wake lock: a screen asks at start and when visible again, an overlay never', async () => {
    const screen = world();
    screen.runtime.start();
    screen.runtime.onVisible();
    equal(screen.wakeLocks, 2, 'screen');
    const overlay = world({ kind: 'overlay' });
    overlay.runtime.start();
    overlay.runtime.onVisible();
    equal(overlay.wakeLocks, 0, 'overlay');
});

await check('no leaks: 10,000 state updates and 1,000 design swaps keep the timers and DOM nodes constant', async () => {
    const window = new JSDOM('<!doctype html><html><body><div id="root"></div></body></html>').window;
    const root = window.document.getElementById('root');
    const elements = [
        { id: 'timer001', type: 'timer', frame: { x: 0, y: 0, w: 50, h: 20 } },
        { id: 'leader01', type: 'leaderboard', frame: { x: 50, y: 0, w: 50, h: 80 }, props: { source: 'live', rows: 10 } },
        { id: 'rotate01', type: 'rotator', frame: { x: 0, y: 80, w: 50, h: 20 }, props: { seconds: 3 } },
    ];
    const renderer = createRenderer({ document: window.document, root, design: { schema: 1, elements }, competitionId: 'c1', kind: 'screen' });
    const env = world({ renderer, state: makeState({ stopwatch: stopwatch('running', START - 1_000) }) });
    env.runtime.start();
    env.events.live = true;
    const row = (i) => ({ key: `k${i % 25}`, rank: (i % 10) + 1, name: `N${i % 25}`, members: [], countries: [], resultSeconds: 100 + i, piecesPlaced: null, didNotFinish: false, tableNumber: 1, enteredAtMs: START });
    let maxTimers = 0;
    let nodesAfterWarmup = null;

    for (let i = 0; i < 10_000; i += 1) {
        const top = Array.from({ length: 10 }, (_, j) => row(i + j));
        env.answer = { status: 'ok', state: env.serverState({ designVersion: 1 + Math.floor(i / 10), stopwatch: stopwatch(i % 3 === 0 ? 'running' : 'scheduled', env.now + 5_000, env.now), results: { published: false, finished: 1, total: 2, top, latest: top } }) };
        env.designAnswer = { status: 'ok', designVersion: 1 + Math.floor(i / 10), design: { schema: 1, elements: i % 20 < 10 ? elements : elements.slice(0, 2) } };
        await env.runtime.refresh();
        env.events.options.onMessage(stopwatch('running', env.now - 1_000, env.now + 1));
        env.events.options.onMessage({ type: 'display.signal', what: 'results' });
        env.now += 37;
        maxTimers = Math.max(maxTimers, env.timers.size);

        if (i === 100) {
            nodesAfterWarmup = root.querySelectorAll('*').length;
        }
    }

    assert(maxTimers <= 7, `timers ${maxTimers}`);
    equal(env.runtime.activeTimers(), env.timers.size, 'every timer in a slot');
    assert(env.designFetches >= 900, `design swaps ${env.designFetches}`);
    const nodes = root.querySelectorAll('*').length;
    assert(Math.abs(nodes - nodesAfterWarmup) <= 40, `nodes ${nodesAfterWarmup} → ${nodes}`);
    env.runtime.close();
    equal(env.timers.size, 0, 'closed: no timer left');
    equal(env.events.closed, 1, 'stream closed');
});

process.stdout.write(JSON.stringify(results));
