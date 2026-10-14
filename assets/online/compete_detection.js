/**
 * The participant page's finish detection - its rules (docs/features/online-competitions/README.md §5.5, §5.7;
 * implementation-plan.md WP 2.2 - 2.4). Pure: what the page knows in, a decision out - pinned by
 * tests/online-detection-harness.mjs. compete_detection_page.js runs them (the worker, the frames, the evidence).
 *
 * - **When the detector looks** (detectorRun()): the set-up test on the checklist, and the round from GO until this
 *   participant taps Finished (stream P2F: the `finished` screen looks at nothing - a raise after the participant's own
 *   tap is never a candidate; "I tapped by mistake" / "Keep puzzling" brings the running screen and the detector back,
 *   its machine unarmed), their result is confirmed or the time is up - never on a hidden page (the finish machine
 *   treats the gap per its maxGapMs and unarms), never without the camera, never in a preview, on a second screen or in
 *   a practice; and never on a device that competes button-only.
 * - **Button-only** (buttonOnly()): no detector in this browser (no Worker / createImageBitmap / WebGL2 - `unsupported`),
 *   a detector that failed (`error`), a set-up test that failed or was skipped on this device - and (stream P2F) a
 *   detector that stopped during the round (`stopped`: `slow` - its frames came too far apart to ever hold a raise,
 *   `stalled` - no answer while the camera gave frames, `error` - frame errors or a crash). Never a blocker: the
 *   Finished button is always there; the page says so ("Tap Finished when your puzzle is complete.") and reports it
 *   (heartbeat → the control room's "Button only", device.ready, `detector.changed` - detectorUsage()).
 * - **The detector's watch** (DetectorWatch, stream P2F): `slow` = the intervals between its answers stayed above the
 *   finish machine's maxGapMs for SLOW_FOR_MS (at least SLOW_MIN_INTERVALS of them - one freeze of the camera is a
 *   gap, not a slow phone); `stalled` = STALL_FOR_MS of fresh camera frames offered without one answer; `watching` =
 *   an answer (or the start of looking) within WATCH_STALE_MS - the hint to raise both hands is shown only then, else
 *   the button-only line (a frozen camera, a camera restarting, a detector still loading: nothing looks, so the page
 *   never asks for a raise it can not see).
 * - **The set-up test** (createSetUpTest()): "Raise both hands for a second" - both views watch the same frames (the
 *   worker runs both models while the set-up is open), each with a finish machine of its own; the first view whose
 *   machine makes a candidate is the camera placement (README §5.7: angled = pose, topDown = gestures; both on one
 *   frame = angled, the framing organisers ask for). Its machines arm like the round's (finish_machine.js): the test
 *   proves the whole cycle the round needs - hands seen down, then raised and held - and a detector that reads
 *   "raised" all the time (a poster, an odd pose) fails it instead of passing at once and never arming in the round.
 *   No candidate within SETUP_TEST_MS = failed → button-only on this
 *   device until a test passes - except a test the page started by itself (once the detector is ready) in which no
 *   hands were seen up at all: nobody tried, it `expired` - untested, the device keeps its view. The choice is kept on
 *   the device (loadDetectorChoice() / saveDetectorChoice()), so a reload competes with it; a device that never tested
 *   competes `angled` (DEFAULT_VIEW) - also a `shadow` participant who said "I'm ready" while the detector still
 *   loaded: it starts looking with that view once loaded.
 * - **Candidates** (candidateData()): the finish machine's candidate as the `finish.candidate` event's data - moments on
 *   the synced clock (the event's clientMs is the first raised frame's), the window for the photo, the scores, the view,
 *   the mode, the machine's version, the detector's cadence then.
 * - **The photo** (sharpestStill()): the sharpest of the stills taken while the hands were up (README §5.7 - hands away
 *   from the puzzle).
 * - **What the participant sees** (feedback()): `on` - "Finish recorded at 47:12 - if a piece is missing, keep going and
 *   raise your hands again", a green flash and a chime, the running clock small; `shadow` - nothing at all.
 * - **"I'm ready"** (holdsReady()) waits for a loading detector in `on` mode only - `shadow` never holds anybody up (the
 *   participant does not use it; it loads in the background and starts looking once loaded).
 * - **The set-up test on the waiting screen** (waitingOffer(), stream RP): a page loaded again after "I'm ready" (a
 *   reload for detection - stream SP's banner) lands on the waiting screen untested; while the round has not started -
 *   and the test still fits before its countdown - the waiting screen offers the same test ("Test", never by itself,
 *   never blocking), for a device that never passed one (untested or failed - a skip was the participant's choice).
 * - **The checklist row** (checklistRow()), **the heartbeat** (telemetry()), **device.ready** (readyDetails()) and
 *   **`detector.changed`** (detectorUsage(): whether the detector watches this device's round, else why not - stored
 *   whenever that changes after "I'm ready", so the accuracy report never counts a finish on a phone whose detector did
 *   not run as a missed raise).
 */

import { createFinishMachine, FINISH_MACHINE_VERSION, MAX_GAP_MS, MODE_ON } from './finish_machine.js';
import { MODE_OFF, MODE_SHADOW } from './compete_detection_basics.js';

export { MODE_OFF, MODE_ON, MODE_SHADOW };

export const VIEW_ANGLED = 'angled';
export const VIEW_TOP_DOWN = 'topDown';
export const VIEWS = [VIEW_ANGLED, VIEW_TOP_DOWN];
// A device that never passed a test competes with the framing organisers ask for (README §5.5 "Set-up rules")
export const DEFAULT_VIEW = VIEW_ANGLED;

// What the heartbeat and device.ready report (src/Value/OnlineHeartbeat.php DETECTOR_STATES)
export const STATE_OFF = 'off';
export const STATE_LOADING = 'loading';
export const STATE_READY = 'ready';
export const STATE_UNSUPPORTED = 'unsupported';
export const STATE_ERROR = 'error';
export const DETECTOR_STATES = [STATE_OFF, STATE_LOADING, STATE_READY, STATE_UNSUPPORTED, STATE_ERROR];

export const TEST_PASSED = 'passed';
export const TEST_FAILED = 'failed';
export const TEST_SKIPPED = 'skipped';

// Why a detector stopped during the round (the page's `stopped`)
export const STOP_SLOW = 'slow';
export const STOP_STALLED = 'stalled';
export const STOP_ERROR = 'error';
// Why a detector does not watch this device's round (detector.changed `reason` - OnlineEventInput DETECTOR_REASONS)
export const DETECTOR_REASONS = ['off', 'loading', 'unsupported', 'error', STOP_STALLED, STOP_SLOW, TEST_FAILED, TEST_SKIPPED];

// How long the set-up test watches for the raise
export const SETUP_TEST_MS = 20000;
// The checklist offers "Skip - use the Finished button" once the detector has been loading this long
export const LOAD_SKIP_AFTER_MS = 15000;
// Frames in a row the worker could not analyse before the detector counts as broken (button-only)
export const MAX_FRAME_ERRORS = 8;
// The candidate's photo: stills taken this far apart while the hands are up, at most this many
export const CANDIDATE_STILLS = 3;
export const STILL_SPACING_MS = 300;
// A detector whose answers came more than the machine's maxGapMs apart for this long - at least this many intervals in
// a row - can never hold a raise: button-only (`slow`). One long interval is a frozen camera or a hidden page (a gap)
export const SLOW_FOR_MS = 10000;
export const SLOW_MIN_INTERVALS = 3;
// Fresh camera frames offered this long - at least this many - without one answer: the detector hangs (`stalled`)
export const STALL_FOR_MS = 10000;
export const STALL_MIN_FRAMES = 5;
// The detector watches while an answer (or the start of looking) is this recent - the hint to raise both hands only then
export const WATCH_STALE_MS = 3000;
// The green flash after a candidate
export const FLASH_MS = 1500;
// "Raise again in a moment" stays this long after a raise the cooldown held back
export const SUPPRESSED_SHOWN_MS = 4000;

export const STORAGE_DETECTOR = 'msp-compete-detector:';

/**
 * Can this browser run the detector at all? A module Web Worker, createImageBitmap (frames go to the worker as
 * transferable bitmaps) and OffscreenCanvas (MediaPipe needs WebGL2 in the worker even on the CPU delegate - the worker
 * itself checks WebGL2 at init: `NotSupportedError`). Without them: button-only, said at once.
 */
export function browserSupportsDetector(scope = globalThis) {
    return typeof scope?.Worker === 'function'
        && typeof scope?.createImageBitmap === 'function'
        && typeof scope?.OffscreenCanvas === 'function';
}

/**
 * The detector's state from the worker's answer to `init`: `ready`, or why not - NotSupportedError (no WebGL2 in the
 * worker) = `unsupported`, anything else = `error`.
 */
export function stateAfterInit(message) {
    if (message?.type === 'ready') {
        return STATE_READY;
    }

    return message?.name === 'NotSupportedError' ? STATE_UNSUPPORTED : STATE_ERROR;
}

/**
 * The device's own detection choice: the camera placement the set-up test chose and how the test ended.
 *
 * @returns {{view: string, test: null|'passed'|'failed'|'skipped', atMs: number|null}}
 */
export function loadDetectorChoice(storage, participantId) {
    const fallback = { view: DEFAULT_VIEW, test: null, atMs: null };

    try {
        const raw = storage?.getItem(STORAGE_DETECTOR + participantId);
        const stored = raw === null || raw === undefined ? null : JSON.parse(raw);

        if (stored === null || typeof stored !== 'object') {
            return fallback;
        }

        return {
            view: VIEWS.includes(stored.view) ? stored.view : DEFAULT_VIEW,
            test: [TEST_PASSED, TEST_FAILED, TEST_SKIPPED].includes(stored.test) ? stored.test : null,
            atMs: Number.isFinite(stored.atMs) ? stored.atMs : null,
        };
    } catch (e) {
        return fallback;
    }
}

export function saveDetectorChoice(storage, participantId, choice) {
    try {
        storage?.setItem(STORAGE_DETECTOR + participantId, JSON.stringify({
            view: VIEWS.includes(choice?.view) ? choice.view : DEFAULT_VIEW,
            test: choice?.test ?? null,
            atMs: Number.isFinite(choice?.atMs) ? choice.atMs : null,
        }));

        return true;
    } catch (e) {
        return false;
    }
}

/**
 * This device competes with the Finished button only: the detector can not run here, the participant's set-up test
 * failed / was skipped (until a test passes), or it stopped during the round (`stopped`: slow, stalled, error).
 *
 * @param {{state: string, test: null|string, stopped?: null|string}} input
 */
export function buttonOnly({ state, test, stopped = null }) {
    return state === STATE_UNSUPPORTED || state === STATE_ERROR || test === TEST_FAILED || test === TEST_SKIPPED
        || (stopped !== null && stopped !== undefined);
}

/**
 * Does the detector watch this device's round - and if not, why (detector.changed, device.ready's counterpart)?
 *
 * @returns {{state: string, running: boolean, reason: null|string, view: null|string}}
 */
export function detectorUsage({ mode, state, test, stopped = null, view }) {
    const reported = DETECTOR_STATES.includes(state) ? state : STATE_OFF;
    const off = (reason) => ({ state: reported, running: false, reason, view: null });

    if (mode !== MODE_ON && mode !== MODE_SHADOW) {
        return off(STATE_OFF);
    }

    if (stopped !== null && stopped !== undefined) {
        return off(DETECTOR_REASONS.includes(stopped) ? stopped : STOP_ERROR);
    }

    if (reported !== STATE_READY) {
        return off(reported);
    }

    if (test === TEST_FAILED || test === TEST_SKIPPED) {
        return off(test);
    }

    return { state: reported, running: true, reason: null, view: VIEWS.includes(view) ? view : DEFAULT_VIEW };
}

/**
 * Where the detector's frames go now: `setup` (the set-up test), `round` (the finish machine of the round), `off`.
 *
 * @param {object} input
 * @param {string} input.mode            the round's detection mode
 * @param {string} input.state           the detector's state
 * @param {null|string} input.test       the device's test result
 * @param {boolean} input.testActive     a set-up test is watching
 * @param {string} input.screen          compete_flow.js screenFor()
 * @param {boolean} input.ready          "I'm ready" was tapped on this device
 * @param {boolean} input.visible        the page is shown
 * @param {boolean} input.cameraLive     the camera delivers frames
 * @param {boolean} [input.practice]
 * @param {boolean} [input.preview]
 * @param {boolean} [input.secondScreen]
 * @param {boolean} [input.takenOver]
 * @param {boolean} [input.linkDead]
 * @param {boolean} [input.entryConfirmed]   the review confirmed this participant's result
 * @param {null|string} [input.stopped]      the detector stopped during the round (button-only)
 * @returns {'off'|'setup'|'round'}
 */
export function detectorRun({
    mode,
    state,
    test,
    testActive,
    screen,
    ready,
    visible,
    cameraLive,
    practice = false,
    preview = false,
    secondScreen = false,
    takenOver = false,
    linkDead = false,
    entryConfirmed = false,
    stopped = null,
}) {
    if (mode === MODE_OFF || state !== STATE_READY || !visible || !cameraLive || practice || preview || secondScreen || takenOver || linkDead) {
        return 'off';
    }

    // The set-up test: on the checklist, or asked for on the waiting screen (waitingOffer())
    if (testActive) {
        return screen === 'checklist' || screen === 'waiting' ? 'setup' : 'off';
    }

    if (buttonOnly({ state, test, stopped }) || !ready || entryConfirmed) {
        return 'off';
    }

    // Not on `finished`: once the participant tapped Finished nothing is detected - undoing the tap brings it back
    return screen === 'running' ? 'round' : 'off';
}

/**
 * The detector's watch during the round (stream P2F): is it too slow to ever hold a raise, does it hang, is it
 * watching right now. All times on performance.now()'s clock; pure - pinned by tests/online-detection-harness.mjs.
 */
export function createDetectorWatch(options = {}) {
    return new DetectorWatch(options);
}

export class DetectorWatch {
    constructor({
        maxGapMs = MAX_GAP_MS,
        slowForMs = SLOW_FOR_MS,
        slowMinIntervals = SLOW_MIN_INTERVALS,
        stallForMs = STALL_FOR_MS,
        stallMinFrames = STALL_MIN_FRAMES,
        staleMs = WATCH_STALE_MS,
    } = {}) {
        this.maxGapMs = maxGapMs;
        this.slowForMs = slowForMs;
        this.slowMinIntervals = slowMinIntervals;
        this.stallForMs = stallForMs;
        this.stallMinFrames = stallMinFrames;
        this.staleMs = staleMs;
        this.startedAtMs = null;
        this.start(null);
    }

    /**
     * The round's detector starts looking (again - after a pause, a hidden page) at `nowMs`: nothing before counts.
     */
    start(nowMs) {
        this.startedAtMs = Number.isFinite(nowMs) ? nowMs : null;
        this.lastAnswerAtMs = null;
        this.lastCaptureMs = null;
        this.slowRun = null;
        this.lastFrameAtMs = null;
        this.stallFromMs = null;
        this.framesSinceAnswer = 0;
    }

    /**
     * A fresh camera frame was there for the detector (asked of the pacing - sent or not).
     */
    frame(nowMs) {
        if (this.lastFrameAtMs !== null && nowMs - this.lastFrameAtMs > this.staleMs) {
            // Frames stopped for a while (a hidden page, a frozen camera): the stall counts from now on
            this.stallFromMs = null;
            this.framesSinceAnswer = 0;
        }

        this.lastFrameAtMs = nowMs;

        if (this.stallFromMs === null) {
            this.stallFromMs = nowMs;
        }

        this.framesSinceAnswer += 1;
    }

    /**
     * The detector answered a frame captured at `captureMs`. → `slow` once its answers stayed too far apart.
     *
     * @returns {null|'slow'}
     */
    answer(captureMs, nowMs) {
        this.lastAnswerAtMs = nowMs;
        this.stallFromMs = null;
        this.framesSinceAnswer = 0;

        if (!Number.isFinite(captureMs)) {
            return null;
        }

        let slow = false;

        if (this.lastCaptureMs !== null && captureMs > this.lastCaptureMs) {
            if (captureMs - this.lastCaptureMs > this.maxGapMs) {
                this.slowRun ??= { fromMs: this.lastCaptureMs, intervals: 0 };
                this.slowRun.intervals += 1;
                slow = captureMs - this.slowRun.fromMs >= this.slowForMs && this.slowRun.intervals >= this.slowMinIntervals;
            } else {
                this.slowRun = null;
            }
        }

        this.lastCaptureMs = Math.max(captureMs, this.lastCaptureMs ?? captureMs);

        return slow ? STOP_SLOW : null;
    }

    /**
     * → `stalled` when fresh frames were offered for stallForMs (at least stallMinFrames) and no answer came.
     *
     * @returns {null|'stalled'}
     */
    check(nowMs) {
        return this.stallFromMs !== null && nowMs - this.stallFromMs >= this.stallForMs && this.framesSinceAnswer >= this.stallMinFrames
            ? STOP_STALLED
            : null;
    }

    /**
     * An answer - or the start of looking - within staleMs.
     */
    watching(nowMs) {
        const since = this.lastAnswerAtMs ?? this.startedAtMs;

        return since !== null && Number.isFinite(nowMs) && nowMs - since < this.staleMs;
    }
}

/**
 * The finish machine of the round in the round's mode (finish_machine.js - its defaults are the binding rules).
 */
export function roundMachine(mode) {
    return createFinishMachine({ mode: mode === MODE_ON || mode === MODE_SHADOW ? mode : MODE_OFF });
}

/**
 * The set-up test: both views' machines on the same frames; the first candidate decides the view.
 *
 * @param {{startedAtMs: number, windowMs?: number, asked?: boolean}} options   performance.now() clock; `asked`: the
 *        participant tapped "Test" (false: the page started it by itself)
 */
export function createSetUpTest({ startedAtMs, windowMs = SETUP_TEST_MS, asked = true }) {
    return new SetUpTest({ startedAtMs, windowMs, asked });
}

export class SetUpTest {
    constructor({ startedAtMs, windowMs, asked = true }) {
        this.startedAtMs = startedAtMs;
        this.windowMs = windowMs;
        this.asked = asked;
        this.machines = {
            [VIEW_ANGLED]: createFinishMachine({ mode: MODE_ON }),
            [VIEW_TOP_DOWN]: createFinishMachine({ mode: MODE_ON }),
        };
        this.status = 'running';
        this.view = null;
        this.frames = 0;
        // How close each view came: its best score of a raised frame
        this.best = { [VIEW_ANGLED]: 0, [VIEW_TOP_DOWN]: 0 };
    }

    /**
     * One detector result (detector.worker.js `result`): its signal carries both views (`angled`, `topDown`) while the
     * worker runs both models; a result with one view's signal only counts for that view.
     *
     * @returns {'running'|'passed'|'failed'|'expired'}
     */
    push(result) {
        if (this.status !== 'running') {
            return this.status;
        }

        const at = result?.captureTimeMs;

        if (!Number.isFinite(at)) {
            return this.status;
        }

        if (at - this.startedAtMs > this.windowMs) {
            this.status = this.outcomeWithoutCandidate();

            return this.status;
        }

        this.frames += 1;
        const signals = viewSignals(result?.signal);
        let winner = null;

        for (const view of VIEWS) {
            const signal = signals[view];

            if (!signal) {
                continue;
            }

            if (signal.raised === true && Number.isFinite(signal.score)) {
                this.best[view] = Math.max(this.best[view], signal.score);
            }

            const events = this.machines[view].push({ captureMs: at, raised: signal.raised === true, score: signal.score });

            if (winner === null && events.some((event) => event.type === 'candidate')) {
                winner = view;
            }
        }

        if (winner !== null) {
            this.status = 'passed';
            this.view = winner;
        }

        return this.status;
    }

    /**
     * The window ran out with no frame to say so (no results at all - a stalled worker).
     */
    tick(nowMs) {
        if (this.status === 'running' && Number.isFinite(nowMs) && nowMs - this.startedAtMs > this.windowMs) {
            this.status = this.outcomeWithoutCandidate();
        }

        return this.status;
    }

    /**
     * No candidate in the window: failed - unless the page started the test by itself and no view ever saw raised
     * hands (nobody tried): expired, the device stays untested.
     */
    outcomeWithoutCandidate() {
        const seen = VIEWS.some((view) => this.best[view] > 0);

        return !this.asked && !seen ? 'expired' : 'failed';
    }

    leftMs(nowMs) {
        return Math.max(0, this.startedAtMs + this.windowMs - nowMs);
    }

    /**
     * Where a view's machine stands - the row shows "Hold them up…" while one holds.
     */
    holding() {
        return VIEWS.some((view) => this.machines[view].state().phase !== 'idle');
    }
}

/**
 * Each view's signal of a detector result: the worker's `signal` holds `angled` / `topDown` (hands_up_signal.js
 * frameSignal()) - or, for one view, only its own fields.
 */
export function viewSignals(signal) {
    if (signal === null || typeof signal !== 'object') {
        return {};
    }

    const result = {};

    if (signal.angled && typeof signal.angled === 'object') {
        result[VIEW_ANGLED] = signal.angled;
    }

    if (signal.topDown && typeof signal.topDown === 'object') {
        result[VIEW_TOP_DOWN] = signal.topDown;
    }

    if (Object.keys(result).length === 0 && VIEWS.includes(signal.view)) {
        result[signal.view] = signal;
    }

    return result;
}

/**
 * The round machine's signal from a detector result for the device's view.
 */
export function roundSignal(result, view) {
    const signal = viewSignals(result?.signal)[view] ?? (result?.signal?.view === view ? result.signal : null);

    return {
        captureMs: result?.captureTimeMs,
        raised: signal?.raised === true,
        score: Number.isFinite(signal?.score) ? signal.score : 0,
    };
}

/**
 * A candidate as the `finish.candidate` event's data (src/Value/OnlineEventInput.php isCandidate()). The machine's
 * moments are on performance.now()'s clock (the frames' capture times); the event's are the synced server's.
 *
 * @param {object} candidate          finish_machine.js `candidate`
 * @param {object} context
 * @param {function(number): number} context.toServerMs
 * @param {string} context.view
 * @param {string} context.mode
 * @param {{fps?: number|null, inferenceMs?: number|null}} [context.stats]   detector_pacing.js stats()
 */
export function candidateData(candidate, { toServerMs, view, mode, stats = {} }) {
    const server = (perfMs) => Math.round(toServerMs(perfMs));

    return {
        candidateId: candidate.id,
        detectedAtMs: server(candidate.detectedAtMs),
        windowFromMs: server(candidate.windowFromMs),
        windowToMs: server(candidate.windowToMs),
        heldMs: Math.round(candidate.heldMs),
        score: candidate.score,
        minScore: candidate.minScore,
        peakScore: candidate.peakScore,
        upFrames: candidate.upFrames,
        droppedFrames: candidate.droppedFrames,
        straddled: candidate.straddled === true,
        view: VIEWS.includes(view) ? view : DEFAULT_VIEW,
        mode,
        machineVersion: candidate.version ?? FINISH_MACHINE_VERSION,
        fps: Number.isFinite(stats?.fps) ? stats.fps : null,
        inferenceMs: Number.isFinite(stats?.inferenceMs) ? stats.inferenceMs : null,
    };
}

/**
 * The candidate's photo: the sharpest still captured while the hands were up (up to `untilMs`, the raise's last raised
 * frame on the server clock, when known) - the first still when none says how sharp it is.
 *
 * @param {Array<{blob: Blob, captureMs: number, sharpness?: number|null}>} stills
 * @param {number|null} [untilMs]
 */
export function sharpestStill(stills, untilMs = null) {
    const usable = (Array.isArray(stills) ? stills : []).filter((still) => still && still.blob);

    if (usable.length === 0) {
        return null;
    }

    // Taken after the hands went down: the puzzle under the hands again - kept only when nothing else is there
    const inWindow = Number.isFinite(untilMs) ? usable.filter((still) => still.captureMs <= untilMs + STILL_SPACING_MS) : usable;
    const pool = inWindow.length > 0 ? inWindow : usable.slice(0, 1);

    return pool.reduce((best, still) => (
        Number.isFinite(still.sharpness) && (!Number.isFinite(best.sharpness) || still.sharpness > best.sharpness) ? still : best
    ));
}

/**
 * What the running screen shows about the detection (`on` mode only; `shadow` shows nothing - the cleanest delta data,
 * README §5.7).
 *
 * @param {object} input
 * @param {string} input.mode
 * @param {Array<{momentMs: number}>} input.detections   this run's candidates on this device (server ms)
 * @param {number|null} input.startedAtMs
 * @param {number} input.serverNowMs
 * @param {number|null} [input.flashUntilMs]
 * @param {number|null} [input.suppressedUntilMs]
 * @param {boolean} [input.buttonOnly]
 * @param {boolean} [input.watching]   the detector looks right now (DetectorWatch) - else nothing can see a raise
 * @param {string} input.screen
 */
export function feedback({ mode, detections = [], startedAtMs, serverNowMs, flashUntilMs = null, suppressedUntilMs = null, buttonOnly: onlyButton = false, watching = true, screen }) {
    const none = { detected: false, momentMs: null, elapsedMs: null, flash: false, suppressed: false, hint: false, buttonOnly: false };

    if (mode !== MODE_ON || !['running', 'finished', 'timeup', 'after'].includes(screen)) {
        return none;
    }

    const latest = latestDetection(detections, startedAtMs);
    const elapsedMs = latest !== null && Number.isFinite(startedAtMs) ? latest.momentMs - startedAtMs : null;
    // Nothing looks (button-only, or not watching right now): the page never asks for a raise it can not see - and a
    // finish already recorded stays shown
    const looking = !onlyButton && watching;

    return {
        detected: latest !== null,
        momentMs: latest?.momentMs ?? null,
        elapsedMs,
        flash: Number.isFinite(flashUntilMs) && serverNowMs < flashUntilMs && screen === 'running',
        suppressed: looking && Number.isFinite(suppressedUntilMs) && serverNowMs < suppressedUntilMs && screen === 'running',
        hint: looking && latest === null && screen === 'running',
        buttonOnly: !looking && screen === 'running',
    };
}

/**
 * The latest candidate of the run (none before its start).
 */
export function latestDetection(detections, startedAtMs = null) {
    const list = (Array.isArray(detections) ? detections : [])
        .filter((item) => item && Number.isFinite(item.momentMs) && (!Number.isFinite(startedAtMs) || item.momentMs >= startedAtMs));

    return list.length === 0 ? null : list.reduce((latest, item) => (item.momentMs >= latest.momentMs ? item : latest));
}

// Every code the checklist's detection row can have - the page's texts are `detection_check_<code>`
export const CHECK_CODES = ['loading', 'loading_slow', 'testing', 'holding', 'passed_angled', 'passed_topDown', 'untested', 'failed', 'skipped', 'unsupported', 'error'];

/**
 * The checklist's detection row `{level, code, skip, retry, test}` (which buttons it offers).
 *
 * @param {object} input
 * @param {string} input.state
 * @param {null|string} input.test             the device's stored result
 * @param {string} input.view
 * @param {null|SetUpTest} input.setUp         a test watching now
 * @param {number|null} input.loadingForMs     how long the detector has been loading
 * @param {string} [input.mode]                `shadow` never holds "I'm ready": no skip, no "still preparing"
 */
export function checklistRow({ state, test, view, setUp = null, loadingForMs = null, mode = MODE_ON }) {
    if (state === STATE_UNSUPPORTED) {
        return { level: 'warn', code: 'unsupported', skip: false, retry: false, test: false };
    }

    if (state === STATE_ERROR) {
        return { level: 'warn', code: 'error', skip: false, retry: true, test: false };
    }

    if (test === TEST_SKIPPED) {
        return { level: 'warn', code: 'skipped', skip: false, retry: false, test: true };
    }

    if (state !== STATE_READY) {
        const slow = mode === MODE_ON && Number.isFinite(loadingForMs) && loadingForMs >= LOAD_SKIP_AFTER_MS;

        return { level: 'warn', code: slow ? 'loading_slow' : 'loading', skip: slow, retry: false, test: false };
    }

    if (setUp !== null && setUp.status === 'running') {
        return { level: 'info', code: setUp.holding() ? 'holding' : 'testing', skip: false, retry: false, test: false };
    }

    if (test === TEST_PASSED) {
        return { level: 'ok', code: view === VIEW_TOP_DOWN ? 'passed_topDown' : 'passed_angled', skip: false, retry: false, test: true };
    }

    if (test === TEST_FAILED) {
        return { level: 'warn', code: 'failed', skip: false, retry: false, test: true };
    }

    return { level: 'info', code: 'untested', skip: false, retry: false, test: true };
}

// A start this close leaves no room for a set-up test on the waiting screen: the test's window and the countdown
const WAITING_TEST_ROOM_MS = SETUP_TEST_MS + 10000;

/**
 * The set-up test offered on the waiting screen (stream RP, docs/TODO.md): the detection's row - its line and "Test" -
 * or null. Shown while a test runs there (to its end), after one ran there (its result, "Test again"), or for a device
 * that never passed one (untested, failed); "Test" only while the round has not started and the test fits before the
 * countdown. Never while the detector can not run or loads, never for a skip (the participant chose the button).
 *
 * @param {object} input
 * @param {string} input.mode               the round's detection mode
 * @param {string} input.state              the detector's state
 * @param {null|string} input.test          the device's test result
 * @param {string} input.view               the device's camera view
 * @param {object|null} input.setUp         the set-up test watching on this screen
 * @param {boolean} input.testedHere        a test ran on this screen on this page
 * @param {object} input.roundView          round_state.js deriveView()
 * @returns {null|{level: string, code: string, skip: boolean, retry: boolean, test: boolean}}
 */
export function waitingOffer({ mode, state, test, view, setUp = null, testedHere = false, roundView }) {
    const running = setUp !== null && setUp.status === 'running';
    const room = roundView?.screen === 'waiting' || (roundView?.screen === 'scheduled' && roundView.countdownMs > WAITING_TEST_ROOM_MS);

    if (mode === MODE_OFF || state !== STATE_READY || (!running && (!room || (!testedHere && (test === TEST_PASSED || test === TEST_SKIPPED))))) {
        return null;
    }

    const row = checklistRow({ state, test, view, setUp, mode });

    return { level: row.level, code: row.code, skip: false, retry: false, test: row.test && room };
}

/**
 * "I'm ready" waits for the detector (README §5.5 "detector loaded") - in `on` mode, while it loads and the participant
 * has not skipped it. `shadow` never waits (stream P2F: the participant does not use it - it loads in the background
 * and starts looking once loaded). A detector that can not run, failed or was skipped never holds anybody up.
 */
export function holdsReady({ mode, state, test }) {
    return mode === MODE_ON && state === STATE_LOADING && test !== TEST_SKIPPED;
}

/**
 * The heartbeat's `detector` (implementation-plan.md §3.1, additive: `state`, `inferenceMs`, `view`). A device that
 * competes button-only by its own test or a stop during the round - or a round whose detection was switched off -
 * reports `off`: the detector is not used (the control room's "Button only").
 */
export function telemetry({ mode, state, test, view, stats = null, stopped = null }) {
    const used = mode !== MODE_OFF && state === STATE_READY && !buttonOnly({ state, test, stopped });
    const reported = state === STATE_READY && !used ? STATE_OFF : state;

    return {
        ok: used,
        fps: used && Number.isFinite(stats?.fps) ? stats.fps : null,
        mode,
        state: DETECTOR_STATES.includes(reported) ? reported : STATE_OFF,
        inferenceMs: used && Number.isFinite(stats?.inferenceMs) ? stats.inferenceMs : null,
        view: used ? view : null,
    };
}

/**
 * device.ready's `detector`: the state, the view, how the set-up test ended, how long loading took.
 */
export function readyDetails({ state, test, view, loadMs = null }) {
    return {
        state: DETECTOR_STATES.includes(state) ? state : STATE_OFF,
        view: state === STATE_READY ? view : null,
        setupTest: [TEST_PASSED, TEST_FAILED, TEST_SKIPPED].includes(test) ? test : null,
        loadMs: Number.isFinite(loadMs) ? Math.round(loadMs) : null,
    };
}
