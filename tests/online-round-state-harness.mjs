// Runs assets/online/round_state.js - what a phone shows for a round - through its scenarios and prints
// {scenario: "ok" | error} as JSON. tests/OnlineRoundStateScriptTest.php asserts every one is "ok".

import assert from 'node:assert/strict';
import {
    adoptRoundClock,
    adoptState,
    COUNTDOWN_MS,
    countdownDigit,
    deriveView,
    formatClock,
    newer,
    nextStateCheckAt,
    readClock,
    resultSeconds,
} from '../assets/online/round_state.js';

const ROUND = '018d0020-0000-0000-0000-000000000101';
const OTHER_ROUND = '018d0020-0000-0000-0000-000000000102';
// The start on a whole second, an hour limit
const START = 1_792_000_000_000;
const HOUR = 3_600_000;

/**
 * A participant state (implementation-plan.md §3.1) with its round clock.
 */
function state({ startedAtMs = null, stoppedAtMs = null, minutesLimit = 60, asOfMs = START - 60_000, round = {} } = {}) {
    return {
        serverNowMs: asOfMs + 3,
        asOfMs,
        participant: { id: 'p', name: 'Jo Doe', connectedPlayer: null },
        competition: { id: 'c', name: 'Test Cup' },
        round: {
            id: ROUND,
            name: 'Final',
            minutesLimit,
            piecesTotal: 500,
            startedAtMs,
            stoppedAtMs,
            stateHash: `h-${startedAtMs}-${stoppedAtMs}`,
            settings: { detection: 'off', video: false, seeEachOther: false, studio: false, unfinishedMeasure: 'left' },
            ...round,
        },
        entry: { confirmedMomentMs: null, candidates: 0, taps: 0, reportedPieces: null, review: 'pending' },
        mercure: null,
    };
}

const scenarios = {
    async 'no start: waiting at 00:00:00 with the whole limit ahead'() {
        const view = deriveView(state(), START);

        assert.deepEqual(view, {
            screen: 'waiting',
            countdownMs: null,
            elapsedMs: 0,
            remainingMs: HOUR,
            startedAtMs: null,
            endsAtMs: null,
            endedAtMs: null,
            endedBy: null,
            limitMs: HOUR,
        });
        assert.equal(formatClock(view.elapsedMs), '00:00:00');
    },

    async 'a start hours away: scheduled, the time to it shown rounded up'() {
        const view = deriveView(state({ startedAtMs: START }), START - 2 * HOUR - 13 * 60_000 - 4_200);

        assert.equal(view.screen, 'scheduled');
        assert.equal(view.countdownMs, 2 * HOUR + 13 * 60_000 + 4_200);
        assert.equal(formatClock(view.countdownMs, { ceil: true }), '02:13:05');
        assert.equal(view.elapsedMs, 0);
        assert.equal(view.remainingMs, HOUR);
        assert.equal(view.startedAtMs, START);
        assert.equal(view.endsAtMs, START + HOUR);
    },

    async 'the last 10 s are the countdown: 10 … 1, then GO - running from the start\'s very millisecond'() {
        const round = state({ startedAtMs: START });

        assert.equal(deriveView(round, START - COUNTDOWN_MS - 1).screen, 'scheduled');
        assert.equal(deriveView(round, START - COUNTDOWN_MS).screen, 'countdown');

        const digits = [[10000, 10], [9001, 10], [9000, 9], [3000, 3], [2999, 3], [1000, 1], [1, 1]];
        for (const [before, digit] of digits) {
            const view = deriveView(round, START - before);
            assert.equal(view.screen, 'countdown', `${before} ms before`);
            assert.equal(view.countdownMs, before);
            assert.equal(countdownDigit(view.countdownMs), digit, `${before} ms before`);
        }

        const go = deriveView(round, START);
        assert.equal(go.screen, 'running');
        assert.equal(go.countdownMs, 0);
        assert.equal(go.elapsedMs, 0);
        assert.equal(countdownDigit(go.countdownMs), 0);
        assert.equal(countdownDigit(-500), 0);
        assert.equal(countdownDigit(Number.NaN), 0);
    },

    async 'running: elapsed rounded down like the official time, remaining to the limit'() {
        const view = deriveView(state({ startedAtMs: START }), START + 47 * 60_000 + 12_999);

        assert.equal(view.screen, 'running');
        assert.equal(view.elapsedMs, 47 * 60_000 + 12_999);
        assert.equal(formatClock(view.elapsedMs), '00:47:12');
        assert.equal(view.remainingMs, HOUR - (47 * 60_000 + 12_999));
        assert.equal(formatClock(view.remainingMs, { ceil: true }), '00:12:48');
        assert.equal(view.endedAtMs, null);
    },

    async 'the time limit ends the round by itself, the time frozen at the limit'() {
        const round = state({ startedAtMs: START });

        assert.equal(deriveView(round, START + HOUR - 1).screen, 'running');

        for (const now of [START + HOUR, START + HOUR + 5 * 60_000]) {
            const view = deriveView(round, now);
            assert.equal(view.screen, 'ended');
            assert.equal(view.endedBy, 'limit');
            assert.equal(view.elapsedMs, HOUR);
            assert.equal(view.remainingMs, 0);
            assert.equal(view.endedAtMs, START + HOUR);
            assert.equal(formatClock(view.elapsedMs), '01:00:00');
        }
    },

    async '"End now": ended at the stop, frozen there - even before the phone\'s own clock reaches it'() {
        const stoppedAtMs = START + 30 * 60_000;
        const round = state({ startedAtMs: START, stoppedAtMs });

        for (const now of [stoppedAtMs - 40, stoppedAtMs, stoppedAtMs + HOUR]) {
            const view = deriveView(round, now);
            assert.equal(view.screen, 'ended', `at ${now - stoppedAtMs}`);
            assert.equal(view.endedBy, 'stop');
            assert.equal(view.elapsedMs, 30 * 60_000);
            assert.equal(view.endedAtMs, stoppedAtMs);
            assert.equal(view.remainingMs, 0);
        }

        // Stopped after the limit had already ended it: the limit counts
        const late = deriveView(state({ startedAtMs: START, stoppedAtMs: START + HOUR + 90_000 }), START + 2 * HOUR);
        assert.equal(late.endedBy, 'limit');
        assert.equal(late.elapsedMs, HOUR);

        // Ended before it began: nothing elapsed
        const early = deriveView(state({ startedAtMs: START, stoppedAtMs: START - 5000 }), START - 4000);
        assert.equal(early.screen, 'ended');
        assert.equal(early.elapsedMs, 0);
    },

    async 'a late joiner lands on the running clock at the right time - never on a countdown'() {
        // The page opened 5 minutes after GO: its first state already has the start in the past
        const joined = state({ startedAtMs: START, asOfMs: START + 5 * 60_000 });
        const view = deriveView(joined, START + 5 * 60_000 + 350);

        assert.equal(view.screen, 'running');
        assert.equal(formatClock(view.elapsedMs), '00:05:00');

        // Opened 4 s before GO: the countdown from 4
        const almost = deriveView(state({ startedAtMs: START, asOfMs: START - 4100 }), START - 4000);
        assert.equal(almost.screen, 'countdown');
        assert.equal(countdownDigit(almost.countdownMs), 4);
    },

    async 'a cancelled countdown: the newer state goes back to waiting; an older fetch arriving late never brings it back'() {
        const scheduled = state({ startedAtMs: START, asOfMs: START - 12_000 });
        let held = scheduled;
        assert.equal(deriveView(held, START - 7000).screen, 'countdown');

        // The organiser cancels 6 s before GO - the push
        const cancel = { status: null, startedAt: null, stoppedAt: null, startedAtMs: null, stoppedAtMs: null, minutesLimit: 60, asOfMs: START - 6000, stateHash: 'h-null', serverNowMs: START - 5998 };
        held = adoptRoundClock(held, cancel);
        assert.equal(deriveView(held, START - 5500).screen, 'waiting');
        assert.equal(held.round.clockAsOfMs, START - 6000);

        // A fetch read before the cancel arrives now: newer than the state held, older than the cancel - its other
        // parts are taken, the cancelled clock stays
        const stale = state({ startedAtMs: START, asOfMs: START - 6500 });
        const merged = adoptState(held, stale);
        assert.equal(merged.asOfMs, START - 6500);
        assert.equal(merged.round.startedAtMs, null);
        assert.equal(merged.round.clockAsOfMs, START - 6000);
        assert.equal(deriveView(merged, START - 5000).screen, 'waiting');
        held = merged;
        // A fetch read even before the state held: not taken at all
        assert.equal(adoptState(held, state({ startedAtMs: START, asOfMs: START - 20_000 })), held);
        // The old clock pushed again (a replay): ignored as well
        assert.equal(adoptRoundClock(held, { ...cancel, startedAtMs: START, asOfMs: START - 6500 }), held);

        // Past the old start (the check 1 s after it): still waiting
        assert.equal(deriveView(held, START + 1000).screen, 'waiting');

        // A newer fetch (still cancelled) is taken
        const fresh = state({ asOfMs: START + 1000 });
        assert.equal(adoptState(held, fresh), fresh);
    },

    async 'the newest state wins by asOfMs; a tie keeps the one held; a state without asOfMs never replaces one with it'() {
        const a = { asOfMs: 100 };
        const b = { asOfMs: 200 };
        const tie = { asOfMs: 100 };
        const bare = { round: null };
        const bare2 = { round: null };

        assert.equal(newer(a, b), b);
        assert.equal(newer(b, a), b);
        assert.equal(newer(a, tie), a);
        assert.equal(newer(a, bare), a);
        assert.equal(newer(bare, a), a);
        assert.equal(newer(bare, bare2), bare2, 'nothing to compare: the later arrival');
        assert.equal(newer(null, a), a);
        assert.equal(newer(a, null), a);
        assert.equal(newer(undefined, undefined), null);
    },

    async 'a pushed round clock: newer ones applied to the round, older or other rounds\' ignored, the ATOM shape read too'() {
        const held = state({ asOfMs: START - 60_000 });

        // The start push (stream D's payload: ms fields + the ATOM ones)
        const start = {
            status: 'scheduled',
            startedAt: new Date(START).toISOString(),
            stoppedAt: null,
            startedAtMs: START,
            stoppedAtMs: null,
            minutesLimit: 60,
            asOfMs: START - 10_500,
            serverNowMs: START - 10_490,
            stateHash: 'h-start',
        };
        const started = adoptRoundClock(held, start);
        assert.notEqual(started, held);
        assert.equal(started.round.startedAtMs, START);
        assert.equal(started.round.stateHash, 'h-start');
        assert.equal(started.round.name, 'Final', 'the rest of the round stays');
        assert.equal(started.asOfMs, held.asOfMs, 'the state itself was not read again');
        assert.equal(deriveView(started, START - 3000).screen, 'countdown');

        // Another round's push
        assert.equal(adoptRoundClock(held, { ...start, roundId: OTHER_ROUND }), held);
        // Older than the state
        assert.equal(adoptRoundClock(held, { ...start, asOfMs: START - 70_000 }), held);
        // Without asOfMs (an old payload): nothing to order it by - not applied over a state that has one
        assert.equal(adoptRoundClock(held, { status: 'running', startedAt: new Date(START).toISOString() }), held);

        // An older payload shape - ATOM times only - replaces the millisecond ones too, never a stale mix
        const stopped = adoptRoundClock(started, { status: 'stopped', startedAt: new Date(START).toISOString(), stoppedAt: new Date(START + 600_000).toISOString(), minutesLimit: 60, asOfMs: START + 600_001 });
        assert.equal(stopped.round.stoppedAtMs, START + 600_000);
        assert.equal(deriveView(stopped, START + 700_000).screen, 'ended');

        // A full state read before the state held: not taken at all
        assert.equal(adoptState(started, state({ asOfMs: START - 70_000 })), started);

        // A full state newer than the state held but read before the push: its other parts are taken, the pushed
        // clock stays
        const fetched = { ...state({ asOfMs: START - 10_800 }), entry: { confirmedMomentMs: null, candidates: 0, taps: 1, reportedPieces: null, review: 'pending' } };
        const kept = adoptState(started, fetched);
        assert.equal(kept.entry.taps, 1, 'the newer state\'s entry');
        assert.equal(kept.asOfMs, START - 10_800);
        assert.equal(kept.round.startedAtMs, START, 'the clock pushed after that state was read');
        assert.equal(kept.round.clockAsOfMs, START - 10_500);
        assert.equal(deriveView(kept, START - 1000).screen, 'countdown');

        // A full state newer than the push replaces it all
        const newest = state({ startedAtMs: START, asOfMs: START - 9000 });
        assert.equal(adoptState(started, newest), newest);
    },

    async 'a round clock alone (the control room, the stopwatch payload) reads the same as a participant state'() {
        const clock = { status: 'running', startedAtMs: START, stoppedAtMs: null, minutesLimit: 45, asOfMs: START + 1 };
        const view = deriveView(clock, START + 60_000);

        assert.equal(view.screen, 'running');
        assert.equal(view.remainingMs, 44 * 60_000);
        assert.deepEqual(readClock(clock), { roundId: null, startedAtMs: START, stoppedAtMs: null, limitMs: 45 * 60_000, endsAtMs: START + 45 * 60_000 });

        // The ATOM shape of today's state endpoint
        const atom = { status: 'running', startedAt: '2026-10-13T10:00:00+00:00', stoppedAt: null, minutesLimit: 30 };
        const at = Date.parse('2026-10-13T10:00:00+00:00');
        assert.equal(deriveView(atom, at + 1000).elapsedMs, 1000);
        assert.equal(deriveView({ ...atom, startedAt: 'not a date' }, at).screen, 'waiting');
    },

    async 'no current round in the state: waiting, nothing to count'() {
        const view = deriveView({ asOfMs: START, round: null }, START);

        assert.equal(view.screen, 'waiting');
        assert.equal(view.remainingMs, null);
        assert.equal(readClock({ round: null }), null);
        assert.equal(deriveView(null, START).screen, 'waiting');
        assert.equal(deriveView(state({ startedAtMs: START }), Number.NaN).screen, 'waiting');
        // A stop without a start means nothing
        assert.equal(deriveView(state({ stoppedAtMs: START }), START + 1).screen, 'waiting');
    },

    async 'a round without a time limit runs until stopped; the round\'s own endsAtMs is used when it has one'() {
        const open = deriveView(state({ startedAtMs: START, minutesLimit: null }), START + 10 * HOUR);

        assert.equal(open.screen, 'running');
        assert.equal(open.remainingMs, null);
        assert.equal(open.limitMs, null);

        const withEnd = deriveView(state({ startedAtMs: START, minutesLimit: null, round: { endsAtMs: START + 90_000 } }), START + 90_000);
        assert.equal(withEnd.screen, 'ended');
        assert.equal(withEnd.endedBy, 'limit');
        assert.equal(withEnd.remainingMs, null);
    },

    async 'formatClock: HH:MM:SS, rounded down (or up), never negative'() {
        assert.equal(formatClock(0), '00:00:00');
        assert.equal(formatClock(999), '00:00:00');
        assert.equal(formatClock(1000), '00:00:01');
        assert.equal(formatClock(59_999), '00:00:59');
        assert.equal(formatClock(3_599_999), '00:59:59');
        assert.equal(formatClock(3_600_000), '01:00:00');
        assert.equal(formatClock(36_000_000 * 10 + 61_000), '100:01:01');
        assert.equal(formatClock(-5000), '00:00:00');
        assert.equal(formatClock(Number.NaN), '00:00:00');
        assert.equal(formatClock(1, { ceil: true }), '00:00:01');
        assert.equal(formatClock(1000, { ceil: true }), '00:00:01');
        assert.equal(formatClock(1001, { ceil: true }), '00:00:02');
        assert.equal(formatClock(0, { ceil: true }), '00:00:00');
    },

    async 'the official time of a finish moment: whole seconds since the start, rounded down'() {
        assert.equal(resultSeconds(START + 2_832_999, START), 2832);
        assert.equal(resultSeconds(START + 2_833_000, START), 2833);
        assert.equal(resultSeconds(START, START), 0);
        assert.equal(resultSeconds(START - 1, START), null, 'before the start');
        assert.equal(resultSeconds(null, START), null);
        assert.equal(resultSeconds(START, null), null);
    },

    async 'state checks: 2 s before the start, 1 s after it, 1 s after the limit - nothing once stopped'() {
        const round = state({ startedAtMs: START });

        assert.equal(nextStateCheckAt(round, START - HOUR), START - 2000);
        assert.equal(nextStateCheckAt(round, START - 2000), START + 1000);
        assert.equal(nextStateCheckAt(round, START + 1000), START + HOUR + 1000);
        assert.equal(nextStateCheckAt(round, START + HOUR + 1000), null);
        assert.equal(nextStateCheckAt(state(), START), null, 'no start');
        assert.equal(nextStateCheckAt(state({ startedAtMs: START, stoppedAtMs: START + 1000 }), START + 1500), null);
        assert.equal(nextStateCheckAt(state({ startedAtMs: START, minutesLimit: null }), START + 2000), null);
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
