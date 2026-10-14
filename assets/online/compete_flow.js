/**
 * The participant page's decisions (docs/features/online-competitions/README.md §5.5, §5.6, §5.10) - which screen it
 * shows, when the set-up checklist lets the participant say "I'm ready", when the next periodic photo is due, the
 * Finished tap's undo window, the pieces typed in at time-up, and what the page remembers on the device. Pure: the
 * state, the round's view (round_state.js deriveView()) and what the page knows in, a decision out.
 *
 * Screens (README §5.5): welcome → checklist → waiting (no start yet, or a scheduled one) → countdown (the last 10 s and
 * GO) → running → finished (a Finished tap recorded) → timeup (the round ended while still puzzling) → after. Besides:
 * no_round (the competition has no round with online timing), not_entered (the participant is not in the round),
 * taken_over (the link was opened on another device), dead_link (the organiser made a new link); with live video
 * second_screen (this device watches next to the camera phone - compete_video.js, implementation-plan.md 1B.6).
 *
 * Pinned by tests/online-compete-harness.mjs.
 */

import { formatClock, readClock } from './round_state.js';

export const SCREENS = [
    'welcome', 'checklist', 'waiting', 'countdown', 'running', 'finished', 'timeup', 'after',
    'no_round', 'not_entered', 'taken_over', 'dead_link', 'second_screen',
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

// What the page remembers on the device: per participant the consent; per participant + round the set-up (consent sent,
// ready, the empty mat, the once-per-start marks); per participant + round + run what happened in the run - the
// Finished taps, the finish candidates (raised hands) and the pieces reported at time-up. A run is one start of the round clock (its startedAtMs): a Reset and
// a new start begin a fresh one, so nothing of the run that was carries over (the server counts the same way)
const CONSENT_PREFIX = 'msp-compete-consent:';
const PROGRESS_PREFIX = 'msp-compete:';
const RUN_INFIX = ':run:';
export const DEVICE_KEY = 'msp-online-device';

/**
 * @typedef {object} Finish   a Finished tap this device recorded
 * @property {string} eventId
 * @property {number} momentMs        the tap on the server's clock (the event's clientMs)
 * @property {boolean} undone         "I tapped by mistake" within the undo window
 * @property {boolean} continued      "Keep puzzling" - the tap stays (evidence), the participant goes on
 */

/**
 * @typedef {object} Progress   one participant's progress in one round on this device - its run part (finishes,
 *                              pieces) is the current run's
 * @property {boolean} consentSent    consent.given was recorded for this round
 * @property {boolean} ready          "I'm ready" was tapped (device.ready)
 * @property {Finish[]} finishes      this run's Finished taps
 * @property {{eventId: string, momentMs: number}[]} detections   this run's finish candidates recorded on this device
 *                                    (Phase 2: raised hands - compete_detection_page.js; shown in `on` mode)
 * @property {{measure: string, pieces: number}|null} pieces   reported at this run's time-up
 * @property {boolean} emptyMat       the empty-mat photo was taken
 * @property {Object<string, boolean>} seen   once-per-start marks: `countdown:<startedAtMs>`, `start:<…>`, `startPhoto:<…>`, …
 */

export function emptyProgress() {
    return { consentSent: false, ready: false, finishes: [], detections: [], pieces: null, emptyMat: false, seen: {} };
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
 * @param {boolean} [input.secondScreen]       this device is a second screen (live video): it watches, records nothing
 * @returns {string} one of SCREENS
 */
export function screenFor({ state, view, consent, progress, takenOver = false, linkDead = false, secondScreen = false }) {
    if (linkDead) {
        return 'dead_link';
    }

    if (!hasRound(state)) {
        return 'no_round';
    }

    if (state.entry === null || typeof state.entry !== 'object') {
        return 'not_entered';
    }

    // Watching next to the camera phone - the round's clock to its end, never Finished
    if (secondScreen) {
        return 'second_screen';
    }

    const ready = progress?.ready === true;
    const finish = currentFinish(progress);
    // This device's own taps decide (an undo or "keep puzzling" may not have reached the state held yet); the server's
    // count stands in only for a device that recorded none - a tap on another device, or this one's memory was cleared
    const tappedHere = (progress?.finishes ?? []).length > 0;
    const finished = finish !== null || (!tappedHere && Number(state.entry.taps ?? 0) > 0);
    const reported = (progress?.pieces !== null && progress?.pieces !== undefined)
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
 * The next moment (server time) the round's clock on screen changes: the countdown's next digit (GO at the start
 * itself), the stopwatch's next second, the end of GO_SHOWN_MS - null while nothing counts (no start, ended). The page
 * renders at that very frame instead of within its usual 50 ms, so GO and every second appear on time
 * (README §5.2: phones side by side within ±100 ms) - measured 2026-10-13: GO up to 50 ms late without it.
 *
 * @param {object} view           deriveView()
 * @param {number} serverNowMs
 * @returns {number|null}
 */
export function nextDisplayChangeAt(view, serverNowMs) {
    const startedAtMs = view?.startedAtMs;

    if (!Number.isFinite(startedAtMs) || !Number.isFinite(serverNowMs) || view.screen === 'ended') {
        return null;
    }

    if (serverNowMs < startedAtMs) {
        // The digit shown is ceil(left / 1000): the next one comes when `left` reaches the whole second below it
        return startedAtMs - (Math.ceil((startedAtMs - serverNowMs) / 1000) - 1) * 1000;
    }

    const nextSecond = startedAtMs + (Math.floor((serverNowMs - startedAtMs) / 1000) + 1) * 1000;
    const goEnds = startedAtMs + GO_SHOWN_MS;

    return serverNowMs < goEnds ? Math.min(nextSecond, goEnds) : nextSecond;
}

// Several devices on one link (README §5.4): the device that said "I'm ready" last holds it. After this device claimed
// it ("I'm ready", "Use this device instead"), a heartbeat answer naming another device is believed only once the claim
// was stored by the server and this long has passed since - a heartbeat sent earlier may have been answered from before
// the claim. So a device never gives way to an answer older than its own claim (no flapping when two devices fight),
// and the device that lost the link learns it at its next heartbeat (every 15 s): ≤ 15 s + a round trip after the other
// device's claim was stored, and when it had claimed itself just before, a heartbeat goes again when the grace ends
// (≤ the grace + a round trip after its own claim was stored) - always under 20 s.
export const TAKEOVER_GRACE_MS = 3000;

/**
 * What a heartbeat's answer says about the link: is another device holding it (`otherDeviceActive` - the checklist's
 * "in use on another device"), was this device taken over (`takenOver` - it stops recording and says so), and when to
 * ask again (`recheckAt`: an answer set aside during the grace).
 *
 * `takenOver` is never undone by an answer: only this device's own claim takes the link back - a stale answer must
 * never make two devices record.
 *
 * @param {object} input
 * @param {string|null} input.activeDevice      the answer's `takenOverBy`
 * @param {string} input.deviceId
 * @param {boolean} input.ready                 "I'm ready" was tapped on this device
 * @param {boolean} input.takenOver             what the page shows now
 * @param {{pending: boolean, ackedAt: number|null}|null} [input.claim]   this page's last claim (null: none since it
 *                                              loaded - a reload of a ready page claims nothing)
 * @param {number} input.beatSentAt             when the heartbeat was sent (Date.now() of this device)
 * @returns {{otherDeviceActive: boolean, takenOver: boolean, recheckAt: number|null}}
 */
export function takeoverVerdict({ activeDevice, deviceId, ready, takenOver, claim = null, beatSentAt }) {
    const other = typeof activeDevice === 'string' && activeDevice !== '' && activeDevice !== deviceId;

    if (!other || !ready || takenOver) {
        return { otherDeviceActive: other, takenOver, recheckAt: null };
    }

    if (claim !== null && claim !== undefined) {
        // The server has not stored this device's claim yet - the answer says nothing about it
        if (claim.pending) {
            return { otherDeviceActive: true, takenOver: false, recheckAt: null };
        }

        const trustedFrom = (Number.isFinite(claim.ackedAt) ? claim.ackedAt : 0) + TAKEOVER_GRACE_MS;

        if (!Number.isFinite(beatSentAt) || beatSentAt < trustedFrom) {
            return { otherDeviceActive: true, takenOver: false, recheckAt: trustedFrom };
        }
    }

    return { otherDeviceActive: true, takenOver: true, recheckAt: null };
}

// Requests in a row that went wrong before the status line says so - one is a hiccup
export const CONNECTION_FAILURES_SHOWN = 2;

/**
 * The status line's connection (README §5.5): `offline` only when the network is down - the browser says so, or
 * requests got no answer at all (a captive portal's page counts as none); `problem` when the server answered with an
 * error or a refusal to wait (5xx, 429 …) - the page keeps retrying; `online` otherwise.
 *
 * @param {{online: boolean, noAnswers: number, failedAnswers: number}} input   failures in a row, by kind
 * @returns {'online'|'offline'|'problem'}
 */
export function connectionStatus({ online, noAnswers = 0, failedAnswers = 0 }) {
    if (online === false || noAnswers >= CONNECTION_FAILURES_SHOWN) {
        return 'offline';
    }

    return failedAnswers >= CONNECTION_FAILURES_SHOWN ? 'problem' : 'online';
}

/**
 * What an API answer (compete_api.js) says about the connection: `ok` (it answered - a refusal of ours included),
 * `none` (no answer, or a page that is not ours - a captive portal), `failed` (an error, or a refusal to wait).
 */
export function answerReach(answer) {
    switch (answer?.kind) {
        case 'ok':
        case 'client':
        case 'forbidden':
            return 'ok';
        case 'server':
            return 'failed';
        default:
            return 'none';
    }
}

/**
 * Screens on which the camera runs (and the preview is shown).
 */
export function cameraWanted(screen, practiceActive = false) {
    return practiceActive || ['checklist', 'waiting', 'countdown', 'running', 'finished', 'timeup'].includes(screen);
}

// The screens a ready participant waits and competes on: the start's beeps, GO and the time's-up alarm play here, and
// the screen must not lock (a locked phone stops the camera)
export const WAKE_SCREENS = ['waiting', 'countdown', 'running', 'finished'];

/**
 * "Tap here once" (README §5.5 "Browser realities"): a page that loads again - a reload, the browser bringing a
 * discarded tab back - has no sound (browsers play nothing before a tap) and, in Safari, no screen lock until the
 * participant touches it, though "I'm ready" was tapped long ago. Shown to a ready participant on the round's screens
 * until the first touch of this page (which unlocks the sound and asks for the lock) - not in a practice (its own tap
 * unlocked them), never twice.
 *
 * @param {object} input
 * @param {boolean} input.ready          "I'm ready" was tapped on this device (the progress survives a reload)
 * @param {string} input.screen          screenFor()
 * @param {boolean} [input.practice]
 * @param {boolean} input.touched        a tap on this page since it loaded
 * @param {boolean} input.soundLocked    the beeps are not unlocked
 * @param {string} input.wakeLock        wake_lock.js state
 */
export function wakeTapNeeded({ ready, screen, practice = false, touched, soundLocked, wakeLock }) {
    if (ready !== true || practice || touched || !WAKE_SCREENS.includes(screen)) {
        return false;
    }

    // Held, being asked for, or impossible on this browser: a tap would not change the lock
    return soundLocked || !['active', 'pending', 'unsupported'].includes(wakeLock);
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

/**
 * The run a state is about: the start of its round clock (startedAtMs, a countdown's too) - null without a round or a
 * start (none yet, or reset since).
 */
export function runKeyOf(state) {
    return readClock(state)?.startedAtMs ?? null;
}

function readFinishes(stored, fromMs = null) {
    if (!Array.isArray(stored)) {
        return [];
    }

    return stored.filter((finish) => finish && typeof finish.eventId === 'string' && Number.isFinite(finish.momentMs)
        && (fromMs === null || finish.momentMs >= fromMs))
        .map((finish) => ({ eventId: finish.eventId, momentMs: finish.momentMs, undone: finish.undone === true, continued: finish.continued === true }));
}

function readDetections(stored) {
    if (!Array.isArray(stored)) {
        return [];
    }

    return stored.filter((item) => item && typeof item.eventId === 'string' && Number.isFinite(item.momentMs))
        .map((item) => ({ eventId: item.eventId, momentMs: item.momentMs }));
}

function readReportedPieces(stored) {
    return stored && Number.isFinite(stored.pieces)
        ? { measure: String(stored.measure ?? ''), pieces: stored.pieces }
        : null;
}

export function loadConsent(storage, participantId) {
    return read(storage, CONSENT_PREFIX + participantId)?.consent === true;
}

export function saveConsent(storage, participantId, atMs) {
    return write(storage, CONSENT_PREFIX + participantId, { consent: true, atMs });
}

/**
 * The progress in a round on this device, with the run part of `runKey` (runKeyOf() - null: no run, nothing of one).
 *
 * @returns {Progress}
 */
export function loadProgress(storage, participantId, roundId, runKey = null) {
    const stored = roundId ? read(storage, `${PROGRESS_PREFIX}${participantId}:${roundId}`) : null;
    const progress = emptyProgress();

    if (stored !== null && typeof stored === 'object') {
        progress.consentSent = stored.consentSent === true;
        progress.ready = stored.ready === true;
        progress.emptyMat = stored.emptyMat === true;
        progress.seen = stored.seen && typeof stored.seen === 'object' ? { ...stored.seen } : {};
    }

    if (!roundId || !Number.isFinite(runKey)) {
        return progress;
    }

    const run = read(storage, `${PROGRESS_PREFIX}${participantId}:${roundId}${RUN_INFIX}${runKey}`);

    if (run !== null && typeof run === 'object') {
        progress.finishes = readFinishes(run.finishes);
        progress.detections = readDetections(run.detections);
        progress.pieces = readReportedPieces(run.pieces);
    } else if (stored !== null && typeof stored === 'object') {
        // Kept by an older page in the round's part: the run's own taps lie from its start on; pieces can not be told
        // apart from an earlier run's - left out
        progress.finishes = readFinishes(stored.finishes, runKey);
    }

    return progress;
}

/**
 * Keeps the progress: the set-up with the round, the taps and pieces with the run `runKey` (none without a run).
 */
export function saveProgress(storage, participantId, roundId, progress, runKey = null) {
    if (!roundId) {
        return false;
    }

    const { finishes, detections, pieces, ...setUp } = progress;
    const savedSetUp = write(storage, `${PROGRESS_PREFIX}${participantId}:${roundId}`, setUp);

    if (!Number.isFinite(runKey)) {
        return savedSetUp;
    }

    // A page without detection keeps the run as it always did
    const run = Array.isArray(detections) && detections.length > 0 ? { finishes, detections, pieces } : { finishes, pieces };

    return write(storage, `${PROGRESS_PREFIX}${participantId}:${roundId}${RUN_INFIX}${runKey}`, run) && savedSetUp;
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
