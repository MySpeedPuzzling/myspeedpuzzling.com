/**
 * The participant page's finish detection at work (docs/features/online-competitions/README.md §5.7; implementation-plan
 * WP 2.2 - 2.4) - the part of assets/controllers/compete_controller.js only a round with detection needs, so it lives
 * in a chunk of its own; the detector worker (detector.worker.js) is yet another chunk, and MediaPipe + its models come
 * from the pinned URLs of the page's config (OnlineDetectorAssets) - loaded when this starts, during the checklist.
 *
 * - **One worker for the page session**, never made twice (Safari runs out of memory, MediaPipe #5036): `init` with both
 *   models (`any` - the set-up test tries both views), then `configure` to the device's view for the round. An init
 *   that failed may be tried again in the same worker; a worker that crashed is not replaced - button-only.
 * - **Frames** from the detector feed riding on the page's frame grabber (detector_feed.js: the same <video>, the same
 *   track - the ring buffer, the photos and the live video are never touched), paced by detector_pacing.js (one in
 *   flight, 4-5 fps, backing off by the inference time), only while compete_detection.js detectorRun() says so.
 * - **The set-up test** on the checklist (compete_detection.js SetUpTest), started by itself once the detector is ready
 *   (unless this device passed one before), again by "Test again"; its result and view are kept on the device.
 * - **The round**: the finish machine in the round's mode (`on` / `shadow`); a candidate → the `finish.candidate`
 *   event (its moment = the first raised frame's capture time), the ±5 s burst and the sharpest of a few full-resolution
 *   stills taken while the hands are up as the `candidate` photo - exactly the evidence of a Finished tap; in `on` mode
 *   also the green flash, the chime and "Finish recorded at 47:12". `shadow` shows nothing.
 * - plan(input) before the screen is drawn, tick() after it (the detection parts, through the controller's painters);
 *   telemetry() for the heartbeat, readyDetails() for device.ready, blocksReady() for "I'm ready".
 *
 * Nothing here may stop the page competing: the controller calls every method inside its own try, and every worker
 * failure ends in button-only.
 */

import { createDetectorPacing } from './detector_pacing.js';
import { canvasSharpness, DetectorFeed } from './detector_feed.js';
import {
    browserSupportsDetector,
    buttonOnly,
    candidateData,
    CANDIDATE_STILLS,
    checklistRow,
    createSetUpTest,
    detectorRun,
    feedback,
    FLASH_MS,
    holdsReady,
    latestDetection,
    loadDetectorChoice,
    MAX_FRAME_ERRORS,
    MODE_OFF,
    MODE_ON,
    readyDetails,
    roundMachine,
    roundSignal,
    saveDetectorChoice,
    sharpestStill,
    stateAfterInit,
    STATE_ERROR,
    STATE_LOADING,
    STATE_OFF,
    STATE_READY,
    STATE_UNSUPPORTED,
    STILL_SPACING_MS,
    SUPPRESSED_SHOWN_MS,
    telemetry,
    TEST_FAILED,
    TEST_PASSED,
    TEST_SKIPPED,
} from './compete_detection.js';
import { clockText } from './compete_flow.js';

// Marks this chunk (the bundle check looks for it)
export const DETECTION_CHUNK_NAME = 'msp-compete-detection-runtime';

// The worker's view while the set-up test watches: both models (detector.worker.js VIEW_ANY)
const VIEW_BOTH = 'any';

export class CompeteDetectionPage {
    /**
     * @param {object} options
     * @param {{tasksVisionUrl: string, wasmBaseUrl: string, poseModelUrl: string, gestureModelUrl: string}} options.assets
     * @param {object} options.grabber          frame_grabber.js FrameGrabber (the candidate's stills)
     * @param {object} [options.feed]           detector_feed.js DetectorFeed on that grabber (the detector's frames)
     * @param {{toServerMs: function(number): number}} options.clock
     * @param {Storage|null} options.storage
     * @param {string} options.participantId
     * @param {Map<string, Element>} options.parts   the [data-compete-detection] elements
     * @param {object} options.paint            the controller's {field, shown, data, t, tc}
     * @param {function(object, number): (object|null)} options.recordCandidate   (data, moment on performance.now()'s
     *                                          clock) → the recorded event, null when nothing is recorded
     * @param {function(string, number): void} options.captureBurst     (eventId, moment ms) - the ±5 s burst
     * @param {function(string, object): void} options.capturePhoto     (eventId, still) - the candidate photo
     * @param {function({eventId: string, momentMs: number}): void} options.onDetection   `on` mode: kept with the run
     * @param {{chime: function(): void}} options.beeps
     * @param {function(): number} options.serverNow
     * @param {function(): void} options.requestRender
     * @param {function(): Worker} [options.createWorker]
     * @param {function(): number} [options.now]
     * @param {boolean} [options.supported]
     * @param {function(function(), number): *} [options.schedule]
     * @param {function(*): void} [options.cancel]
     * @param {function(): boolean} [options.isVisible]   the page is shown - a hidden page never feeds the detector
     */
    constructor({
        assets,
        grabber,
        feed = null,
        clock,
        storage,
        participantId,
        parts,
        paint,
        recordCandidate,
        captureBurst,
        capturePhoto,
        onDetection,
        beeps,
        serverNow,
        requestRender,
        createWorker = () => new Worker(new URL('./detector.worker.js', import.meta.url), { type: 'module' }),
        now = () => globalThis.performance.now(),
        supported = browserSupportsDetector(),
        schedule = (task, ms) => setTimeout(task, ms),
        cancel = (timer) => clearTimeout(timer),
        isVisible = () => globalThis.document?.visibilityState !== 'hidden',
    }) {
        this.assets = assets ?? {};
        this.grabber = grabber;
        this.feed = feed ?? new DetectorFeed({ grabber });
        // How sharp a still is - asked of its full-resolution canvas before it is encoded
        this.judge = canvasSharpness(() => grabber.createCanvas());
        this.clock = clock;
        this.storage = storage;
        this.participantId = participantId;
        this.parts = parts ?? new Map();
        this.paint = paint;
        this.recordCandidate = recordCandidate;
        this.captureBurst = captureBurst;
        this.capturePhoto = capturePhoto;
        this.onDetection = onDetection;
        this.beeps = beeps;
        this.serverNow = serverNow;
        this.requestRender = requestRender;
        this.createWorker = createWorker;
        this.now = now;
        this.supported = supported;
        this.schedule = schedule;
        this.cancel = cancel;
        this.isVisible = isVisible;

        this.state = STATE_OFF;
        this.worker = null;
        this.workerBroken = false;
        this.loadStartedAt = null;
        this.loadMs = null;
        this.workerView = null;
        // A `configure` sent and not answered yet (its view)
        this.configuring = null;
        this.choice = loadDetectorChoice(storage, participantId);
        this.setUp = null;
        // The set-up test starts by itself once per page - a participant who skipped or failed it starts it again
        this.autoTested = false;
        this.pacing = createDetectorPacing();
        this.frameId = 0;
        this.frameErrors = 0;
        this.run = 'off';
        this.mode = MODE_OFF;
        this.machine = null;
        this.machineKey = null;
        // The candidates' photo sessions by the machine's candidate id
        this.sessions = new Map();
        this.flashUntilMs = null;
        this.suppressedUntilMs = null;
        this.input = null;
        this.closed = false;
        this.counts = { candidates: 0, results: 0 };
    }

    // ---- the controller's calls

    /**
     * Before the screen is drawn: the detector started (once), the set-up test, the round's machine, the frames on or
     * off.
     *
     * @param {object} input   {state, mode, screen, ready, visible, cameraLive, practice, preview, secondScreen, takenOver,
     *                         linkDead, entryConfirmed, runKey, startedAtMs, detections, serverNowMs}
     */
    plan(input) {
        if (this.closed) {
            return;
        }

        this.input = input;
        this.mode = input.mode;
        this.start();

        // The round's machine: a new one for a new run or another mode (a raise in progress is not carried over)
        const key = `${input.mode}|${input.runKey ?? ''}`;

        if (this.machineKey !== key) {
            this.machineKey = key;
            this.machine = roundMachine(input.mode);
            this.sessions.clear();
        }

        if (input.screen === 'checklist') {
            if (this.state === STATE_READY && !this.autoTested && this.setUp === null && this.choice.test === null) {
                this.autoTested = true;
                this.startTest({ asked: false });
            }
        } else if (this.setUp !== null) {
            // "I'm ready" while the test watched: untested, not failed - the device keeps its view
            this.setUp = null;
        }

        if (this.setUp !== null && this.setUp.tick(this.now()) !== 'running') {
            this.testEnded();
        }

        this.run = detectorRun({
            mode: input.mode,
            state: this.state,
            test: this.choice.test,
            testActive: this.setUp !== null,
            screen: input.screen,
            ready: input.ready,
            visible: input.visible,
            cameraLive: input.cameraLive,
            practice: input.practice,
            preview: input.preview,
            secondScreen: input.secondScreen,
            takenOver: input.takenOver,
            linkDead: input.linkDead,
            entryConfirmed: input.entryConfirmed,
        });

        this.configureFor(this.run);

        if (this.run !== 'off' && !this.feed.isFeeding()) {
            this.feed.startFeed({
                // A hidden page draws no frames while its timers still fire (and the animation loop that plans
                // stops) - nothing goes to the detector until it is shown again
                wants: () => this.run !== 'off' && this.state === STATE_READY && this.isVisible()
                    && this.workerView === this.wantedView(this.run) && this.pacing.wants(this.now()),
                onBitmap: (bitmap, perf) => this.send(bitmap, perf),
            });
        } else if (this.run === 'off' && this.feed.isFeeding()) {
            this.feed.stopFeed();
        }
    }

    /**
     * After the frame: the detection parts painted.
     */
    tick() {
        const input = this.input;

        if (input === null || this.closed) {
            return;
        }

        const paint = this.paint;

        if (input.screen === 'checklist') {
            const row = checklistRow({
                state: this.state,
                test: this.choice.test,
                view: this.choice.view,
                setUp: this.setUp,
                loadingForMs: this.state === STATE_LOADING && this.loadStartedAt !== null ? this.now() - this.loadStartedAt : null,
            });
            const element = this.parts.get('check');

            if (element && element.dataset.level !== row.level) {
                element.dataset.level = row.level;
            }

            paint.field('detectionCheck', paint.t(`detection_check_${row.code}`, {
                seconds: this.setUp === null ? 0 : Math.ceil(this.setUp.leftMs(this.now()) / 1000),
            }));
            paint.shown('detectionSkip', row.skip);
            paint.shown('detectionRetry', row.retry);
            paint.shown('detectionTest', row.test);
            paint.field('detectionTestLabel', paint.t(this.choice.test === TEST_PASSED ? 'detection_test_again' : 'detection_test'));
        }

        const shown = feedback({
            mode: input.mode,
            detections: input.detections,
            startedAtMs: input.startedAtMs,
            // Now, not the frame's plan: the flash and the hint end on time
            serverNowMs: this.serverNow(),
            flashUntilMs: this.flashUntilMs,
            suppressedUntilMs: this.suppressedUntilMs,
            buttonOnly: this.isButtonOnly(),
            screen: input.screen,
        });
        const time = shown.elapsedMs === null ? '' : clockText(shown.elapsedMs, { limitMs: input.limitMs ?? null });

        paint.shown('detectionDetected', shown.detected && input.screen === 'running');
        paint.shown('detectionTimeUp', shown.detected && input.screen === 'timeup');
        paint.field('detectionRecorded', time === '' ? '' : paint.t('detection_recorded', { time }));
        paint.shown('detectionHint', shown.hint);
        paint.shown('detectionSuppressed', shown.suppressed);
        paint.shown('detectionButtonOnly', shown.buttonOnly);
        paint.data('detected', shown.detected && input.screen === 'running' ? '1' : '0');
        paint.data('detectionFlash', shown.flash ? '1' : '0');

        if (shown.flash || shown.suppressed) {
            // The flash and the hint end by themselves - drawn again then
            this.requestRender();
        }
    }

    /**
     * "I'm ready" waits while the detector loads (a skip lets it go).
     */
    blocksReady() {
        return holdsReady({ mode: this.mode, state: this.state, test: this.choice.test });
    }

    isButtonOnly() {
        return buttonOnly({ state: this.state, test: this.choice.test });
    }

    /**
     * The latest candidate of the run in `on` mode (server ms) - the after screen shows it as the time.
     */
    detectedMoment() {
        if (this.mode !== MODE_ON || this.input === null) {
            return null;
        }

        return latestDetection(this.input.detections, this.input.startedAtMs)?.momentMs ?? null;
    }

    telemetry() {
        return telemetry({ mode: this.mode, state: this.state, test: this.choice.test, view: this.choice.view, stats: this.pacing.stats() });
    }

    readyDetails() {
        return readyDetails({ state: this.state, test: this.choice.test, view: this.choice.view, loadMs: this.loadMs });
    }

    /**
     * "Test" / "Test again" / "Try the camera check" - a set-up test window from now (`asked`: a tap - the page's own
     * start once the detector is ready is not).
     */
    startTest({ asked = true } = {}) {
        if (this.state !== STATE_READY) {
            return;
        }

        this.setUp = createSetUpTest({ startedAtMs: this.now(), asked });

        if (this.choice.test === TEST_SKIPPED) {
            // Back from button-only: the test decides again
            this.choice = { ...this.choice, test: null };
        }

        this.requestRender();
    }

    /**
     * "Skip - use the Finished button": button-only on this device until a test passes.
     */
    skip() {
        this.setUp = null;
        this.choice = { ...this.choice, test: TEST_SKIPPED, atMs: Date.now() };
        saveDetectorChoice(this.storage, this.participantId, this.choice);
        this.requestRender();
    }

    /**
     * "Try again" after a detector that failed to load: init again in the same worker (never a second worker).
     */
    retry() {
        if (this.state !== STATE_ERROR || this.workerBroken || this.worker === null) {
            return;
        }

        this.init();
        this.requestRender();
    }

    close() {
        this.closed = true;
        this.feed.stopFeed();

        for (const session of this.sessions.values()) {
            this.cancel(session.timer);
        }

        if (this.worker !== null) {
            try {
                this.worker.postMessage({ type: 'dispose' });
                this.worker.terminate();
            } catch (e) {
                // Gone already
            }
        }

        this.worker = null;
    }

    // ---- the worker

    start() {
        if (this.state !== STATE_OFF || this.closed) {
            return;
        }

        if (!this.supported) {
            this.state = STATE_UNSUPPORTED;

            return;
        }

        try {
            this.worker = this.createWorker();
        } catch (e) {
            this.state = STATE_UNSUPPORTED;

            return;
        }

        this.worker.addEventListener('message', (event) => this.received(event.data));
        this.worker.addEventListener('error', () => this.broken());
        this.worker.addEventListener('messageerror', () => this.broken());
        this.init();
    }

    init() {
        this.state = STATE_LOADING;
        this.loadStartedAt = this.now();
        this.workerView = null;
        this.configuring = null;
        this.worker.postMessage({
            type: 'init',
            view: VIEW_BOTH,
            tasksVisionUrl: this.assets.tasksVisionUrl,
            wasmBaseUrl: this.assets.wasmBaseUrl,
            poseModelUrl: this.assets.poseModelUrl,
            gestureModelUrl: this.assets.gestureModelUrl,
        });
    }

    /**
     * The worker itself failed (its script did not load, it crashed): button-only - never a second worker.
     */
    broken() {
        this.workerBroken = true;
        this.state = STATE_ERROR;
        this.setUp = null;
        this.feed.stopFeed();
        this.requestRender();
    }

    received(message) {
        if (this.closed || message === null || typeof message !== 'object') {
            return;
        }

        switch (message.type) {
            case 'ready':
                this.state = STATE_READY;
                this.loadMs = Number.isFinite(message.loadMs) ? message.loadMs : Math.round(this.now() - (this.loadStartedAt ?? this.now()));
                this.workerView = message.view ?? VIEW_BOTH;
                this.requestRender();
                break;
            case 'configured':
                this.workerView = message.view ?? this.workerView;
                this.configuring = null;
                break;
            case 'result':
                this.result(message);
                break;
            case 'skipped':
                this.pacing.failed(this.now(), message.frameId ?? null);
                break;
            case 'error':
                this.failed(message);
                break;
            default:
                break;
        }
    }

    failed(message) {
        if (message.phase === 'init') {
            this.state = stateAfterInit(message);
            this.loadMs = null;
            this.requestRender();

            return;
        }

        if (message.phase === 'frame') {
            this.pacing.failed(this.now(), message.frameId ?? null);
            this.frameErrors += 1;

            if (this.frameErrors >= MAX_FRAME_ERRORS) {
                this.state = STATE_ERROR;
                this.setUp = null;
                this.feed.stopFeed();
                this.requestRender();
            }

            return;
        }

        if (message.phase === 'configure') {
            // Asked again with the next frame drawn
            this.configuring = null;

            return;
        }

        if (message.phase === 'worker') {
            this.broken();
        }
    }

    /**
     * The view the worker must run for where the frames go: both models for the set-up test, the device's own for the
     * round.
     */
    wantedView(run) {
        return run === 'setup' ? VIEW_BOTH : this.choice.view;
    }

    configureFor(run) {
        if (run === 'off' || this.state !== STATE_READY || this.worker === null) {
            return;
        }

        const view = this.wantedView(run);

        // Frames wait until the worker said so (the feed's wants() compares the view)
        if (this.workerView !== view && this.configuring !== view) {
            this.configuring = view;
            this.worker.postMessage({ type: 'configure', view });
        }
    }

    send(bitmap, perf) {
        if (this.closed || this.worker === null || this.state !== STATE_READY || this.run === 'off') {
            try {
                bitmap.close();
            } catch (e) {
                // Closed
            }

            return;
        }

        const frameId = ++this.frameId;
        this.pacing.sent(this.now(), frameId);

        try {
            this.worker.postMessage({ type: 'frame', bitmap, captureTimeMs: perf, frameId }, [bitmap]);
        } catch (e) {
            this.pacing.failed(this.now(), frameId);
        }
    }

    result(message) {
        this.pacing.answered(message.inferenceMs, message.frameId ?? null);
        this.frameErrors = 0;
        this.counts.results += 1;

        if (this.run === 'setup' && this.setUp !== null) {
            const status = this.setUp.push(message);

            if (status !== 'running') {
                this.testEnded();
            }

            this.requestRender();

            return;
        }

        if (this.run === 'round' && this.machine !== null) {
            for (const event of this.machine.push(roundSignal(message, this.choice.view))) {
                this.roundEvent(event);
            }
        }
    }

    testEnded() {
        const setUp = this.setUp;
        this.setUp = null;

        if (setUp === null) {
            return;
        }

        if (setUp.status === 'expired') {
            // Nobody raised a hand while the page's own test watched: untested, nothing kept
            this.requestRender();

            return;
        }

        this.choice = setUp.status === 'passed'
            ? { view: setUp.view, test: TEST_PASSED, atMs: Date.now() }
            : { ...this.choice, test: TEST_FAILED, atMs: Date.now() };
        saveDetectorChoice(this.storage, this.participantId, this.choice);

        if (setUp.status === 'passed') {
            this.beeps?.chime?.();
        }

        this.requestRender();
    }

    // ---- the round

    roundEvent(event) {
        switch (event.type) {
            case 'candidate':
                this.candidate(event);
                break;
            case 'raise_end':
                this.raiseEnded(event);
                break;
            case 'raise_suppressed':
                if (this.mode === MODE_ON) {
                    this.suppressedUntilMs = this.serverNow() + SUPPRESSED_SHOWN_MS;
                    this.requestRender();
                }

                break;
            default:
                break;
        }
    }

    candidate(candidate) {
        const data = candidateData(candidate, {
            toServerMs: (perf) => this.clock.toServerMs(perf),
            view: this.choice.view,
            mode: this.mode,
            stats: this.pacing.stats(),
        });
        const event = this.recordCandidate(data, candidate.atMs);

        if (event === null || event === undefined) {
            return;
        }

        this.counts.candidates += 1;
        const momentMs = event.clientMs;
        this.captureBurst(event.id, momentMs);
        this.takeStills(candidate.id, event.id);

        if (this.mode === MODE_ON) {
            this.onDetection({ eventId: event.id, momentMs });
            this.flashUntilMs = this.serverNow() + FLASH_MS;
            this.beeps?.chime?.();
            this.requestRender();
        }
    }

    /**
     * The candidate's photo (README §5.7): up to CANDIDATE_STILLS full-resolution stills STILL_SPACING_MS apart while
     * the hands stay up; the sharpest of those taken before they went down becomes the `candidate` photo.
     */
    takeStills(candidateId, eventId) {
        const session = { eventId, stills: [], taken: 0, pending: 0, ended: false, untilMs: null, done: false, timer: null };
        this.sessions.set(candidateId, session);

        const take = () => {
            session.timer = null;

            if (session.done || (session.ended && session.taken > 0)) {
                this.finishStills(session);

                return;
            }

            session.taken += 1;
            session.pending += 1;
            Promise.resolve()
                .then(() => this.grabber.still({ judge: this.judge }))
                .then((shot) => {
                    if (shot) {
                        session.stills.push(shot);
                    }
                })
                .catch(() => {})
                .finally(() => {
                    session.pending -= 1;
                    this.finishStills(session);
                });

            if (session.taken < CANDIDATE_STILLS && !session.ended) {
                session.timer = this.schedule(take, STILL_SPACING_MS);
            }
        };

        take();
    }

    raiseEnded(event) {
        const session = this.sessions.get(event.candidateId);

        if (!session) {
            return;
        }

        session.ended = true;
        session.untilMs = Math.round(this.clock.toServerMs(event.windowToMs));

        if (session.timer !== null) {
            this.cancel(session.timer);
            session.timer = null;
        }

        this.finishStills(session);
    }

    finishStills(session) {
        if (session.done || session.pending > 0 || session.timer !== null) {
            return;
        }

        if (session.taken < CANDIDATE_STILLS && !session.ended) {
            return;
        }

        session.done = true;
        const best = sharpestStill(session.stills, session.untilMs);

        if (best !== null) {
            this.capturePhoto(session.eventId, best);
        }
    }
}

export { STATE_ERROR, STATE_LOADING, STATE_OFF, STATE_READY, STATE_UNSUPPORTED, TEST_FAILED, TEST_PASSED, TEST_SKIPPED };
