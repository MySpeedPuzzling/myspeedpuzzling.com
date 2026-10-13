/**
 * The participant page's decisions (docs/features/online-competitions/README.md §5.5, §5.6, §5.10) - which screen it
 * shows, when the set-up checklist lets the participant say "I'm ready", when the next periodic photo is due, the
 * Finished tap's undo window, the pieces typed in at time-up, and what the page remembers on the device. Pure: the
 * state, the round's view (round_state.js deriveView()) and what the page knows in, a decision out.
 *
 * Screens (README §5.5): welcome → checklist → waiting (no start yet, or a scheduled one) → countdown (the last 10 s and
 * GO) → running → finished (a Finished tap recorded) → timeup (the round ended while still puzzling) → after. Besides:
 * no_round (the competition has no round with online timing), not_entered (the participant is not in the round),
 * taken_over (the link was opened on another device), dead_link (the organiser made a new link).
 *
 * Pinned by tests/online-compete-harness.mjs.
 */

import { formatClock } from './round_state.js';

export const SCREENS = [
    'welcome', 'checklist', 'waiting', 'countdown', 'running', 'finished', 'timeup', 'after',
    'no_round', 'not_entered', 'taken_over', 'dead_link',
];

// GO stays on screen this long after the start, then the running stopwatch takes over
export const GO_SHOWN_MS = 1500;
// "I tapped by mistake" is offered this long after a Finished tap
export const UNDO_WINDOW_MS = 10000;
// Periodic photos (README §5.6): every 30 s, every 10 s in the last 3 minutes before GO (the unwrapping on camera)
export const PERIODIC_EVERY_MS = 30000;
export const PERIODIC_UNWRAP_EVERY_MS = 10000;
export const UNWRAP_WINDOW_MS = 3 * 60 * 1000;
// A scheduled start further away than this takes no periodic photos yet - nothing to see on an empty table for hours
export const PERIODIC_LEAD_MS = 30 * 60 * 1000;
// The practice round: a 10-second countdown, then one minute
export const PRACTICE_COUNTDOWN_MS = 10000;
export const PRACTICE_MINUTES = 1;
// A practice round must end before a scheduled start comes this close
export const PRACTICE_CLEARANCE_MS = 15000;
// The connection counts as working while an answer came this recently
export const CONNECTION_FRESH_MS = 45000;

// What the page remembers on the device, per participant (consent) and per participant + round (the rest)
const CONSENT_PREFIX = 'msp-compete-consent:';
const PROGRESS_PREFIX = 'msp-compete:';
export const DEVICE_KEY = 'msp-online-device';

/**
 * @typedef {object} Finish   a Finished tap this device recorded
 * @property {string} eventId
 * @property {number} momentMs        the tap on the server's clock (the event's clientMs)
 * @property {boolean} undone         "I tapped by mistake" within the undo window
 * @property {boolean} continued      "Keep puzzling" - the tap stays (evidence), the participant goes on
 */

/**
 * @typedef {object} Progress   one participant's progress in one round on this device
 * @property {boolean} consentSent    consent.given was recorded for this round
 * @property {boolean} ready          "I'm ready" was tapped (device.ready)
 * @property {Finish[]} finishes
 * @property {{measure: string, pieces: number}|null} pieces   reported at time-up
 * @property {boolean} emptyMat       the empty-mat photo was taken
 * @property {Object<string, boolean>} seen   once-per-start marks: `countdown:<startedAtMs>`, `start:<…>`, `startPhoto:<…>`, …
 */

export function emptyProgress() {
    return { consentSent: false, ready: false, finishes: [], pieces: null, emptyMat: false, seen: {} };
}

/**
 * The tap the page stands on: the latest one neither undone nor continued from - null when there is none.
 *
 * @param {Progress} progress
 * @returns {Finish|null}
 */
export function currentFinish(progress) {
    const finishes = Array.isArray(progress?.finishes) ? progress.finishes : [];

    for (let index = finishes.length - 1; index >= 0; index--) {
        const finish = finishes[index];

        if (finish && !finish.undone && !finish.continued) {
            return finish;
        }
    }

    return null;
}

/**
 * How long "I tapped by mistake" is still offered - on the server's clock, so a reload keeps the window.
 */
export function undoLeftMs(finish, serverNowMs) {
    if (!finish || !Number.isFinite(finish.momentMs) || !Number.isFinite(serverNowMs)) {
        return 0;
    }

    return Math.max(0, finish.momentMs + UNDO_WINDOW_MS - serverNowMs);
}

function hasRound(state) {
    return state !== null && typeof state === 'object' && state.round !== null && typeof state.round === 'object';
}

/**
 * The screen.
 *
 * @param {object} input
 * @param {object|null} input.state            the participant state (implementation-plan.md §3.1)
 * @param {object} input.view                  deriveView(state, serverNow)
 * @param {boolean} input.consent
 * @param {Progress} input.progress
 * @param {boolean} [input.takenOver]          the link is active on another device
 * @param {boolean} [input.linkDead]           the token opens nothing any more
 * @returns {string} one of SCREENS
 */
export function screenFor({ state, view, consent, progress, takenOver = false, linkDead = false }) {
    if (linkDead) {
        return 'dead_link';
    }

    if (!hasRound(state)) {
        return 'no_round';
    }

    if (state.entry === null || typeof state.entry !== 'object') {
        return 'not_entered';
    }

    const ready = progress?.ready === true;
    const finish = currentFinish(progress);
    const continued = (progress?.finishes ?? []).some((item) => item && item.continued && !item.undone);
    // A tap the server knows of (another device, or this device's memory was cleared) counts too - unless this device
    // went on puzzling after its own
    const finished = finish !== null || (!continued && Number(state.entry.taps ?? 0) > 0);
    const reported = progress?.pieces !== null && progress?.pieces !== undefined
        || (state.entry.reportedPieces !== null && state.entry.reportedPieces !== undefined);

    if (view?.screen === 'ended') {
        // Still puzzling when the time ran out: the pieces question - for a page that took part in the round
        return ready && consent && !finished && !reported ? 'timeup' : 'after';
    }

    if (!consent) {
        return 'welcome';
    }

    if (!ready) {
        return 'checklist';
    }

    if (takenOver) {
        return 'taken_over';
    }

    switch (view?.screen) {
        case 'countdown':
            return 'countdown';
        case 'running':
            if (Number.isFinite(view.elapsedMs) && view.elapsedMs < GO_SHOWN_MS) {
                return 'countdown';
            }

            return finished ? 'finished' : 'running';
        default:
            return 'waiting';
    }
}

/**
 * Screens on which the camera runs (and the preview is shown).
 */
export function cameraWanted(screen, practiceActive = false) {
    return practiceActive || ['checklist', 'waiting', 'countdown', 'running', 'finished', 'timeup'].includes(screen);
}

/**
 * The checklist's verdict: "I'm ready" needs the camera on and the clock synced (README §5.5).
 *
 * @param {{status: string}|null} camera
 * @param {{synced: boolean}|null} clock   clock_sync.js quality()
 */
export function readiness(camera, clock) {
    const cameraOk = camera?.status === 'live';
    const clockOk = clock?.synced === true;

    return { cameraOk, clockOk, canBeReady: cameraOk && clockOk };
}

// Every code a checklist row can have - the page's texts are `check_<row>_<code>` (tests/CompeteTextsTest.php reads
// this list, tests/online-compete-harness.mjs keeps checklistRows() inside it)
export const CHECK_CODES = {
    camera: ['live', 'opening', 'muted', 'denied', 'unavailable', 'busy', 'ended', 'error', 'off'],
    clock: ['synced', 'slow', 'syncing'],
    wake: ['active', 'released', 'unsupported', 'denied', 'on_ready'],
    connection: ['offline', 'failing', 'ok', 'checking'],
    uploads: ['none', 'blocked', 'refused', 'offline', 'pending', 'ok'],
    emptyMat: ['taken', 'take'],
};

/**
 * One row per check, each `{level: 'ok'|'warn'|'bad'|'info', code}` - the page turns the code into a text.
 *
 * @param {object} input
 * @param {{status: string, width?: number, height?: number}} input.camera
 * @param {{synced: boolean, samples: number, errorMs: number|null, rttMs: number|null}} input.clock
 * @param {boolean} input.clockSyncing
 * @param {string} input.wakeLock            wake_lock.js state
 * @param {{online: boolean, lastOkAgoMs: number|null, failing: boolean}} input.connection
 * @param {{pending: number, refused: number, uploaded: number, offline: boolean, blocked: string|null}|null} input.uploads
 * @param {boolean} input.emptyMat
 */
export function checklistRows({ camera, clock, clockSyncing = false, wakeLock, connection, uploads, emptyMat }) {
    return {
        camera: cameraRow(camera),
        clock: clockRow(clock, clockSyncing),
        wake: wakeRow(wakeLock),
        connection: connectionRow(connection),
        uploads: uploadsRow(uploads),
        emptyMat: emptyMat ? { level: 'ok', code: 'taken' } : { level: 'info', code: 'take' },
    };
}

function cameraRow(camera) {
    switch (camera?.status) {
        case 'live':
            return { level: 'ok', code: 'live' };
        case 'opening':
            return { level: 'warn', code: 'opening' };
        case 'muted':
            return { level: 'warn', code: 'muted' };
        case 'denied':
        case 'unavailable':
        case 'busy':
        case 'ended':
        case 'error':
            return { level: 'bad', code: camera.status };
        default:
            return { level: 'warn', code: 'off' };
    }
}

function clockRow(clock, syncing) {
    if (clock?.synced) {
        return { level: 'ok', code: 'synced' };
    }

    // Samples came, all of them too slow to bound the clock (README §5.2 "Readiness gate")
    if (!syncing && Number(clock?.samples ?? 0) > 0) {
        return { level: 'bad', code: 'slow' };
    }

    return { level: 'warn', code: 'syncing' };
}

function wakeRow(state) {
    switch (state) {
        case 'active':
            return { level: 'ok', code: 'active' };
        case 'released':
            return { level: 'warn', code: 'released' };
        case 'unsupported':
            return { level: 'warn', code: 'unsupported' };
        case 'denied':
            return { level: 'warn', code: 'denied' };
        default:
            // Asked on the "I'm ready" tap - Safari grants it only right after one
            return { level: 'info', code: 'on_ready' };
    }
}

function connectionRow(connection) {
    if (connection?.online === false) {
        return { level: 'bad', code: 'offline' };
    }

    if (connection?.failing) {
        return { level: 'bad', code: 'failing' };
    }

    if (Number.isFinite(connection?.lastOkAgoMs) && connection.lastOkAgoMs <= CONNECTION_FRESH_MS) {
        return { level: 'ok', code: 'ok' };
    }

    return { level: 'warn', code: 'checking' };
}

function uploadsRow(uploads) {
    if (!uploads) {
        return { level: 'info', code: 'none' };
    }

    if (uploads.blocked) {
        return { level: 'bad', code: 'blocked' };
    }

    if (uploads.refused > 0) {
        return { level: 'bad', code: 'refused', count: uploads.refused };
    }

    if (uploads.offline) {
        return { level: 'warn', code: 'offline', count: uploads.pending };
    }

    if (uploads.pending > 0) {
        return { level: uploads.uploaded > 0 ? 'ok' : 'warn', code: 'pending', count: uploads.pending };
    }

    return uploads.uploaded > 0 ? { level: 'ok', code: 'ok' } : { level: 'info', code: 'none' };
}

/**
 * How often periodic photos are taken in this view - null: none (README §5.6: every 30 s to the end, every 10 s in the
 * last 3 minutes before GO; a start hours away takes none yet).
 *
 * @param {object} view   deriveView()
 */
export function periodicGapMs(view) {
    switch (view?.screen) {
        case 'waiting':
        case 'running':
            return PERIODIC_EVERY_MS;
        case 'scheduled':
        case 'countdown':
            if (!Number.isFinite(view.countdownMs) || view.countdownMs > PERIODIC_LEAD_MS) {
                return null;
            }

            return view.countdownMs <= UNWRAP_WINDOW_MS ? PERIODIC_UNWRAP_EVERY_MS : PERIODIC_EVERY_MS;
        default:
            return null;
    }
}

/**
 * When the next periodic photo is due (server time) - null when none is.
 *
 * @param {object} view
 * @param {number} serverNowMs
 * @param {number|null} lastTakenMs   the last periodic photo of this round (server time)
 */
export function nextPeriodicPhotoAt(view, serverNowMs, lastTakenMs) {
    const gap = periodicGapMs(view);

    if (gap === null || !Number.isFinite(serverNowMs)) {
        return null;
    }

    if (!Number.isFinite(lastTakenMs) || lastTakenMs > serverNowMs) {
        return serverNowMs;
    }

    return lastTakenMs + gap;
}

/**
 * The pieces typed in at time-up (README §5.10): a whole number from 0 up to the puzzle's pieces (when known).
 *
 * @returns {{ok: true, pieces: number}|{ok: false, reason: 'empty'|'not_a_number'|'too_many'}}
 */
export function readPieces(text, piecesTotal = null) {
    const value = String(text ?? '').trim();

    if (value === '') {
        return { ok: false, reason: 'empty' };
    }

    if (!/^\d{1,6}$/.test(value)) {
        return { ok: false, reason: 'not_a_number' };
    }

    const pieces = parseInt(value, 10);

    if (Number.isFinite(piecesTotal) && piecesTotal > 0 && pieces > piecesTotal) {
        return { ok: false, reason: 'too_many' };
    }

    return { ok: true, pieces };
}

/**
 * The practice round's clock as a round clock (deriveView() reads it like the real one).
 */
export function practiceState(practice) {
    return {
        asOfMs: 0,
        round: {
            id: 'practice',
            startedAtMs: practice.startedAtMs,
            stoppedAtMs: null,
            minutesLimit: PRACTICE_MINUTES,
        },
    };
}

/**
 * A practice round begins on the next whole second at least 10 s ahead (the real start's rule).
 */
export function practiceStartAt(serverNowMs) {
    return Math.ceil((serverNowMs + PRACTICE_COUNTDOWN_MS) / 1000) * 1000;
}

/**
 * Practice may start while nothing real is coming: no countdown, nothing running, and a scheduled start far enough
 * away that the minute fits in front of it.
 *
 * @param {object} view       the real round's view
 */
export function practiceAllowed(view) {
    const screen = view?.screen ?? 'waiting';

    if (screen === 'waiting' || screen === 'ended') {
        return true;
    }

    if (screen !== 'scheduled') {
        return false;
    }

    return Number.isFinite(view.countdownMs)
        && view.countdownMs > PRACTICE_COUNTDOWN_MS + PRACTICE_MINUTES * 60000 + PRACTICE_CLEARANCE_MS;
}

/**
 * An event as the outbox keeps it and the server receives it (implementation-plan.md §3.1).
 *
 * @param {string} type
 * @param {{clientMs: number, perfMs: number, offsetMs: number|null, rttMs: number|null}} stamp  clock_sync.js stamp()
 * @param {{id: string, roundId: string, data?: object}} details
 */
export function buildEvent(type, stamp, { id, roundId, data = {} }) {
    return {
        id,
        type,
        roundId,
        clientMs: stamp.clientMs,
        perfMs: stamp.perfMs,
        offsetMs: stamp.offsetMs,
        rttMs: stamp.rttMs,
        data: data ?? {},
    };
}

// A round this long or shorter shows its clock as MM:SS - five characters can be twice as big as HH:MM:SS
export const SHORT_CLOCK_LIMIT_MS = 60 * 60 * 1000;

/**
 * Whether the round's clock is shown as MM:SS (the whole round fits in an hour) - the page sizes the digits by it.
 */
export function shortClock(limitMs) {
    return Number.isFinite(limitMs) && limitMs > 0 && limitMs <= SHORT_CLOCK_LIMIT_MS;
}

/**
 * The stopwatch as the page shows it: HH:MM:SS, or MM:SS for a round of an hour or less (60:00 at its very end) - the
 * same whole seconds as formatClock() (rounded down, or up with `ceil`).
 */
export function clockText(ms, { limitMs = null, ceil = false } = {}) {
    const full = formatClock(ms, { ceil });

    if (!shortClock(limitMs)) {
        return full;
    }

    const [hours, minutes, seconds] = full.split(':').map((part) => parseInt(part, 10));

    return `${String(hours * 60 + minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
}

/**
 * The time to a start ("Starts in 2:13:05" / "Starts in 00:30"): rounded up - it reaches zero at the start itself -
 * without the hours while there are none.
 */
export function countdownText(ms) {
    const full = formatClock(ms, { ceil: true });

    return full.startsWith('00:') ? full.slice(3) : full;
}

/**
 * A text with its %placeholders% filled.
 */
export function fill(text, params = {}) {
    let result = String(text ?? '');

    for (const [name, value] of Object.entries(params)) {
        result = result.replaceAll(`%${name}%`, String(value));
    }

    return result;
}

// ---- what the page remembers on the device (any Storage: localStorage, or a memory one in preview / private mode)

function read(storage, key) {
    try {
        const raw = storage?.getItem(key);

        return raw === null || raw === undefined ? null : JSON.parse(raw);
    } catch (e) {
        return null;
    }
}

function write(storage, key, value) {
    try {
        storage?.setItem(key, JSON.stringify(value));

        return true;
    } catch (e) {
        return false;
    }
}

export function loadConsent(storage, participantId) {
    return read(storage, CONSENT_PREFIX + participantId)?.consent === true;
}

export function saveConsent(storage, participantId, atMs) {
    return write(storage, CONSENT_PREFIX + participantId, { consent: true, atMs });
}

/**
 * @returns {Progress}
 */
export function loadProgress(storage, participantId, roundId) {
    const stored = roundId ? read(storage, `${PROGRESS_PREFIX}${participantId}:${roundId}`) : null;
    const progress = emptyProgress();

    if (stored === null || typeof stored !== 'object') {
        return progress;
    }

    progress.consentSent = stored.consentSent === true;
    progress.ready = stored.ready === true;
    progress.emptyMat = stored.emptyMat === true;
    progress.finishes = Array.isArray(stored.finishes)
        ? stored.finishes.filter((finish) => finish && typeof finish.eventId === 'string' && Number.isFinite(finish.momentMs))
            .map((finish) => ({ eventId: finish.eventId, momentMs: finish.momentMs, undone: finish.undone === true, continued: finish.continued === true }))
        : [];
    progress.pieces = stored.pieces && Number.isFinite(stored.pieces.pieces)
        ? { measure: String(stored.pieces.measure ?? ''), pieces: stored.pieces.pieces }
        : null;
    progress.seen = stored.seen && typeof stored.seen === 'object' ? { ...stored.seen } : {};

    return progress;
}

export function saveProgress(storage, participantId, roundId, progress) {
    if (!roundId) {
        return false;
    }

    return write(storage, `${PROGRESS_PREFIX}${participantId}:${roundId}`, progress);
}

/**
 * This browser's device id (README §5.4 "Several devices on one link") - kept, so a reload is no takeover.
 */
export function deviceIdFrom(storage, newId) {
    try {
        const known = storage?.getItem(DEVICE_KEY);

        if (typeof known === 'string' && /^[A-Za-z0-9-]{8,64}$/.test(known)) {
            return known;
        }
    } catch (e) {
        // A private window - a new id for this page
    }

    const id = newId();

    try {
        storage?.setItem(DEVICE_KEY, id);
    } catch (e) {
        // Not kept - fine
    }

    return id;
}

/**
 * A Storage in memory (preview as organiser, private windows): same calls, nothing outlives the page.
 */
export function memoryStorage() {
    const items = new Map();

    return {
        getItem: (key) => (items.has(key) ? items.get(key) : null),
        setItem: (key, value) => {
            items.set(key, String(value));
        },
        removeItem: (key) => {
            items.delete(key);
        },
    };
}
