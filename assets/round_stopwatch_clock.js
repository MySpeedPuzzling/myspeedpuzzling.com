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
 * The server's clock minus this device's (ms), from the answer with the shortest round trip: its error is at most half
 * of it. Every state fetch is a sample (`observe()`), the page's own server time a first guess without a round trip
 * (`hint()`). Samples older than `windowMs` before the newest one are dropped, so a drifting clock is followed - and
 * the newest sample always stays.
 */
export function createServerClock({ now = () => Date.now(), windowMs = 10 * 60 * 1000, maxSamples = 12 } = {}) {
    let hint = 0;
    let samples = [];

    function offsetMs() {
        if (samples.length === 0) {
            return hint;
        }

        return samples.reduce((best, sample) => (sample.rttMs < best.rttMs ? sample : best)).offsetMs;
    }

    return {
        hint(offset) {
            if (typeof offset === 'number' && Number.isFinite(offset)) {
                hint = offset;
            }
        },

        observe({ sentAtMs, receivedAtMs, serverNowMs }) {
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

        offsetMs,

        bestRttMs() {
            return samples.length === 0 ? null : Math.min(...samples.map((sample) => sample.rttMs));
        },

        serverNow() {
            return now() + offsetMs();
        },
    };
}
