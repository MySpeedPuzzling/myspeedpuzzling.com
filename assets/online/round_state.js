/**
 * What a phone shows for a round, from the round's clock and the server's time (docs/features/online-competitions/
 * README.md §5.1, §5.3, §5.5) - the participant page and the organiser's control room alike. Pure: a state in, a
 * screen out.
 *
 * The round's clock is a few timestamps - `startedAtMs` (a start in the future is the countdown), `stoppedAtMs` (the
 * organiser ended the round) and the time limit - so with the server's time (clock_sync.js) everything is arithmetic:
 * - no start → `waiting`; a start more than COUNTDOWN_MS away → `scheduled` ("Starts in 2:13:05"); the last 10 s →
 *   `countdown`; from the start → `running`; stopped, or the time limit reached → `ended`, the time frozen at that
 *   moment. A late joiner simply lands on `running` at the right time - it counts from the official start for everyone.
 * - States come from several places - the state fetch, the round's Mercure push, a fetch after a heartbeat's hint - and
 *   the newest one wins by `asOfMs` (when the server read the round), so a slow fetch can never undo a newer push: a
 *   cancelled countdown never comes back.
 *
 * A state is the participant state of implementation-plan.md §3.1 (`{asOfMs, round: {…}, …}`) or a round clock alone
 * (the round stopwatch payload `{status, startedAtMs, stoppedAtMs, minutesLimit, asOfMs, stateHash, …}` - the ATOM
 * `startedAt` / `stoppedAt` of older payloads are read too). Pinned by tests/online-round-state-harness.mjs.
 */

export const COUNTDOWN_MS = 10000;
// The page fetches the state this long before the start and this long after the start / the end (README §5.3: a
// missed cancel or a missed "End now" is caught right there)
export const CHECK_BEFORE_START_MS = 2000;
export const CHECK_AFTER_MS = 1000;

// The fields of a round clock - what a push carries and replaces
const CLOCK_FIELDS = ['status', 'startedAtMs', 'stoppedAtMs', 'startedAt', 'stoppedAt', 'minutesLimit', 'endsAtMs', 'stateHash'];

/**
 * @typedef {object} RoundView
 * @property {'waiting'|'scheduled'|'countdown'|'running'|'ended'} screen
 * @property {number|null} countdownMs   until the start (> 0 while scheduled / counting down), 0 once started, null
 *                                       without a start
 * @property {number} elapsedMs          0 before the start, frozen at the end
 * @property {number|null} remainingMs   until the time limit (the whole limit before the start, 0 once ended), null
 *                                       without a limit
 * @property {number|null} startedAtMs
 * @property {number|null} endsAtMs      start + limit
 * @property {number|null} endedAtMs     the stop, or the limit - whichever came first
 * @property {'stop'|'limit'|null} endedBy
 * @property {number|null} limitMs
 */

function finiteOrNull(value) {
    return typeof value === 'number' && Number.isFinite(value) ? value : null;
}

function epochMs(milliseconds, atom) {
    const value = finiteOrNull(milliseconds);

    if (value !== null) {
        return value;
    }

    if (typeof atom === 'string' && atom !== '') {
        const parsed = Date.parse(atom);

        return Number.isNaN(parsed) ? null : parsed;
    }

    return null;
}

/**
 * The round of a state: `state.round` of a participant state (null when it has none), else the state itself.
 */
function roundOf(state) {
    if (state === null || typeof state !== 'object') {
        return null;
    }

    if (Object.prototype.hasOwnProperty.call(state, 'round')) {
        return state.round !== null && typeof state.round === 'object' ? state.round : null;
    }

    return state;
}

/**
 * The round clock of a state in milliseconds - null without a round.
 *
 * @returns {{roundId: string|null, startedAtMs: number|null, stoppedAtMs: number|null, limitMs: number|null, endsAtMs: number|null}|null}
 */
export function readClock(state) {
    const round = roundOf(state);

    if (round === null) {
        return null;
    }

    const startedAtMs = epochMs(round.startedAtMs, round.startedAt);
    const stoppedAtMs = epochMs(round.stoppedAtMs, round.stoppedAt);
    const minutes = finiteOrNull(round.minutesLimit);
    const limitMs = minutes !== null && minutes > 0 ? minutes * 60000 : null;
    let endsAtMs = null;

    if (startedAtMs !== null && limitMs !== null) {
        endsAtMs = startedAtMs + limitMs;
    } else if (startedAtMs !== null) {
        endsAtMs = finiteOrNull(round.endsAtMs);
    }

    return {
        roundId: typeof round.id === 'string' ? round.id : (typeof round.roundId === 'string' ? round.roundId : null),
        startedAtMs,
        // A stop without a start means nothing
        stoppedAtMs: startedAtMs === null ? null : stoppedAtMs,
        limitMs,
        endsAtMs,
    };
}

/**
 * The screen at `serverNowMs` (the server's time - clock_sync.js serverNow()).
 *
 * @returns {RoundView}
 */
export function deriveView(state, serverNowMs) {
    const clock = readClock(state);
    const limitMs = clock?.limitMs ?? null;
    const view = {
        screen: 'waiting',
        countdownMs: null,
        elapsedMs: 0,
        remainingMs: limitMs,
        startedAtMs: null,
        endsAtMs: null,
        endedAtMs: null,
        endedBy: null,
        limitMs,
    };

    if (clock === null || clock.startedAtMs === null || !Number.isFinite(serverNowMs)) {
        return view;
    }

    const { startedAtMs, stoppedAtMs, endsAtMs } = clock;
    view.startedAtMs = startedAtMs;
    view.endsAtMs = endsAtMs;

    // Any stop ends the round (online rounds have no resume) - even one the phone's clock has not reached yet
    let endedAtMs = null;
    let endedBy = null;

    if (stoppedAtMs !== null) {
        const limitFirst = endsAtMs !== null && endsAtMs <= stoppedAtMs;
        endedAtMs = limitFirst ? endsAtMs : stoppedAtMs;
        endedBy = limitFirst ? 'limit' : 'stop';
    } else if (endsAtMs !== null && serverNowMs >= endsAtMs) {
        endedAtMs = endsAtMs;
        endedBy = 'limit';
    }

    if (endedAtMs !== null) {
        return {
            ...view,
            screen: 'ended',
            countdownMs: 0,
            elapsedMs: Math.max(0, endedAtMs - startedAtMs),
            remainingMs: limitMs === null ? null : 0,
            endedAtMs,
            endedBy,
        };
    }

    if (serverNowMs < startedAtMs) {
        const countdownMs = startedAtMs - serverNowMs;

        return { ...view, screen: countdownMs <= COUNTDOWN_MS ? 'countdown' : 'scheduled', countdownMs };
    }

    return {
        ...view,
        screen: 'running',
        countdownMs: 0,
        elapsedMs: serverNowMs - startedAtMs,
        remainingMs: endsAtMs === null ? null : endsAtMs - serverNowMs,
    };
}

function asOfOf(state) {
    return state !== null && typeof state === 'object' ? finiteOrNull(state.asOfMs) : null;
}

/**
 * The newer of two states by `asOfMs` - `a` on a tie (the same read can not be told apart). A state without `asOfMs`
 * never replaces one with it; between two without, the later arrival (`b`) wins.
 */
export function newer(a, b) {
    if (b === null || b === undefined) {
        return a ?? null;
    }

    if (a === null || a === undefined) {
        return b;
    }

    const asOfA = asOfOf(a);
    const asOfB = asOfOf(b);

    if (asOfB === null) {
        return asOfA === null ? b : a;
    }

    if (asOfA === null) {
        return b;
    }

    return asOfB > asOfA ? b : a;
}

function clockFieldsOf(source) {
    const fields = {};

    for (const field of CLOCK_FIELDS) {
        if (Object.prototype.hasOwnProperty.call(source, field)) {
            fields[field] = source[field];
        }
    }

    // A payload with only the ATOM times (older shape) replaces the millisecond ones too - never a stale mix
    for (const [ms, atom] of [['startedAtMs', 'startedAt'], ['stoppedAtMs', 'stoppedAt']]) {
        if (!Object.prototype.hasOwnProperty.call(fields, ms) && Object.prototype.hasOwnProperty.call(fields, atom)) {
            fields[ms] = epochMs(null, fields[atom]);
        }
    }

    return fields;
}

function clockAsOfOf(state) {
    return finiteOrNull(state?.round?.clockAsOfMs) ?? asOfOf(state);
}

function sameRound(state, update) {
    const updateRound = typeof update.roundId === 'string' ? update.roundId : (typeof update.id === 'string' ? update.id : null);
    const stateRound = typeof state.round?.id === 'string' ? state.round.id : null;

    return updateRound === null || stateRound === null || updateRound.toLowerCase() === stateRound.toLowerCase();
}

/**
 * A round clock pushed on the round's topic (`/round-stopwatch/{roundId}`), applied to the state's round when it is
 * newer than what that clock was read at. Returns the state unchanged (the same object) otherwise.
 */
export function adoptRoundClock(state, update) {
    if (state === null || typeof state !== 'object' || state.round === null || typeof state.round !== 'object') {
        return state;
    }

    if (update === null || typeof update !== 'object' || !sameRound(state, update)) {
        return state;
    }

    const incoming = asOfOf(update);
    const known = clockAsOfOf(state);

    if (incoming === null || (known !== null && incoming <= known)) {
        return state;
    }

    return { ...state, round: { ...state.round, ...clockFieldsOf(update), clockAsOfMs: incoming } };
}

/**
 * A full state (the state fetch): taken when newer than the one held - keeping a round clock pushed meanwhile that is
 * newer still. Returns `current` (the same object) when nothing changes.
 */
export function adoptState(current, incoming) {
    if (newer(current, incoming) !== incoming || incoming === current) {
        return current;
    }

    const pushedAsOf = finiteOrNull(current?.round?.clockAsOfMs);
    const incomingAsOf = asOfOf(incoming);

    if (
        pushedAsOf !== null
        && incomingAsOf !== null
        && pushedAsOf > incomingAsOf
        && incoming.round !== null
        && typeof incoming.round === 'object'
        && sameRound(incoming, current.round)
    ) {
        return { ...incoming, round: { ...incoming.round, ...clockFieldsOf(current.round), clockAsOfMs: pushedAsOf } };
    }

    return incoming;
}

/**
 * The next moment (server time) the page should fetch the state - 2 s before the start, 1 s after it (a missed
 * cancel), 1 s after the time limit (a missed "End now" is long caught by then, this one confirms the end); null when
 * nothing is coming.
 */
export function nextStateCheckAt(state, serverNowMs) {
    const clock = readClock(state);

    if (clock === null || clock.startedAtMs === null || clock.stoppedAtMs !== null || !Number.isFinite(serverNowMs)) {
        return null;
    }

    const moments = [clock.startedAtMs - CHECK_BEFORE_START_MS, clock.startedAtMs + CHECK_AFTER_MS];

    if (clock.endsAtMs !== null) {
        moments.push(clock.endsAtMs + CHECK_AFTER_MS);
    }

    return moments.find((moment) => moment > serverNowMs) ?? null;
}

/**
 * A duration as HH:MM:SS - whole seconds rounded down (the stopwatch and the official time do), or up (`ceil`: what
 * is left, so it reaches 00:00:00 exactly at the end). Never negative.
 */
export function formatClock(ms, { ceil = false } = {}) {
    let seconds = 0;

    if (Number.isFinite(ms) && ms > 0) {
        seconds = ceil ? Math.ceil(ms / 1000) : Math.floor(ms / 1000);
    }

    const hours = Math.floor(seconds / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);

    return [hours, minutes, seconds % 60].map((part) => String(part).padStart(2, '0')).join(':');
}

/**
 * The countdown's big digit: 10 … 1, then 0 = GO. The digit changes on the whole seconds before the start.
 */
export function countdownDigit(countdownMs) {
    return Number.isFinite(countdownMs) && countdownMs > 0 ? Math.ceil(countdownMs / 1000) : 0;
}

/**
 * The time a finish moment gives (README §5.9): whole seconds since the start, rounded down. Null when the moment is
 * missing or before the start.
 */
export function resultSeconds(momentMs, startedAtMs) {
    if (!Number.isFinite(momentMs) || !Number.isFinite(startedAtMs) || momentMs < startedAtMs) {
        return null;
    }

    return Math.floor((momentMs - startedAtMs) / 1000);
}
