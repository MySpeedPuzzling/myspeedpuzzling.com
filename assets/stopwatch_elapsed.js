// The running stopwatch's elapsed time in the top bar's pill and the Menu's Stopwatch row (docs/features/navigation
// README D7, ADD-03). Pure functions only - assets/controllers/stopwatch_pill_controller.js ticks with them, node runs
// them in tests/stopwatch-elapsed-harness.mjs. format() is the twin of RunningStopwatch::format() in PHP, which renders
// the first digits: both must write every number the same, or the pill changes on its first tick.

// The last state this device set each stopwatch to - written by stopwatch_controller.js on start, pause, resume, reset
// and finish: a map `{"<stopwatch id>": {status: 'running'|'paused'|'reset'|'finished', at: Date.now(), elapsed?: ms}}`
// of the MAX_REMEMBERED_STOPWATCHES newest (ADD-03 review F6: one record per stopwatch, so pausing A and then starting
// or deleting B keeps A's). `elapsed` = the stopwatch's elapsed ms at `at` (start, pause, resume). A pill rendered before
// a pause (a page restored from a snapshot, a pause still waiting in the offline queue) hides itself when it reads a newer
// "not running" for its stopwatch, and shows again - counting from the record's elapsed - on a newer "running".
// Before the map the key held one record `{id, status, at}`: still read (parseLastStates()).
export const LAST_STATE_KEY = 'msp:stopwatch-last';

// Dispatched on window after the state is written, so a pill on the same page reacts (other tabs get `storage`)
export const LAST_STATE_EVENT = 'msp:stopwatch-last';

export const MAX_REMEMBERED_STOPWATCHES = 10;

// The page's receipt belongs to a pill rendered up to this many seconds before the page's msp-rendered-at (the profile
// row is read first), or a second after it (read while the body renders, across a second boundary)
const RECEIPT_AFTER_RENDER_SECONDS = 5;

const pad = (number) => (number < 10 ? '0' : '') + number;

// Laps are stored to the millisecond (whole seconds before 2026-10-12): every number is counted in whole milliseconds
// and the total cut to whole seconds once, at the end - the digits the stopwatch page shows, never cut per lap
const ms = (seconds) => Math.round(seconds * 1000);

/**
 * @param {number} lapStartedEpoch unix seconds when the running lap started (fractions allowed)
 * @param {number} closedSeconds the laps before it (fractions allowed)
 * @param {number} serverNowEpoch unix seconds, the server's clock (fractions allowed)
 * @returns {number} whole seconds, never negative
 */
export function elapsedSeconds(lapStartedEpoch, closedSeconds, serverNowEpoch) {
    return Math.floor((Math.max(0, ms(closedSeconds)) + Math.max(0, ms(serverNowEpoch) - ms(lapStartedEpoch))) / 1000);
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
 * @param {number} closedSeconds the laps before the running one (fractions allowed)
 * @returns {number}
 */
export function nextChangeAt(lapStartedEpoch, serverNowEpoch, closedSeconds = 0) {
    const closedMs = Math.max(0, ms(closedSeconds));
    const totalMs = closedMs + Math.max(0, ms(serverNowEpoch) - ms(lapStartedEpoch));

    // The running lap reaches the next whole second of the total (before the lap starts: counted from its start)
    return (ms(lapStartedEpoch) + (Math.floor(totalMs / 1000) + 1) * 1000 - closedMs) / 1000;
}

function validState(value) {
    if (!value || typeof value !== 'object' || typeof value.status !== 'string' || !Number.isFinite(value.at)) {
        return null;
    }

    const state = { status: value.status, at: value.at };

    if (Number.isFinite(value.elapsed) && value.elapsed >= 0) {
        state.elapsed = value.elapsed;
    }

    return state;
}

function stateOf(states, stopwatchId) {
    return Object.prototype.hasOwnProperty.call(states, stopwatchId) ? states[stopwatchId] : null;
}

/**
 * @param {null|string} raw what localStorage holds under LAST_STATE_KEY - the map, or the single record written before it
 * @returns {Object<string, {status: string, at: number, elapsed?: number}>} by stopwatch id; damaged entries dropped
 */
export function parseLastStates(raw) {
    if (typeof raw !== 'string') {
        return {};
    }

    let data;

    try {
        data = JSON.parse(raw);
    } catch {
        // Not ours, or damaged - as if nothing was stored
        return {};
    }

    if (!data || typeof data !== 'object' || Array.isArray(data)) {
        return {};
    }

    // The single record of the first release: {id, status, at}
    if (typeof data.id === 'string') {
        const state = validState(data);

        return state === null ? {} : { [data.id]: state };
    }

    const states = {};

    for (const [stopwatchId, value] of Object.entries(data)) {
        const state = validState(value);

        if (state !== null) {
            states[stopwatchId] = state;
        }
    }

    return states;
}

/**
 * The map with one stopwatch's new state, keeping the MAX_REMEMBERED_STOPWATCHES newest.
 *
 * @param {Object<string, {status: string, at: number, elapsed?: number}>} states parseLastStates()
 * @param {string} stopwatchId
 * @param {string} status
 * @param {number} at Date.now()
 * @param {null|number} elapsedMs the stopwatch's elapsed ms at `at`, when known
 * @returns {Object<string, {status: string, at: number, elapsed?: number}>}
 */
export function withState(states, stopwatchId, status, at, elapsedMs = null) {
    const next = { ...states };
    next[stopwatchId] = Number.isFinite(elapsedMs) && elapsedMs >= 0 ? { status, at, elapsed: elapsedMs } : { status, at };

    const newest = Object.keys(next)
        .sort((a, b) => next[b].at - next[a].at)
        .slice(0, MAX_REMEMBERED_STOPWATCHES);

    return Object.fromEntries(newest.map((id) => [id, next[id]]));
}

/**
 * Whether this device stopped the pill's stopwatch after the page was rendered.
 *
 * @param {Object<string, {status: string, at: number}>} states parseLastStates()
 * @param {string} stopwatchId the pill's stopwatch
 * @param {number} renderedEpoch unix seconds when the server rendered the pill
 * @param {number} offsetMs the server's clock minus this device's clock (clockOffsetMs())
 * @returns {boolean}
 */
export function stoppedAfterRender(states, stopwatchId, renderedEpoch, offsetMs) {
    const state = stateOf(states, stopwatchId);

    if (state === null || state.status === 'running') {
        return false;
    }

    return state.at + offsetMs > renderedEpoch * 1000;
}

/**
 * When this device (re)started the pill's stopwatch after the page was rendered - resumed after a pause the pill hid
 * for: where to count from, so the paused time is left out. Null when the render's own numbers still hold.
 *
 * @param {Object<string, {status: string, at: number, elapsed?: number}>} states parseLastStates()
 * @param {string} stopwatchId
 * @param {number} renderedEpoch
 * @param {number} offsetMs
 * @returns {null|{lapStarted: number, closedSeconds: number}} server unix seconds (fractions allowed)
 */
export function resumedAfterRender(states, stopwatchId, renderedEpoch, offsetMs) {
    const state = stateOf(states, stopwatchId);

    if (state === null || state.status !== 'running' || state.elapsed === undefined || state.at + offsetMs <= renderedEpoch * 1000) {
        return null;
    }

    // One running lap that started `elapsed` before the record: the seconds then match the stopwatch page's own
    return { lapStarted: (state.at + offsetMs - state.elapsed) / 1000, closedSeconds: 0 };
}

/**
 * The server's clock minus this device's (ms) for a pill rendered at `renderedEpoch`: from the moment the page's HTML
 * arrived (assets/document_receipt.js) when the receipt is this render's page, else from the pill's first connect -
 * which also counts the server's work after the read, the transfer and the lazy chunk's download as clock difference.
 *
 * @param {number} renderedEpoch unix seconds when the server read the stopwatch
 * @param {null|{renderedAt: number, at: number}} receipt the page's msp-rendered-at and its arrival on this device
 * @param {number} connectedAtMs Date.now() when the render first connects
 * @returns {number}
 */
export function clockOffsetMs(renderedEpoch, receipt, connectedAtMs) {
    const sincePageRender = receipt === null ? NaN : receipt.renderedAt - renderedEpoch;
    const ofThisPage = sincePageRender >= -1 && sincePageRender <= RECEIPT_AFTER_RENDER_SECONDS;

    return renderedEpoch * 1000 - (ofThisPage ? receipt.at : connectedAtMs);
}

/**
 * Remembers what this device just did to a stopwatch (stopwatch_controller.js). Storage may be unavailable (private
 * mode, blocked site data): the pill then simply waits for the next page.
 *
 * @param {string} stopwatchId
 * @param {'running'|'paused'|'reset'|'finished'} status
 * @param {null|number} elapsedMs the stopwatch's elapsed ms now, when known (start, pause, resume)
 */
export function rememberStopwatchState(stopwatchId, status, elapsedMs = null) {
    if (!stopwatchId) {
        return;
    }

    try {
        const states = parseLastStates(window.localStorage.getItem(LAST_STATE_KEY));
        window.localStorage.setItem(LAST_STATE_KEY, JSON.stringify(withState(states, stopwatchId, status, Date.now(), elapsedMs)));
    } catch {
        // Storage unavailable
    }

    window.dispatchEvent(new CustomEvent(LAST_STATE_EVENT));
}
