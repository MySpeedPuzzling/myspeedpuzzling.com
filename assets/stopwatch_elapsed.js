// The running stopwatch's elapsed time in the top bar's pill and the Menu's Stopwatch row (docs/features/navigation
// README D7, ADD-03). Pure functions only - assets/controllers/stopwatch_pill_controller.js ticks with them, node runs
// them in tests/stopwatch-elapsed-harness.mjs. format() is the twin of RunningStopwatch::format() in PHP, which renders
// the first digits: both must write every number the same, or the pill changes on its first tick.

// The last state this device set a stopwatch to - written by stopwatch_controller.js on start, pause, resume, reset and
// finish: `{id, status: 'running'|'paused'|'reset'|'finished', at: Date.now()}`. A pill rendered before a pause (a page
// restored from a snapshot, a pause still waiting in the offline queue) hides itself when it reads a newer "not running".
export const LAST_STATE_KEY = 'msp:stopwatch-last';

// Dispatched on window after the state is written, so a pill on the same page reacts (other tabs get `storage`)
export const LAST_STATE_EVENT = 'msp:stopwatch-last';

const pad = (number) => (number < 10 ? '0' : '') + number;

/**
 * @param {number} lapStartedEpoch unix seconds when the running lap started
 * @param {number} closedSeconds the laps before it
 * @param {number} serverNowEpoch unix seconds, the server's clock (fractions allowed)
 * @returns {number} whole seconds, never negative
 */
export function elapsedSeconds(lapStartedEpoch, closedSeconds, serverNowEpoch) {
    return Math.max(0, Math.floor(closedSeconds)) + Math.max(0, Math.floor(serverNowEpoch - lapStartedEpoch));
}

/**
 * Full `h:mm:ss`; compact `m:ss` with the minutes uncapped (99:59, 100:00, 999:59).
 *
 * @param {number} seconds
 * @param {boolean} compact
 * @returns {string}
 */
export function format(seconds, compact) {
    const total = Math.max(0, Math.floor(seconds));

    if (compact) {
        return `${Math.floor(total / 60)}:${pad(total % 60)}`;
    }

    return `${Math.floor(total / 3600)}:${pad(Math.floor((total % 3600) / 60))}:${pad(total % 60)}`;
}

/**
 * When the elapsed seconds next change, in the server's unix seconds.
 *
 * @param {number} lapStartedEpoch
 * @param {number} serverNowEpoch
 * @returns {number}
 */
export function nextChangeAt(lapStartedEpoch, serverNowEpoch) {
    if (serverNowEpoch < lapStartedEpoch) {
        return lapStartedEpoch + 1;
    }

    return lapStartedEpoch + Math.floor(serverNowEpoch - lapStartedEpoch) + 1;
}

/**
 * @param {null|string} raw what localStorage holds under LAST_STATE_KEY
 * @returns {null|{id: string, status: string, at: number}}
 */
export function parseLastState(raw) {
    if (typeof raw !== 'string') {
        return null;
    }

    try {
        const state = JSON.parse(raw);

        if (state && typeof state.id === 'string' && typeof state.status === 'string' && Number.isFinite(state.at)) {
            return { id: state.id, status: state.status, at: state.at };
        }
    } catch {
        // Not ours, or damaged - as if nothing was stored
    }

    return null;
}

/**
 * Whether this device stopped the pill's stopwatch after the page was rendered.
 *
 * @param {null|{id: string, status: string, at: number}} state parseLastState()
 * @param {string} stopwatchId the pill's stopwatch
 * @param {number} renderedEpoch unix seconds when the server rendered the pill
 * @param {number} offsetMs the server's clock minus this device's clock, measured when that render first connected
 * @returns {boolean}
 */
export function stoppedAfterRender(state, stopwatchId, renderedEpoch, offsetMs) {
    if (state === null || state.id !== stopwatchId || state.status === 'running') {
        return false;
    }

    return state.at + offsetMs > renderedEpoch * 1000;
}

/**
 * Remembers what this device just did to a stopwatch (stopwatch_controller.js). Storage may be unavailable (private
 * mode, blocked site data): the pill then simply waits for the next page.
 *
 * @param {string} stopwatchId
 * @param {'running'|'paused'|'reset'|'finished'} status
 */
export function rememberStopwatchState(stopwatchId, status) {
    if (!stopwatchId) {
        return;
    }

    try {
        window.localStorage.setItem(LAST_STATE_KEY, JSON.stringify({ id: stopwatchId, status, at: Date.now() }));
    } catch {
        // Storage unavailable
    }

    window.dispatchEvent(new CustomEvent(LAST_STATE_EVENT));
}
