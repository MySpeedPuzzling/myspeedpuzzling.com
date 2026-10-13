// Runs assets/online/clock_sync.js - the server's time on a participant's phone - through its scenarios against a
// simulated world (a true time, the phone's performance.now() and Date.now(), the server's clock, a network with its
// own delays each way) and prints {scenario: "ok" | error} as JSON. tests/OnlineClockScriptTest.php asserts every one
// is "ok".

import assert from 'node:assert/strict';
import {
    CONFIRMATIONS,
    createClock,
    fetchServerTime,
    JUMP_THRESHOLD_MS,
    READY_RTT_MS,
    SLEW_RATE,
    SYNC_SAMPLES,
} from '../assets/online/clock_sync.js';

const START = 1_790_000_000_000;
const PERF_AT_START = 50_000;

async function settle() {
    for (let i = 0; i < 20; i++) {
        await new Promise((resolve) => setImmediate(resolve));
    }
}

/**
 * The world: `trueMs` moves on with advance(); performance.now() moves with it (at `perfRate`) except while asleep;
 * Date.now() is the true time plus the phone's own error; the server's clock is the true time plus `serverAheadMs`.
 * fetchTime() is one request: `up` ms to the server, which stamps its whole milliseconds, `down` ms back - per request
 * from `queue`, else `delay`. Optional per request: `queueMs` (waited inside the browser before it left), `tailMs`
 * (after the response started, reading the body) and `sleepMs` (the phone slept while the request was out).
 */
function simulation({ serverAheadMs = 0, wallAheadMs = 0, perfRate = 1, delay = { up: 20, down: 20 } } = {}) {
    const sim = {
        trueMs: START,
        perfMs: PERF_AT_START,
        wallSkewMs: wallAheadMs,
        serverAheadMs,
        delay,
        queue: [],
        requests: 0,
        failNext: 0,
        advance(ms) {
            sim.trueMs += ms;
            sim.perfMs += ms * perfRate;
        },
        sleep(ms) {
            sim.trueMs += ms;
        },
        perf: () => sim.perfMs,
        wall: () => Math.floor(sim.trueMs + sim.wallSkewMs),
        server: () => sim.trueMs + sim.serverAheadMs,
        async fetchTime() {
            sim.requests += 1;
            await null;

            if (sim.failNext > 0) {
                sim.failNext -= 1;
                throw new TypeError('Failed to fetch');
            }

            const { up, down, queueMs = 0, tailMs = 0, sleepMs = 0, timing = false } = sim.queue.shift() ?? sim.delay;
            sim.advance(queueMs);
            const requestStart = sim.perfMs;
            sim.advance(up);
            const stamped = Math.floor(sim.server());
            sim.sleep(sleepMs);
            sim.advance(down);
            const responseStart = sim.perfMs;
            sim.advance(tailMs);

            return timing ? { serverNowMs: stamped, t0: requestStart, t1: responseStart } : stamped;
        },
        async wait(ms) {
            sim.advance(ms);
        },
    };

    return sim;
}

function makeClock(sim, options = {}) {
    const jumps = [];
    const clock = createClock({
        fetchTime: () => sim.fetchTime(),
        now: sim.perf,
        wallNow: sim.wall,
        timeOrigin: START - PERF_AT_START,
        wait: (ms) => sim.wait(ms),
        onJump: (jump) => jumps.push(jump),
        ...options,
    });

    return { clock, jumps };
}

/**
 * One free sample as a page takes it around an API request.
 */
function heartbeat(sim, clock, { up = 20, down = 20 } = {}) {
    const t0 = sim.perf();
    sim.advance(up);
    const serverNowMs = Math.floor(sim.server());
    sim.advance(down);

    return clock.observe({ t0, t1: sim.perf(), serverNowMs });
}

function errorOf(clock, sim) {
    return clock.serverNow() - sim.server();
}

/**
 * A small deterministic random sequence (the network's jitter).
 */
function random(seed) {
    let state = seed;

    return () => {
        state = (state * 1103515245 + 12345) % 2147483648;

        return state / 2147483648;
    };
}

const scenarios = {
    async 'the sample with the smallest round trip wins - the first, slow one (connection set-up) is left out'() {
        const sim = simulation({ serverAheadMs: 2500 });
        sim.queue.push(
            { up: 300, down: 300 },
            { up: 40, down: 40 },
            { up: 15, down: 15 },
            { up: 60, down: 60 },
            { up: 25, down: 25 },
            { up: 35, down: 35 },
            { up: 50, down: 50 },
            { up: 20, down: 20 },
        );
        const { clock } = makeClock(sim);

        // Before any sample: the phone's own clock
        assert.equal(clock.serverNow(), sim.wall());

        const quality = await clock.sync();

        assert.equal(sim.requests, SYNC_SAMPLES);
        assert.equal(quality.samples, SYNC_SAMPLES);
        assert.equal(quality.rttMs, 30);
        assert.ok(Math.abs(quality.offsetMs - 2500) <= 1, `offset ${quality.offsetMs}`);
        assert.ok(Math.abs(errorOf(clock, sim)) <= 1, `error ${errorOf(clock, sim)}`);
        assert.equal(quality.synced, true);
        assert.equal(quality.settled, true);
        assert.equal(quality.slewing, false, 'the first sync applies at once');
    },

    async 'an asymmetric route can not be seen - its error stays within half the round trip'() {
        // 5 ms up, 55 ms down: the midpoint is 25 ms late
        const sim = simulation({ serverAheadMs: -800, delay: { up: 5, down: 55 } });
        const { clock } = makeClock(sim);
        const quality = await clock.sync();
        const error = errorOf(clock, sim);

        assert.equal(quality.rttMs, 60);
        assert.ok(Math.abs(error + 25) <= 1, `error ${error}`);
        assert.ok(Math.abs(error) <= quality.rttMs / 2, 'bounded by rtt / 2');
        assert.ok(quality.errorMs >= Math.abs(error) - 1, 'the bound it reports holds');
    },

    async 'a sleep: performance.now() stood still - the clock moves on at once, empties its window and syncs again'() {
        const sim = simulation({ serverAheadMs: 1200 });
        const { clock, jumps } = makeClock(sim);
        await clock.sync();
        const before = sim.requests;

        sim.advance(3000);
        sim.sleep(60_000);
        sim.advance(16);

        // Right away - before any new sample - the time is right (by the wall clock's word)
        assert.ok(Math.abs(errorOf(clock, sim)) <= 2, `error ${errorOf(clock, sim)}`);
        assert.equal(jumps.length, 1);
        assert.ok(Math.abs(jumps[0].deltaMs - 60_000) <= 1, `delta ${jumps[0].deltaMs}`);
        const right = clock.quality();
        assert.equal(right.samples, 0, 'the old samples are gone');
        assert.equal(right.synced, false, 'not ready until a new sample');
        assert.equal(right.settled, false);

        await settle();

        assert.equal(sim.requests, before + SYNC_SAMPLES, 'synced again by itself');
        const after = clock.quality();
        assert.equal(after.synced, true);
        assert.equal(after.samples, SYNC_SAMPLES);
        assert.ok(Math.abs(errorOf(clock, sim)) <= 20, `error ${errorOf(clock, sim)}`);
    },

    async 'a sample the phone slept through is never taken'() {
        const sim = simulation({ serverAheadMs: 500 });
        const { clock } = makeClock(sim);
        await clock.sync();
        const offset = clock.quality().offsetMs;

        // Stamped, then the phone sleeps 30 s, then the answer arrives - 40 ms on performance.now()
        sim.queue.push({ up: 20, down: 20, sleepMs: 30_000 });
        await clock.sync({ samples: 1 });
        await settle();

        // The sleep was seen (the window emptied and refilled by the sync it started) - the slept-through sample, which
        // would put the clock 30 s back, is not among them
        assert.ok(Math.abs(errorOf(clock, sim)) <= 20, `error ${errorOf(clock, sim)}`);
        assert.ok(Math.abs(clock.quality().offsetMs - (offset + 30_000)) <= 25);

        // The same through observe(): began before the sleep
        const t0 = sim.perf();
        sim.advance(20);
        sim.sleep(45_000);
        const stamped = Math.floor(sim.server());
        sim.advance(20);
        assert.equal(clock.observe({ t0, t1: sim.perf(), serverNowMs: stamped }), false);
        assert.ok(Math.abs(errorOf(clock, sim)) <= 20);
    },

    async 'small corrections are slewed - the stopwatch never jumps nor goes back; big ones apply at once'() {
        const sim = simulation({ serverAheadMs: 0 });
        const { clock } = makeClock(sim);
        await clock.sync();
        assert.ok(Math.abs(errorOf(clock, sim)) <= 1);

        // The server's clock is 200 ms further on (stepped by its NTP): two samples say so - the second confirms it
        sim.serverAheadMs += 200;
        assert.equal(heartbeat(sim, clock), false, 'one sample alone is not believed');
        await settle();
        assert.equal(clock.quality().slewing, true, 'confirmed - and slewed');

        let previous = clock.serverNow();
        let maxStep = 0;
        let minStep = Infinity;

        for (let step = 0; step < 40; step++) {
            sim.advance(100);
            const shown = clock.serverNow();
            maxStep = Math.max(maxStep, shown - previous);
            minStep = Math.min(minStep, shown - previous);
            previous = shown;
        }

        assert.ok(minStep >= 100 * (1 - SLEW_RATE) - 0.001, `slowest step ${minStep}`);
        assert.ok(maxStep <= 100 * (1 + SLEW_RATE) + 0.001, `fastest step ${maxStep}`);
        assert.ok(Math.abs(errorOf(clock, sim)) <= 2, `caught up: ${errorOf(clock, sim)}`);
        assert.equal(clock.quality().slewing, false);

        // 3 s off: no point in slewing for 30 s - it applies at once
        sim.serverAheadMs += 3000;
        heartbeat(sim, clock);
        await settle();
        assert.equal(clock.quality().slewing, false);
        assert.ok(Math.abs(errorOf(clock, sim)) <= 2, `error ${errorOf(clock, sim)}`);
    },

    async 'Date.now() three minutes off: the server time is right all the same, and the phone\'s error is reported'() {
        const sim = simulation({ serverAheadMs: 40, wallAheadMs: 180_000 });
        // The default origin (Date.now() − performance.now() at creation), as on a page
        const clock = createClock({ fetchTime: () => sim.fetchTime(), now: sim.perf, wallNow: sim.wall, wait: (ms) => sim.wait(ms) });

        assert.ok(Math.abs(clock.serverNow() - sim.server() - 179_960) <= 1, 'before a sample: the phone\'s own clock');
        await clock.sync();

        assert.ok(Math.abs(errorOf(clock, sim)) <= 1);
        assert.ok(Math.abs(clock.quality().wallOffsetMs + 179_960) <= 2, `wall offset ${clock.quality().wallOffsetMs}`);
        assert.ok(Math.abs(clock.stamp().clientMs - sim.server()) <= 1);
    },

    async 'performance.now() drifting from the server for 30 minutes: heartbeat samples keep it right, slewed, never backwards'() {
        // The phone's monotonic clock runs 150 ppm fast; heartbeats every 15 s over a jittery mobile network
        const sim = simulation({ serverAheadMs: 7000, perfRate: 1 + 150e-6 });
        const { clock, jumps } = makeClock(sim);
        await clock.sync();
        const jitter = random(7);
        let previous = clock.serverNow();
        let worst = 0;

        for (let beat = 0; beat < 120; beat++) {
            for (let step = 0; step < 30; step++) {
                sim.advance(500);
                const shown = clock.serverNow();
                assert.ok(shown > previous, 'never backwards');
                previous = shown;
                worst = Math.max(worst, Math.abs(shown - sim.server()));
            }

            heartbeat(sim, clock, { up: 10 + Math.round(jitter() * 60), down: 10 + Math.round(jitter() * 60) });
            await settle();
        }

        assert.equal(jumps.length, 0, 'a slow drift is no jump');
        assert.ok(worst <= 50, `worst error ${worst} ms`);
        assert.ok(Math.abs(errorOf(clock, sim)) <= 40);
    },

    async 'the wall clock set back an hour moves nothing - the samples stay, a sync confirms'() {
        const sim = simulation({ serverAheadMs: 300 });
        const { clock, jumps } = makeClock(sim);
        await clock.sync();
        const before = sim.requests;

        sim.wallSkewMs -= 3_600_000;
        sim.advance(16);

        assert.ok(Math.abs(errorOf(clock, sim)) <= 1, `error ${errorOf(clock, sim)}`);
        assert.equal(jumps.length, 1);
        assert.ok(jumps[0].deltaMs < -JUMP_THRESHOLD_MS);
        assert.equal(clock.quality().samples, SYNC_SAMPLES, 'performance.now() did not move - the samples are good');
        assert.ok(Math.abs(clock.quality().wallOffsetMs - 3_600_300) <= 2);

        await settle();
        assert.ok(sim.requests > before, 'confirmed by a sync');
        assert.ok(Math.abs(errorOf(clock, sim)) <= 1);
    },

    async 'one odd sample (a stale cached answer) is not believed; two that agree are'() {
        const sim = simulation({ serverAheadMs: 0 });
        const { clock } = makeClock(sim);
        await clock.sync();
        const before = sim.requests;

        // 2 ms round trip, 30 s old: the best round trip ever - and wrong
        const t1 = sim.perf();
        assert.equal(clock.observe({ t0: t1 - 2, t1, serverNowMs: Math.floor(sim.server()) - 30_000 }), false);
        assert.ok(Math.abs(errorOf(clock, sim)) <= 1, 'not taken');

        await settle();
        assert.equal(sim.requests, before + CONFIRMATIONS, 'a confirmation was asked for at once');
        assert.ok(Math.abs(errorOf(clock, sim)) <= 1, 'the confirmation sided with the window');
        assert.equal(clock.quality().samples, SYNC_SAMPLES + CONFIRMATIONS);

        // Now the server's clock really moved by 2 s: once confirmed it applies (at once - too big to slew)
        sim.serverAheadMs += 2000;
        await clock.sync({ samples: 2 });
        assert.ok(Math.abs(errorOf(clock, sim)) <= 1, `error ${errorOf(clock, sim)}`);
        assert.equal(clock.quality().samples, 2, 'the old samples went with the old clock');
    },

    async 'Resource Timing\'s request and response start are used when they lie inside the request'() {
        const sim = simulation({ serverAheadMs: 900 });
        // 150 ms waiting inside the browser, 25 ms each way, 100 ms reading the body: 300 ms outside, 50 ms inside
        for (let index = 0; index < SYNC_SAMPLES; index++) {
            sim.queue.push({ queueMs: 150, up: 25, down: 25, tailMs: 100, timing: true });
        }

        const { clock } = makeClock(sim);
        const quality = await clock.sync();

        assert.equal(quality.rttMs, 50);
        assert.ok(Math.abs(errorOf(clock, sim)) <= 1);

        // Timing values outside the request (another request's entry) are not trusted - the measured window is
        const other = simulation({ serverAheadMs: 900 });
        other.fetchTime = async () => {
            await null;
            other.advance(40);
            const stamped = Math.floor(other.server());
            other.advance(40);

            return { serverNowMs: stamped, t0: other.perf() - 5000, t1: other.perf() - 4990 };
        };
        const second = makeClock(other).clock;
        const measured = await second.sync({ samples: 1 });
        assert.equal(measured.rttMs, 80);
    },

    async 'stamp() and toServerMs(): event and frame times on the estimate, whole milliseconds for clientMs'() {
        const sim = simulation({ serverAheadMs: -1234 });
        const { clock } = makeClock(sim);
        await clock.sync();
        const tap = sim.perf();
        sim.advance(250);

        const stamp = clock.stamp(tap);
        assert.equal(Number.isInteger(stamp.clientMs), true);
        assert.ok(Math.abs(stamp.clientMs - (sim.server() - 250)) <= 1, `clientMs ${stamp.clientMs}`);
        assert.equal(stamp.perfMs, tap);
        assert.equal(stamp.offsetMs, clock.quality().offsetMs);
        assert.equal(stamp.rttMs, clock.quality().rttMs);
        assert.ok(Math.abs(clock.toServerMs(tap - 1000) - (clock.toServerMs(tap) - 1000)) < 1e-6);
        assert.ok(Math.abs(clock.stamp().clientMs - sim.server()) <= 1, 'now by default');
    },

    async 'sync(): one at a time; failed requests are skipped; without any answer the phone\'s own clock stands in'() {
        const sim = simulation({ serverAheadMs: 5000 });
        const { clock } = makeClock(sim);
        sim.failNext = 100;

        const first = clock.sync();
        const second = clock.sync();
        assert.equal(first, second, 'a call while one runs gets the running one');
        const quality = await first;

        assert.equal(sim.requests, SYNC_SAMPLES);
        assert.equal(quality.samples, 0);
        assert.equal(quality.synced, false);
        assert.equal(quality.offsetMs, null);
        assert.equal(quality.rttMs, null);
        assert.equal(clock.serverNow(), sim.wall());

        // Half of them fail: the rest is enough
        sim.failNext = 4;
        const later = await clock.sync();
        assert.equal(later.samples, SYNC_SAMPLES - 4);
        assert.equal(later.synced, true);
        assert.ok(Math.abs(errorOf(clock, sim)) <= 1);
    },

    async 'the readiness gate: round trips over a second do not make the phone ready'() {
        const sim = simulation({ delay: { up: 600, down: 600 } });
        const { clock } = makeClock(sim);
        const slow = await clock.sync();

        assert.equal(slow.samples, SYNC_SAMPLES);
        assert.ok(slow.rttMs > READY_RTT_MS);
        assert.equal(slow.synced, false);

        heartbeat(sim, clock, { up: 100, down: 100 });
        assert.equal(clock.quality().synced, true);
    },

    async 'an old fast sample gives way to fresh ones as it ages; samples older than the window go'() {
        const sim = simulation({ serverAheadMs: 100, delay: { up: 5, down: 5 } });
        const { clock } = makeClock(sim);
        await clock.sync();
        assert.equal(clock.quality().rttMs, 10);

        // 5 ms + 4 min × 50 ppm = 17 ms beats a fresh 40 ms round trip's 20 ms
        sim.advance(4 * 60_000);
        heartbeat(sim, clock, { up: 20, down: 20 });
        assert.equal(clock.quality().rttMs, 10, 'four minutes old: still the better bound');

        // 5 ms + 10 min × 50 ppm = 35 ms does not
        sim.advance(6 * 60_000);
        heartbeat(sim, clock, { up: 20, down: 20 });
        assert.equal(clock.quality().rttMs, 40, 'ten minutes old: the fresh one wins');
        assert.ok(clock.quality().ageMs < 100);

        sim.advance(6 * 60_000);
        heartbeat(sim, clock, { up: 20, down: 20 });
        assert.equal(clock.quality().samples, 3, 'the first sync\'s samples are older than 15 minutes - the heartbeats stay');
    },

    async 'observe() refuses what can not be a sample'() {
        const sim = simulation();
        const { clock } = makeClock(sim);
        const now = sim.perf();

        assert.equal(clock.observe({ t0: now, t1: now - 1, serverNowMs: START }), false, 'ends before it starts');
        assert.equal(clock.observe({ t0: now - 10, t1: now, serverNowMs: Number.NaN }), false);
        assert.equal(clock.observe({ t0: now - 20_000, t1: now, serverNowMs: START }), false, 'a 20 s round trip says nothing');
        assert.equal(clock.observe({ t0: now - 10, t1: now + 500, serverNowMs: START }), false, 'from the future');
        assert.equal(clock.observe({}), false);
        assert.equal(clock.quality().samples, 0);
        assert.equal(clock.observe({ t0: now - 10, t1: now, serverNowMs: START + 1000, w0: 0, w1: 5000 }), false, 'the wall clock saw a sleep');
        assert.equal(clock.observe({ t0: now - 10, t1: now, serverNowMs: Math.floor(sim.server()) }), true);
    },

    async 'a page listener that throws never breaks the clock'() {
        const sim = simulation({ serverAheadMs: 10 });
        const clock = createClock({
            fetchTime: () => sim.fetchTime(),
            now: sim.perf,
            wallNow: sim.wall,
            timeOrigin: START - PERF_AT_START,
            wait: (ms) => sim.wait(ms),
            onJump: () => {
                throw new Error('listener');
            },
            onChange: () => {
                throw new Error('listener');
            },
        });
        await clock.sync();
        sim.sleep(10_000);

        assert.ok(Math.abs(errorOf(clock, sim)) <= 2);
        await settle();
        assert.ok(Math.abs(errorOf(clock, sim)) <= 1);
    },

    async 'closed: no more requests'() {
        const sim = simulation();
        const { clock } = makeClock(sim);
        await clock.sync();
        const before = sim.requests;
        clock.close();

        sim.sleep(60_000);
        clock.serverNow();
        await clock.sync();
        await settle();

        assert.equal(sim.requests, before);
    },

    async 'fetchServerTime(): no cache, no cookies, its own URL - and Resource Timing when the browser has it'() {
        const calls = [];
        let clock = 1000;
        const performance = {
            now: () => {
                clock += 7;

                return clock;
            },
            entries: new Map(),
            getEntriesByName(name, type) {
                calls.push(['entries', name, type]);

                return performance.entries.get(name) ?? [];
            },
        };
        const fetch = async (url, init) => {
            calls.push(['fetch', url, init]);
            performance.entries.set(url, [{ requestStart: clock + 2, responseStart: clock + 30 }]);

            return { ok: true, status: 200, json: async () => ({ serverNowMs: 1_790_000_000_123 }) };
        };

        const answer = await fetchServerTime('/-/time', { fetch, performance, baseUrl: 'https://myspeedpuzzling.test/en/compete/x' });
        const [, url, init] = calls.find((call) => call[0] === 'fetch');

        assert.match(url, /^https:\/\/myspeedpuzzling\.test\/-\/time\?n=\d+$/);
        assert.equal(init.cache, 'no-store');
        assert.equal(init.credentials, 'omit');
        assert.equal(answer.serverNowMs, 1_790_000_000_123);
        assert.equal(answer.t1 - answer.t0, 28);
        assert.deepEqual(calls.find((call) => call[0] === 'entries').slice(1), [url, 'resource']);

        // A second request has a URL of its own
        await fetchServerTime('/-/time', { fetch, performance, baseUrl: 'https://myspeedpuzzling.test/' });
        const urls = calls.filter((call) => call[0] === 'fetch').map((call) => call[1]);
        assert.notEqual(urls[0], urls[1]);

        // No timing entry (a full buffer): the time alone - the clock measures the request itself
        const plain = await fetchServerTime('/-/time', {
            fetch: async () => ({ ok: true, status: 200, json: async () => ({ serverNowMs: 5 }) }),
            performance: { now: () => 1, getEntriesByName: () => [] },
            baseUrl: 'https://myspeedpuzzling.test/',
        });
        assert.deepEqual(plain, { serverNowMs: 5 });

        // An entry from before the request is somebody else's
        const stale = await fetchServerTime('/-/time', {
            fetch: async () => ({ ok: true, status: 200, json: async () => ({ serverNowMs: 5 }) }),
            performance: { now: () => 500, getEntriesByName: () => [{ requestStart: 100, responseStart: 120 }] },
            baseUrl: 'https://myspeedpuzzling.test/',
        });
        assert.deepEqual(stale, { serverNowMs: 5 });

        await assert.rejects(fetchServerTime('/-/time', {
            fetch: async () => ({ ok: false, status: 503, json: async () => ({}) }),
            performance: { now: () => 1 },
            baseUrl: 'https://myspeedpuzzling.test/',
        }));
        await assert.rejects(fetchServerTime('/-/time', {
            fetch: async () => ({ ok: true, status: 200, json: async () => ({ serverNow: '2026-10-13T10:00:00+00:00' }) }),
            performance: { now: () => 1 },
            baseUrl: 'https://myspeedpuzzling.test/',
        }));
    },

    async 'any answer carrying serverNowMs is a sample - the official results API shape too'() {
        const sim = simulation({ serverAheadMs: 321 });
        const clock = createClock({
            fetchTime: async () => {
                await null;
                sim.advance(15);
                const stamped = Math.floor(sim.server());
                sim.advance(15);

                return { kind: 'ok', status: 200, data: { serverNowMs: stamped } };
            },
            now: sim.perf,
            wallNow: sim.wall,
            timeOrigin: START - PERF_AT_START,
            wait: (ms) => sim.wait(ms),
        });

        const quality = await clock.sync();
        assert.equal(quality.samples, SYNC_SAMPLES);
        assert.ok(Math.abs(errorOf(clock, sim)) <= 1);

        const refused = createClock({
            fetchTime: async () => ({ kind: 'server', status: 503 }),
            now: sim.perf,
            wallNow: sim.wall,
            wait: (ms) => sim.wait(ms),
        });
        assert.equal((await refused.sync()).samples, 0);
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
