// Runs the round stopwatch's clock (assets/round_stopwatch_clock.js) through its scenarios and prints
// {scenario: "ok" | error} as JSON - tests/RoundStopwatchScriptTest.php asserts every one is "ok".

import assert from 'node:assert/strict';
import {
    BIG_COUNTDOWN_MS,
    clockParts,
    countdownSeconds,
    createServerClock,
    formatCountdown,
    isNewerState,
    startedAtMs,
    stopwatchStatusAt,
    stopwatchView,
} from '../assets/round_stopwatch_clock.js';

const NOW = Date.parse('2026-11-10T18:00:00Z');
const SECOND = 1000;
const MINUTE = 60 * SECOND;

function stopwatch(status, startedAtMs, extra = {}) {
    return {
        status,
        startedAt: startedAtMs === null ? null : new Date(startedAtMs).toISOString().replace('.000Z', '+00:00'),
        stoppedAt: null,
        minutesLimit: 60,
        startedAtMs,
        stoppedAtMs: null,
        ...extra,
    };
}

const scenarios = {
    async 'a stopwatch that never ran is idle'() {
        assert.equal(stopwatchStatusAt(stopwatch(null, null), NOW), null);
        assert.deepEqual(stopwatchView(stopwatch(null, null), NOW), { phase: 'idle', elapsedMs: 0, countdownMs: 0, limitMs: 60 * MINUTE });
        assert.equal(stopwatchView(null, NOW).phase, 'idle', 'nothing readable');
        assert.equal(stopwatchView({}, NOW).phase, 'idle');
    },

    async 'a scheduled start counts down until its moment'() {
        const view = stopwatchView(stopwatch('scheduled', NOW + 9 * SECOND + 300), NOW);

        assert.equal(view.phase, 'scheduled');
        assert.equal(view.countdownMs, 9 * SECOND + 300);
        assert.equal(view.elapsedMs, 0, 'nothing has run yet');
        assert.equal(stopwatchStatusAt(stopwatch('scheduled', NOW + 1), NOW), 'scheduled');
    },

    async 'a scheduled start runs from its moment - nobody tells the page'() {
        const scheduled = stopwatch('scheduled', NOW);

        assert.equal(stopwatchStatusAt(scheduled, NOW), 'running', 'at the moment itself');
        assert.equal(stopwatchStatusAt(scheduled, NOW - 1), 'scheduled', 'a millisecond before');

        const view = stopwatchView(scheduled, NOW + 75 * SECOND);
        assert.equal(view.phase, 'running');
        assert.equal(view.elapsedMs, 75 * SECOND);
    },

    async 'a scheduled start without its moment never runs'() {
        assert.equal(stopwatchStatusAt({ status: 'scheduled', startedAt: null }, NOW), 'scheduled');
        assert.equal(stopwatchView({ status: 'scheduled', startedAt: null }, NOW).phase, 'idle');
    },

    async 'the time is never negative - a start this device still sees ahead shows 0'() {
        const view = stopwatchView(stopwatch('running', NOW + 400), NOW);

        assert.equal(view.phase, 'running');
        assert.equal(view.elapsedMs, 0);
        assert.deepEqual(clockParts(-5000), { hours: '00', minutes: '00', seconds: '00' });
    },

    async 'the time limit ends the round on every clock'() {
        const view = stopwatchView(stopwatch('running', NOW - 61 * MINUTE), NOW);

        assert.equal(view.phase, 'times_up');
        assert.equal(view.elapsedMs, 60 * MINUTE, 'the clock stops at the limit');

        // A scheduled start whose limit ran out while nobody looked
        assert.equal(stopwatchView(stopwatch('scheduled', NOW - 61 * MINUTE), NOW).phase, 'times_up');
        // A round without a limit runs on
        assert.equal(stopwatchView(stopwatch('running', NOW - 61 * MINUTE, { minutesLimit: 0 }), NOW).phase, 'running');
    },

    async 'a stopped stopwatch shows the time from the start to the stop'() {
        const stopped = stopwatch('stopped', NOW - 10 * MINUTE, { stoppedAtMs: NOW - 4 * MINUTE });
        const view = stopwatchView(stopped, NOW);

        assert.equal(view.phase, 'stopped');
        assert.equal(view.elapsedMs, 6 * MINUTE);

        // Rows from before the stop moment was kept stand where "now" is
        assert.equal(stopwatchView(stopwatch('stopped', NOW - 10 * MINUTE), NOW).elapsedMs, 10 * MINUTE);
    },

    async 'the older shape without milliseconds reads the same'() {
        const old = { status: 'running', startedAt: '2026-11-10T17:58:00+00:00', stoppedAt: null, minutesLimit: 60 };

        assert.equal(startedAtMs(old), NOW - 2 * MINUTE);
        assert.equal(stopwatchView(old, NOW).elapsedMs, 2 * MINUTE);
        assert.equal(startedAtMs({ startedAt: 'not a date' }), null);
    },

    async 'the milliseconds win over the ATOM moment'() {
        assert.equal(startedAtMs({ startedAt: '2026-11-10T17:58:00+00:00', startedAtMs: NOW }), NOW);
    },

    async 'a running clock is shown in whole seconds, rounded down'() {
        assert.deepEqual(clockParts(999), { hours: '00', minutes: '00', seconds: '00' });
        assert.deepEqual(clockParts(3_723_999), { hours: '01', minutes: '02', seconds: '03' });
    },

    async 'a countdown is shown rounded up - 10 to 1, then the start'() {
        assert.equal(countdownSeconds(10 * SECOND), 10);
        assert.equal(countdownSeconds(9 * SECOND + 1), 10);
        assert.equal(countdownSeconds(1), 1);
        assert.equal(countdownSeconds(0), 0);
        assert.equal(countdownSeconds(-500), 0);
        assert.equal(formatCountdown(9 * SECOND), '0:00:09');
        assert.equal(formatCountdown(2 * 3600 * SECOND + 13 * MINUTE + 4 * SECOND + 1), '2:13:05');
    },

    async 'the last ten seconds are the big countdown'() {
        assert.equal(BIG_COUNTDOWN_MS, 10 * SECOND);
        assert.ok(stopwatchView(stopwatch('scheduled', NOW + 10 * SECOND), NOW).countdownMs <= BIG_COUNTDOWN_MS);
        assert.ok(stopwatchView(stopwatch('scheduled', NOW + 10 * SECOND + 1), NOW).countdownMs > BIG_COUNTDOWN_MS);
    },

    async 'the newest read wins - a slow fetch never overwrites a newer live update'() {
        const pushed = { status: 'scheduled', asOfMs: NOW };
        const slowFetch = { status: null, asOfMs: NOW - 300 };

        assert.equal(isNewerState(pushed, slowFetch), false);
        assert.equal(isNewerState(slowFetch, pushed), true);
        assert.equal(isNewerState(pushed, { status: 'running', asOfMs: NOW }), true, 'the same moment replaces');
        assert.equal(isNewerState(pushed, { status: 'running' }), true, 'the older shape always replaces');
        assert.equal(isNewerState({}, pushed), true);
    },

    async 'the clock offset comes from the answer with the shortest round trip'() {
        let local = 1_000_000;
        const clock = createServerClock({ now: () => local });

        clock.hint(5000);
        assert.equal(clock.offsetMs(), 5000, 'the page\'s own server time until a fetch answered');

        // 400 ms round trip: server time 2_000_200 at the local midpoint 1_000_200 → +1_000_000
        assert.ok(clock.observe({ sentAtMs: 1_000_000, receivedAtMs: 1_000_400, serverNowMs: 2_000_200 }));
        assert.equal(clock.offsetMs(), 1_000_000);

        // 40 ms round trip: a better sample
        assert.ok(clock.observe({ sentAtMs: 1_001_000, receivedAtMs: 1_001_040, serverNowMs: 2_001_030 }));
        assert.equal(clock.offsetMs(), 1_000_010);
        assert.equal(clock.bestRttMs(), 40);

        // A slow one later changes nothing
        assert.ok(clock.observe({ sentAtMs: 1_002_000, receivedAtMs: 1_002_900, serverNowMs: 2_002_000 }));
        assert.equal(clock.offsetMs(), 1_000_010);

        local = 1_003_000;
        assert.equal(clock.serverNow(), 2_003_010);
    },

    async 'a sample that makes no sense is left out'() {
        const clock = createServerClock({ now: () => 0 });

        assert.equal(clock.observe({ sentAtMs: 10, receivedAtMs: 5, serverNowMs: 100 }), false, 'negative round trip');
        assert.equal(clock.observe({ sentAtMs: 10, receivedAtMs: 20, serverNowMs: Number.NaN }), false);
        assert.equal(clock.observe({ sentAtMs: 10, receivedAtMs: 20 }), false);
        assert.equal(clock.offsetMs(), 0);
        assert.equal(clock.bestRttMs(), null);
    },

    async 'old samples give way, so a drifting clock is followed - the newest always stays'() {
        const clock = createServerClock({ now: () => 0, windowMs: 10 * MINUTE });

        clock.observe({ sentAtMs: 0, receivedAtMs: 20, serverNowMs: 1010 });
        assert.equal(clock.offsetMs(), 1000);

        // Eleven minutes later a slower sample - the old, faster one is out of the window
        clock.observe({ sentAtMs: 11 * MINUTE, receivedAtMs: 11 * MINUTE + 200, serverNowMs: 11 * MINUTE + 1600 });
        assert.equal(clock.offsetMs(), 1500);
        assert.equal(clock.bestRttMs(), 200);
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
