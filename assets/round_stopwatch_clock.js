/**
 * The round stopwatch's clock in the browser (docs/features/competitions-management/README.md "Round Stopwatch") -
 * plain functions without DOM or timers, run under node by tests/round-stopwatch-harness.mjs. The projector page and the
 * stopwatch control page (round_stopwatch_controller.js), the live entry (live_results_controller.js) and the results
 * overview (results_overview_controller.js) read the stopwatch through them.
 *
 * A stopwatch is the server's RoundStopwatchState - {status, startedAt, stoppedAt, minutesLimit, startedAtMs,
 * stoppedAtMs, asOfMs, stateHash} - or the shape the pages had before it (no milliseconds, no asOfMs). Its status is
 * null | 'scheduled' | 'running' | 'stopped'; the one rule (RoundStopwatchStatus in PHP): a scheduled start counts as
 * running once its moment has passed - nothing tells the page at that moment, it follows its own clock.
 */

export const SCHEDULED = 'scheduled';
export const RUNNING = 'running';
export const STOPPED = 'stopped';

// The last seconds of a countdown are shown big on the projector page
export const BIG_COUNTDOWN_MS = 10000;

function momentMs(ms, iso) {
    if (typeof ms === 'number' && Number.isFinite(ms)) {
        return ms;
    }

    if (typeof iso === 'string' && iso !== '') {
        const parsed = Date.parse(iso);

        return Number.isNaN(parsed) ? null : parsed;
    }

    return null;
}

export function startedAtMs(stopwatch) {
    return momentMs(stopwatch?.startedAtMs, stopwatch?.startedAt);
}

export function stoppedAtMs(stopwatch) {
    return momentMs(stopwatch?.stoppedAtMs, stopwatch?.stoppedAt);
}

/**
 * The status at `nowMs` (server time): a scheduled start whose moment has come is running.
 *
 * RoundStopwatchStatus::at() in PHP, with one difference on purpose: a `running` start this device's clock still sees
 * ahead stays running (at 0:00:00) instead of counting down. The server never stores `running` ahead of time ("Start"
 * writes now) - only this device's clock error can show it, and a countdown flashing for a moment right after "Start"
 * would be wrong on the big screen.
 */
export function stopwatchStatusAt(stopwatch, nowMs) {
    const status = stopwatch?.status ?? null;

    if (status === SCHEDULED) {
        const start = startedAtMs(stopwatch);

        return start !== null && start <= nowMs ? RUNNING : SCHEDULED;
    }

    return status;
}

/**
 * What the clock shows at `nowMs` (server time):
 * - `idle` - never started (or nothing readable);
 * - `scheduled` - counting down, `countdownMs` until the start;
 * - `running` - `elapsedMs` since the start, never negative (a start a device's clock still sees ahead shows 0);
 * - `times_up` - the round's time limit has run, `elapsedMs` = the limit;
 * - `stopped` - `elapsedMs` from the start to the stop.
 *
 * @returns {{phase: 'idle'|'scheduled'|'running'|'times_up'|'stopped', elapsedMs: number, countdownMs: number, limitMs: number}}
 */
export function stopwatchView(stopwatch, nowMs) {
    const status = stopwatchStatusAt(stopwatch, nowMs);
    const start = startedAtMs(stopwatch);
    const minutesLimit = Number(stopwatch?.minutesLimit ?? 0);
    const limitMs = Number.isFinite(minutesLimit) && minutesLimit > 0 ? minutesLimit * 60 * 1000 : 0;
    const view = { phase: 'idle', elapsedMs: 0, countdownMs: 0, limitMs };

    if (start === null) {
        return view;
    }

    if (status === SCHEDULED) {
        return { ...view, phase: 'scheduled', countdownMs: Math.max(0, start - nowMs) };
    }

    if (status === RUNNING) {
        const elapsedMs = Math.max(0, nowMs - start);

        if (limitMs > 0 && elapsedMs >= limitMs) {
            return { ...view, phase: 'times_up', elapsedMs: limitMs };
        }

        return { ...view, phase: 'running', elapsedMs };
    }

    if (status === STOPPED) {
        // Rows from before the stop moment was kept have none - the clock stays where "now" is
        const stop = stoppedAtMs(stopwatch) ?? nowMs;

        return { ...view, phase: 'stopped', elapsedMs: Math.max(0, stop - start) };
    }

    return view;
}

function pad(number) {
    return number < 10 ? `0${number}` : String(number);
}

/**
 * A running clock as "HH", "MM", "SS" - whole seconds, rounded down (00:00:00 for the first second).
 */
export function clockParts(ms) {
    const total = Math.max(0, Math.floor(ms / 1000));

    return {
        hours: pad(Math.floor(total / 3600)),
        minutes: pad(Math.floor((total % 3600) / 60)),
        seconds: pad(total % 60),
    };
}

/**
 * Whole seconds left of a countdown, rounded up: 10, 9, … 1 - and the start at 0.
 */
export function countdownSeconds(ms) {
    return Math.max(0, Math.ceil(ms / 1000));
}

/**
 * A countdown as "H:MM:SS", rounded up: "0:00:09", "2:13:05".
 */
export function formatCountdown(ms) {
    const total = countdownSeconds(ms);

    return `${Math.floor(total / 3600)}:${pad(Math.floor((total % 3600) / 60))}:${pad(total % 60)}`;
}

/**
 * Whether `incoming` replaces `current`: the newest read wins (`asOfMs`), so a slow state fetch never overwrites a newer
 * live update. A state without `asOfMs` (the older shape) always replaces.
 */
export function isNewerState(current, incoming) {
    if (typeof incoming?.asOfMs !== 'number' || typeof current?.asOfMs !== 'number') {
        return true;
    }

    return incoming.asOfMs >= current.asOfMs;
}

/**
 * This device's monotonic clock on the epoch scale (ms): `performance.timeOrigin + performance.now()` - a change of the
 * device's wall clock while the page is open (NTP, a manual change, a DST bug) moves `Date.now()`, never this
 * (reliability hardening F1, D2). `Date.now()` where the browser has no `performance`.
 */
export function monotonicNow() {
    const performance = globalThis.performance;

    if (performance && typeof performance.now === 'function' && Number.isFinite(performance.timeOrigin)) {
        return performance.timeOrigin + performance.now();
    }

    return Date.now();
}

// The wall clock and the monotonic one disagreeing by more than this about the time that passed: a clock change or a sleep
export const CLOCK_JUMP_MS = 2000;

/**
 * The server's clock minus this device's (ms), from the answer with the shortest round trip: its error is at most half
 * of it. Every state fetch is a sample (`observe()`), the page's own server time a first guess without a round trip
 * (`hint()`). Samples older than `windowMs` before the newest one are dropped, so a drifting clock is followed - and
 * the newest sample always stays. `sampleAgeMs()`: the age of the sample in use (sent with a competitor's tap - T2;
 * null right after a jump - the estimate then rests on the hint).
 *
 * Measured on the monotonic clock (`now`, monotonicNow() - callers stamp `sentAtMs` / `receivedAtMs` with the same
 * clock), so a wall clock change while the page is open moves nothing (D2). `performance.now()` stops while most phones
 * sleep: when the wall clock (`wallNow`) and the monotonic one disagree by more than CLOCK_JUMP_MS about the time that
 * passed,
 * - after `hidden()` (the page was in the background - it may have slept): the estimate moves on by the difference (a
 *   sleep the monotonic clock did not count);
 * - while the page stayed visible: the wall clock changed - the estimate stays;
 * either way the estimate becomes the hint, the samples are dropped (a fresh one decides - a sample taken across the
 * jump is left out) and `onJump()` asks the page for one. An injected `now` without `wallNow` sees no jumps (tests).
 */
export function createServerClock({ now, wallNow, windowMs = 10 * 60 * 1000, maxSamples = 12, jumpMs = CLOCK_JUMP_MS, onJump = () => {} } = {}) {
    const readNow = now ?? monotonicNow;
    const readWall = wallNow ?? (now === undefined || now === monotonicNow ? () => Date.now() : null);
    let hint = 0;
    let samples = [];
    let skew = null;
    let mayHaveSlept = false;
    // Moments (monotonic) a jump was seen at - a sample sent before the last one says nothing
    let jumpedAt = -Infinity;

    function best() {
        return samples.length === 0 ? null : samples.reduce((chosen, sample) => (sample.rttMs < chosen.rttMs ? sample : chosen));
    }

    function bestOffset() {
        return best()?.offsetMs ?? hint;
    }

    // Looks for a wall clock change or a sleep since the last look - true when it saw one
    function look() {
        if (readWall === null) {
            return false;
        }

        const current = readWall() - readNow();

        // Compared with the last look, so a slow drift of the two clocks is followed and never counts as a jump
        if (skew === null || Math.abs(current - skew) <= jumpMs) {
            skew = current;

            return false;
        }

        const deltaMs = current - skew;
        // The wall clock ran on while the monotonic one stood after the page went to the background: a sleep - the
        // server's time moved on by it too. Otherwise the wall clock was changed - nothing of the server's moved.
        hint = bestOffset() + (mayHaveSlept && deltaMs > 0 ? deltaMs : 0);
        mayHaveSlept = false;
        samples = [];
        jumpedAt = readNow();
        skew = readWall() - readNow();
        onJump({ deltaMs });

        return true;
    }

    return {
        hint(offset) {
            if (typeof offset === 'number' && Number.isFinite(offset)) {
                hint = offset;
            }
        },

        observe({ sentAtMs, receivedAtMs, serverNowMs }) {
            // A jump seen after the request went out: the sample says nothing - onJump() asked for another
            if (look() || sentAtMs < jumpedAt) {
                return false;
            }

            if (![sentAtMs, receivedAtMs, serverNowMs].every((value) => typeof value === 'number' && Number.isFinite(value))) {
                return false;
            }

            const rttMs = receivedAtMs - sentAtMs;

            if (rttMs < 0) {
                return false;
            }

            samples.push({ atMs: receivedAtMs, rttMs, offsetMs: serverNowMs - (sentAtMs + rttMs / 2) });

            const newest = Math.max(...samples.map((sample) => sample.atMs));
            samples = samples.filter((sample) => newest - sample.atMs <= windowMs).slice(-maxSamples);

            return true;
        },

        offsetMs() {
            look();

            return bestOffset();
        },

        bestRttMs() {
            return samples.length === 0 ? null : Math.min(...samples.map((sample) => sample.rttMs));
        },

        // How old the sample the offset rests on is (ms on this device) - null with only the hint
        sampleAgeMs() {
            const chosen = best();

            return chosen === null ? null : Math.max(0, readNow() - chosen.atMs);
        },

        serverNow() {
            look();

            return readNow() + bestOffset();
        },

        /** The page went to the background - a jump seen before it is shown again may be a sleep */
        hidden() {
            look();
            mayHaveSlept = true;
        },

        /** The page is shown again: a sleep is looked for now (the page asks for a fresh state anyway) */
        shown() {
            look();
            mayHaveSlept = false;
        },
    };
}
