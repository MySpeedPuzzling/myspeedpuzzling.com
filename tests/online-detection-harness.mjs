// Runs the participant page's finish detection - assets/online/compete_detection_basics.js (when the chunk loads),
// compete_detection.js (its rules) and compete_detection_page.js (the worker, the frames, the set-up test, the
// candidates and their evidence, what the page shows) - against a fake worker, frame grabber and clock, and prints
// {scenario: "ok" | error} as JSON. tests/OnlineDetectionScriptTest.php asserts every one is "ok".
//
// The decisions these scenarios pin (docs/features/online-competitions/README.md §5.5, §5.7; stream P2I):
// - the chunk loads from the checklist on, only with the page's detection parts, the mode not off, consent given and on
//   a device that competes (no preview / second screen / dead link);
// - one worker per page session: init with both models, never a second worker (a failed init may be tried again in the
//   same one), configure to the device's view for the round; no Worker / createImageBitmap / OffscreenCanvas, no WebGL2
//   in the worker (NotSupportedError), a broken worker or 8 frame errors in a row = button-only, never blocked;
// - the detector looks during the set-up test and from GO (running - not `finished`: stream P2F) until the result is
//   confirmed - never on a hidden page, without the camera, in a practice, a preview, on a second screen; a Finished tap
//   stops it and interrupts the machine (unarmed - undoing the tap brings it back, a raise held through it is never a
//   candidate);
// - the set-up test: both views watch, the first candidate decides the view (both on one frame = angled), 20 s without
//   one = failed → button-only on this device until a test passes; kept on the device;
// - a candidate = the event (its moment the first raised frame's), the ±5 s burst, the sharpest still taken while the
//   hands were up as the candidate photo; `on` shows it (flash, chime, "Finish recorded"), `shadow` records silently;
// - stream P2F: the stills are let go once the photo is chosen (20 candidates hold none), a mode switch cancels every
//   photo timer; `shadow` never holds "I'm ready" and starts looking once loaded; slow / stalled / broken detectors
//   become button-only (said, reported, one `detector.changed`), "Try again" starts ONE new worker; the hint to raise
//   both hands only while the detector watches.

import assert from 'node:assert/strict';
import { detectionLoadWanted, detectionMode, LOAD_RETRY_MS } from '../assets/online/compete_detection_basics.js';
import {
    browserSupportsDetector,
    buttonOnly,
    candidateData,
    CANDIDATE_STILLS,
    CHECK_CODES,
    checklistRow,
    createDetectorWatch,
    createSetUpTest,
    DEFAULT_VIEW,
    DETECTOR_REASONS,
    detectorRun,
    detectorUsage,
    feedback,
    FLASH_MS,
    holdsReady,
    latestDetection,
    LOAD_SKIP_AFTER_MS,
    loadDetectorChoice,
    MAX_FRAME_ERRORS,
    readyDetails,
    roundSignal,
    saveDetectorChoice,
    SETUP_TEST_MS,
    sharpestStill,
    SLOW_FOR_MS,
    stateAfterInit,
    STALL_FOR_MS,
    STILL_SPACING_MS,
    STORAGE_DETECTOR,
    telemetry,
    viewSignals,
    WATCH_STALE_MS,
} from '../assets/online/compete_detection.js';
import { CompeteDetectionPage, DETECTION_CHUNK_NAME } from '../assets/online/compete_detection_page.js';
import { FINISH_MACHINE_VERSION } from '../assets/online/finish_machine.js';

const START = 1_792_000_000_000;
// performance.now() of the page → the synced server clock
const OFFSET = START - 10_000;
const ASSETS = {
    tasksVisionUrl: 'https://cdn.example/vision_bundle.mjs',
    wasmBaseUrl: 'https://cdn.example/wasm',
    poseModelUrl: 'https://models.example/pose.task',
    gestureModelUrl: 'https://models.example/gesture.task',
};

async function settle() {
    for (let i = 0; i < 20; i++) {
        await new Promise((resolve) => setImmediate(resolve));
    }
}

function storage(initial = {}) {
    const items = new Map(Object.entries(initial));

    return {
        getItem: (key) => (items.has(key) ? items.get(key) : null),
        setItem: (key, value) => items.set(key, String(value)),
        removeItem: (key) => items.delete(key),
        items,
    };
}

function timers() {
    let now = 0;
    const list = [];

    return {
        now: () => now,
        set: (value) => {
            now = value;
        },
        schedule: (task, ms) => {
            const timer = { at: now + ms, task, cancelled: false };
            list.push(timer);

            return timer;
        },
        cancel: (timer) => {
            if (timer) {
                timer.cancelled = true;
            }
        },
        async advance(ms) {
            now += ms;

            for (const timer of list.filter((item) => !item.cancelled && item.at <= now)) {
                timer.cancelled = true;
                timer.task();
            }

            await settle();
        },
    };
}

class FakeWorker {
    constructor() {
        this.posted = [];
        this.listeners = { message: [], error: [], messageerror: [] };
        this.terminated = false;
    }

    addEventListener(type, listener) {
        this.listeners[type].push(listener);
    }

    postMessage(message, transfer = []) {
        this.posted.push({ message, transfer });
    }

    terminate() {
        this.terminated = true;
    }

    answer(data) {
        this.listeners.message.forEach((listener) => listener({ data }));
    }

    fail() {
        this.listeners.error.forEach((listener) => listener({ message: 'boom' }));
    }

    of(type) {
        return this.posted.filter((item) => item.message.type === type);
    }
}

function fakeGrabber() {
    const grabber = {
        feed: null,
        stills: [],
        feeds: 0,
        startFeed(handlers) {
            grabber.feed = handlers;
            grabber.feeds += 1;
        },
        stopFeed() {
            grabber.feed = null;
        },
        isFeeding: () => grabber.feed !== null,
        // Each still resolves when the test says so - with its own sharpness; it is asked with the judge
        still: (options) => new Promise((resolve) => {
            assert.equal(typeof options?.judge, 'function', 'a candidate\'s still says how sharp it is');
            grabber.stills.push(resolve);
        }),
        createCanvas: () => ({ width: 0, height: 0, getContext: () => null }),
        // A camera frame captured at `perf`: asked, then a bitmap handed over
        // (the feed tells the page of every fresh frame first - detector_feed.js onFresh)
        frame(perf) {
            if (grabber.feed === null) {
                return null;
            }

            grabber.feed.onFresh?.(perf);

            if (grabber.feed === null || !grabber.feed.wants(perf)) {
                return null;
            }

            const bitmap = { closed: false, close() { this.closed = true; } };
            grabber.feed.onBitmap(bitmap, perf);

            return bitmap;
        },
    };

    return grabber;
}

function paintRecorder() {
    const fields = new Map();
    const shown = new Map();
    const data = new Map();

    return {
        fields,
        shown,
        data,
        api: {
            field: (name, text) => fields.set(name, text),
            shown: (name, visible) => shown.set(name, visible),
            data: (name, value) => data.set(name, value),
            t: (key, params = {}) => `${key}${Object.keys(params).length > 0 ? JSON.stringify(params) : ''}`,
            tc: (key, count) => `${key}:${count}`,
        },
    };
}

/**
 * A detection page wired to fakes, with what it recorded.
 */
function page({ supported = true, store = storage(), visible = () => true, createWorker = null, recording = true } = {}) {
    const clock = timers();
    const grabber = fakeGrabber();
    const paint = paintRecorder();
    const workers = [];
    const recorded = [];
    const bursts = [];
    const photos = [];
    const detections = [];
    const chimes = [];
    const usages = [];
    let nextEvent = 1;

    const detection = new CompeteDetectionPage({
        assets: ASSETS,
        grabber,
        feed: grabber,
        clock: { toServerMs: (perf) => OFFSET + perf },
        storage: store,
        participantId: 'p1',
        parts: new Map([['check', { dataset: {} }]]),
        paint: paint.api,
        recordCandidate: (data, perfMoment) => {
            const event = { id: `event-${nextEvent++}`, clientMs: Math.round(OFFSET + perfMoment), data };
            recorded.push(event);

            return event;
        },
        captureBurst: (eventId, momentMs) => bursts.push({ eventId, momentMs }),
        capturePhoto: (eventId, still) => photos.push({ eventId, still }),
        onDetection: (detected) => detections.push(detected),
        recordDetector: (usage) => {
            if (!recording) {
                return null;
            }

            usages.push(usage);

            return { id: `detector-${usages.length}` };
        },
        beeps: { chime: () => chimes.push(clock.now()) },
        serverNow: () => OFFSET + clock.now(),
        requestRender: () => {},
        createWorker: createWorker ?? (() => {
            const worker = new FakeWorker();
            workers.push(worker);

            return worker;
        }),
        now: clock.now,
        supported,
        schedule: clock.schedule,
        cancel: clock.cancel,
        isVisible: visible,
    });

    return { detection, clock, grabber, paint, workers, recorded, bursts, photos, detections, chimes, store, usages };
}

function input(overrides = {}) {
    return {
        mode: 'on',
        screen: 'checklist',
        ready: false,
        visible: true,
        cameraLive: true,
        practice: false,
        preview: false,
        secondScreen: false,
        takenOver: false,
        linkDead: false,
        entryConfirmed: false,
        runKey: START,
        startedAtMs: START,
        limitMs: 3_600_000,
        detections: [],
        serverNowMs: START,
        ...overrides,
    };
}

/**
 * A worker answer to the last frame sent - a signal for both views (the set-up) or one.
 */
function answer(t, { angled = null, topDown = null, inferenceMs = 60 } = {}) {
    const worker = t.workers[0];
    const frame = worker.of('frame').at(-1).message;
    const signal = { view: 'any' };

    if (angled !== null) {
        signal.angled = { raised: angled > 0, score: angled };
    }

    if (topDown !== null) {
        signal.topDown = { raised: topDown > 0, score: topDown };
    }

    worker.answer({ type: 'result', frameId: frame.frameId, captureTimeMs: frame.captureTimeMs, inferenceMs, signal });
}

/**
 * Frames every `stepMs` (perf) with an answer each - `raised(perf)` → {angled, topDown}.
 */
async function frames(t, fromMs, toMs, raised, stepMs = 200) {
    for (let at = fromMs; at <= toMs; at += stepMs) {
        t.clock.set(at);

        if (t.grabber.frame(at) !== null) {
            answer(t, raised(at));
        }
    }

    await settle();
}

async function readyDetector(t, view = 'any') {
    t.detection.plan(input());
    t.workers[0].answer({ type: 'ready', view, models: ['pose', 'gesture'], worker: 'classic', loadMs: 840 });
}

/**
 * A device that passed its test (angled), the round running from `at`, the worker configured.
 */
async function running(t, { mode = 'on', at = 0 } = {}) {
    await readyDetector(t);
    t.detection.choice = { view: 'angled', test: 'passed', atMs: null };
    t.clock.set(at);
    t.detection.plan(input({ mode, screen: 'running', ready: true }));
    t.workers.at(-1).answer({ type: 'configured', view: 'angled' });
}

/**
 * A worker answer to the last frame of the page's current worker.
 */
function answerLast(t, angled, { inferenceMs = 60 } = {}) {
    const worker = t.workers.at(-1);
    const frame = worker.of('frame').at(-1).message;
    worker.answer({ type: 'result', frameId: frame.frameId, captureTimeMs: frame.captureTimeMs, inferenceMs, signal: { view: 'angled', angled: { raised: angled > 0, score: angled } } });
}

const scenarios = {
    async 'the chunk loads from the checklist on - with the parts, the mode not off, consent, on a device that competes'() {
        const on = { round: { settings: { detection: 'on' } } };
        const base = { hasParts: true, state: on, consent: true, screen: 'checklist' };

        assert.equal(detectionMode(on), 'on');
        assert.equal(detectionMode({ round: { settings: { detection: 'shadow' } } }), 'shadow');
        assert.equal(detectionMode({ round: { settings: { detection: 'loud' } } }), 'off');
        assert.equal(detectionMode(null), 'off');

        assert.equal(detectionLoadWanted(base), true);
        for (const screen of ['waiting', 'countdown', 'running', 'finished']) {
            assert.equal(detectionLoadWanted({ ...base, screen }), true, screen);
        }

        for (const screen of ['welcome', 'timeup', 'after', 'no_round', 'not_entered', 'taken_over', 'dead_link']) {
            assert.equal(detectionLoadWanted({ ...base, screen }), false, screen);
        }

        assert.equal(detectionLoadWanted({ ...base, hasParts: false }), false, 'a page without the parts');
        assert.equal(detectionLoadWanted({ ...base, state: { round: { settings: { detection: 'off' } } } }), false);
        assert.equal(detectionLoadWanted({ ...base, consent: false }), false);
        assert.equal(detectionLoadWanted({ ...base, preview: true }), false);
        assert.equal(detectionLoadWanted({ ...base, secondScreen: true }), false);
        assert.equal(detectionLoadWanted({ ...base, linkDead: true }), false);
        assert.ok(LOAD_RETRY_MS.length > 0);
        assert.equal(DETECTION_CHUNK_NAME, 'msp-compete-detection-runtime');
    },

    async 'when the detector looks: the set-up test on the checklist, the round from GO - never hidden, without camera, in a practice'() {
        const base = { mode: 'on', state: 'ready', test: null, testActive: false, screen: 'running', ready: true, visible: true, cameraLive: true };

        assert.equal(detectorRun(base), 'round');
        assert.equal(detectorRun({ ...base, screen: 'finished' }), 'off', 'the participant tapped Finished: nothing after it is a candidate');
        assert.equal(detectorRun({ ...base, mode: 'shadow' }), 'round');
        assert.equal(detectorRun({ ...base, stopped: 'slow' }), 'off', 'stopped during the round: button-only');

        for (const screen of ['waiting', 'countdown', 'finished', 'timeup', 'after', 'checklist']) {
            assert.equal(detectorRun({ ...base, screen }), 'off', screen);
        }

        assert.equal(detectorRun({ ...base, mode: 'off' }), 'off');
        assert.equal(detectorRun({ ...base, state: 'loading' }), 'off');
        assert.equal(detectorRun({ ...base, visible: false }), 'off', 'paused while the page is hidden');
        assert.equal(detectorRun({ ...base, cameraLive: false }), 'off');
        assert.equal(detectorRun({ ...base, practice: true }), 'off');
        assert.equal(detectorRun({ ...base, preview: true }), 'off');
        assert.equal(detectorRun({ ...base, secondScreen: true }), 'off');
        assert.equal(detectorRun({ ...base, takenOver: true }), 'off');
        assert.equal(detectorRun({ ...base, linkDead: true }), 'off');
        assert.equal(detectorRun({ ...base, entryConfirmed: true }), 'off', 'until the result is confirmed');
        assert.equal(detectorRun({ ...base, ready: false }), 'off');
        assert.equal(detectorRun({ ...base, test: 'failed' }), 'off', 'a failed test: button-only');
        assert.equal(detectorRun({ ...base, test: 'skipped' }), 'off');
        assert.equal(detectorRun({ ...base, test: 'passed' }), 'round');

        // The set-up test watches on the checklist only - a failed or skipped test may be tried again there
        assert.equal(detectorRun({ ...base, screen: 'checklist', testActive: true, ready: false }), 'setup');
        assert.equal(detectorRun({ ...base, screen: 'checklist', testActive: true, test: 'failed' }), 'setup');
        assert.equal(detectorRun({ ...base, screen: 'waiting', testActive: true }), 'off');
    },

    async 'button-only: no detector here, a broken one, a failed or skipped test - said by state and reported as such'() {
        assert.equal(browserSupportsDetector({ Worker: function () {}, createImageBitmap: () => {}, OffscreenCanvas: function () {} }), true);
        assert.equal(browserSupportsDetector({ Worker: function () {}, createImageBitmap: () => {} }), false, 'no OffscreenCanvas (iOS before 16.4)');
        assert.equal(browserSupportsDetector({}), false);

        assert.equal(stateAfterInit({ type: 'ready' }), 'ready');
        assert.equal(stateAfterInit({ type: 'error', phase: 'init', name: 'NotSupportedError' }), 'unsupported', 'no WebGL2 in the worker');
        assert.equal(stateAfterInit({ type: 'error', phase: 'init', name: 'TypeError' }), 'error');

        assert.equal(buttonOnly({ state: 'ready', test: null }), false);
        assert.equal(buttonOnly({ state: 'ready', test: 'passed' }), false);
        assert.equal(buttonOnly({ state: 'unsupported', test: 'passed' }), true);
        assert.equal(buttonOnly({ state: 'error', test: null }), true);
        assert.equal(buttonOnly({ state: 'ready', test: 'failed' }), true);
        assert.equal(buttonOnly({ state: 'ready', test: 'skipped' }), true);
        assert.equal(buttonOnly({ state: 'ready', test: 'passed', stopped: 'slow' }), true, 'stopped during the round');
        assert.equal(buttonOnly({ state: 'ready', test: 'passed', stopped: null }), false);

        // The heartbeat: a detector not used reports `off`, never a fps it does not run at
        const stats = { fps: 4.8, inferenceMs: 61.2 };
        assert.deepEqual(telemetry({ mode: 'on', state: 'ready', test: 'passed', view: 'topDown', stats }), { ok: true, fps: 4.8, mode: 'on', state: 'ready', inferenceMs: 61.2, view: 'topDown' });
        assert.deepEqual(telemetry({ mode: 'on', state: 'ready', test: 'failed', view: 'angled', stats }), { ok: false, fps: null, mode: 'on', state: 'off', inferenceMs: null, view: null });
        assert.deepEqual(telemetry({ mode: 'shadow', state: 'unsupported', test: null, view: 'angled', stats }), { ok: false, fps: null, mode: 'shadow', state: 'unsupported', inferenceMs: null, view: null });
        assert.deepEqual(telemetry({ mode: 'on', state: 'loading', test: null, view: 'angled' }), { ok: false, fps: null, mode: 'on', state: 'loading', inferenceMs: null, view: null });
        assert.deepEqual(telemetry({ mode: 'on', state: 'ready', test: 'passed', view: 'angled', stats, stopped: 'slow' }), { ok: false, fps: null, mode: 'on', state: 'off', inferenceMs: null, view: null }, 'slow: the control room\'s "Button only"');

        // detector.changed: does it watch the round here - else why not
        assert.deepEqual(detectorUsage({ mode: 'on', state: 'ready', test: 'passed', view: 'topDown' }), { state: 'ready', running: true, reason: null, view: 'topDown' });
        assert.deepEqual(detectorUsage({ mode: 'shadow', state: 'ready', test: null, view: 'sideways' }), { state: 'ready', running: true, reason: null, view: 'angled' }, 'untested: the default view');
        assert.deepEqual(detectorUsage({ mode: 'on', state: 'ready', test: 'passed', view: 'angled', stopped: 'slow' }), { state: 'ready', running: false, reason: 'slow', view: null });
        assert.deepEqual(detectorUsage({ mode: 'on', state: 'error', test: null, view: 'angled', stopped: 'stalled' }).reason, 'stalled');
        assert.deepEqual(detectorUsage({ mode: 'on', state: 'loading', test: null, view: 'angled' }).reason, 'loading');
        assert.deepEqual(detectorUsage({ mode: 'on', state: 'unsupported', test: null, view: 'angled' }).reason, 'unsupported');
        assert.deepEqual(detectorUsage({ mode: 'on', state: 'ready', test: 'failed', view: 'angled' }).reason, 'failed');
        assert.deepEqual(detectorUsage({ mode: 'on', state: 'ready', test: 'skipped', view: 'angled' }).reason, 'skipped');
        assert.deepEqual(detectorUsage({ mode: 'off', state: 'ready', test: 'passed', view: 'angled' }).reason, 'off');
        assert.deepEqual(DETECTOR_REASONS, ['off', 'loading', 'unsupported', 'error', 'stalled', 'slow', 'failed', 'skipped']);

        assert.deepEqual(readyDetails({ state: 'ready', test: 'passed', view: 'topDown', loadMs: 1234.6 }), { state: 'ready', view: 'topDown', setupTest: 'passed', loadMs: 1235 });
        assert.deepEqual(readyDetails({ state: 'error', test: null, view: 'angled' }), { state: 'error', view: null, setupTest: null, loadMs: null });

        // "I'm ready" waits only while it loads in `on` mode - `shadow`, a skip, a failure, an unsupported phone never
        // hold anybody up
        assert.equal(holdsReady({ mode: 'on', state: 'loading', test: null }), true);
        assert.equal(holdsReady({ mode: 'on', state: 'loading', test: 'failed' }), true);
        assert.equal(holdsReady({ mode: 'shadow', state: 'loading', test: null }), false, 'shadow loads in the background');
        assert.equal(holdsReady({ mode: 'on', state: 'loading', test: 'skipped' }), false);
        assert.equal(holdsReady({ mode: 'on', state: 'unsupported', test: null }), false);
        assert.equal(holdsReady({ mode: 'on', state: 'error', test: null }), false);
        assert.equal(holdsReady({ mode: 'on', state: 'ready', test: null }), false);
        assert.equal(holdsReady({ mode: 'off', state: 'loading', test: null }), false);
    },

    async 'the device keeps its choice: view and test result - anything broken reads as never tested, angled'() {
        const store = storage();

        assert.deepEqual(loadDetectorChoice(store, 'p1'), { view: DEFAULT_VIEW, test: null, atMs: null });
        assert.equal(DEFAULT_VIEW, 'angled', 'the framing organisers ask for');
        assert.equal(saveDetectorChoice(store, 'p1', { view: 'topDown', test: 'passed', atMs: 5 }), true);
        assert.deepEqual(loadDetectorChoice(store, 'p1'), { view: 'topDown', test: 'passed', atMs: 5 });
        assert.deepEqual(loadDetectorChoice(store, 'p2'), { view: 'angled', test: null, atMs: null }, 'per participant');

        store.setItem(`${STORAGE_DETECTOR}p1`, '{nope');
        assert.deepEqual(loadDetectorChoice(store, 'p1'), { view: 'angled', test: null, atMs: null });
        store.setItem(`${STORAGE_DETECTOR}p1`, JSON.stringify({ view: 'sideways', test: 'maybe' }));
        assert.deepEqual(loadDetectorChoice(store, 'p1'), { view: 'angled', test: null, atMs: null });

        const broken = { getItem: () => { throw new Error('private'); }, setItem: () => { throw new Error('private'); } };
        assert.deepEqual(loadDetectorChoice(broken, 'p1'), { view: 'angled', test: null, atMs: null });
        assert.equal(saveDetectorChoice(broken, 'p1', { view: 'angled', test: 'failed' }), false);
    },

    async 'the set-up test: both views watch - the first candidate decides; both on one frame = angled; 20 s without one = failed'() {
        const result = (at, angled, topDown) => ({
            captureTimeMs: at,
            signal: { view: 'any', angled: { raised: angled, score: angled ? 0.9 : 0 }, topDown: { raised: topDown, score: topDown ? 0.8 : 0 } },
        });

        // Gestures see the open palms (a top-down mount): topDown - once the hands were seen down (the machines arm like
        // the round's: the test proves the whole cycle)
        const top = createSetUpTest({ startedAtMs: 1_000 });
        for (const at of [1_000, 1_300, 1_600]) {
            assert.equal(top.push(result(at, false, false)), 'running');
        }

        assert.equal(top.push(result(1_800, false, true)), 'running');
        assert.equal(top.holding(), true);
        assert.equal(top.push(result(2_000, false, true)), 'running');
        assert.equal(top.push(result(2_300, false, true)), 'passed', 'held 0.5 s');
        assert.equal(top.view, 'topDown');
        assert.equal(top.push(result(2_500, true, true)), 'passed', 'decided once');

        // Hands already up when the test starts: not even "holding" - lowered and raised again, it passes
        const early = createSetUpTest({ startedAtMs: 0 });
        for (const at of [0, 200, 400, 700]) {
            early.push(result(at, true, false));
        }

        assert.equal(early.status, 'running');
        assert.equal(early.holding(), false);
        for (const at of [900, 1_200, 1_500]) {
            early.push(result(at, false, false));
        }

        for (const at of [1_700, 1_900, 2_200]) {
            early.push(result(at, true, false));
        }

        assert.equal(early.status, 'passed');

        // A detector that reads "raised" on every frame (a poster, an odd pose) fails the test - it would never arm in
        // the round
        const stuck = createSetUpTest({ startedAtMs: 0 });
        for (let at = 0; at <= SETUP_TEST_MS; at += 250) {
            stuck.push(result(at, true, false));
        }

        assert.equal(stuck.push(result(SETUP_TEST_MS + 1, true, false)), 'failed');

        // Both models see it on the same frame: angled
        const both = createSetUpTest({ startedAtMs: 0 });
        for (const at of [0, 300, 600]) {
            both.push(result(at, false, false));
        }

        for (const at of [800, 1_000, 1_300]) {
            both.push(result(at, true, true));
        }

        assert.equal(both.status, 'passed');
        assert.equal(both.view, 'angled');

        // A raise too short, then nothing for the rest of the window: failed
        const failed = createSetUpTest({ startedAtMs: 0 });
        failed.push(result(200, true, false));
        failed.push(result(400, false, false));
        assert.equal(failed.status, 'running');
        assert.equal(failed.leftMs(5_000), SETUP_TEST_MS - 5_000);
        assert.equal(failed.push(result(SETUP_TEST_MS + 1, true, false)), 'failed', 'a frame past the window');

        // No frames at all (a stalled worker): the window ends it too
        const stalled = createSetUpTest({ startedAtMs: 0 });
        assert.equal(stalled.tick(SETUP_TEST_MS), 'running');
        assert.equal(stalled.tick(SETUP_TEST_MS + 1), 'failed');

        // The page's own test (not asked for): nothing seen up at all = expired (untested); a raise seen = failed
        const unasked = createSetUpTest({ startedAtMs: 0, asked: false });
        unasked.push(result(200, false, false));
        assert.equal(unasked.tick(SETUP_TEST_MS + 1), 'expired');
        const tried = createSetUpTest({ startedAtMs: 0, asked: false });
        tried.push(result(200, false, true));
        tried.push(result(400, false, false));
        assert.equal(tried.tick(SETUP_TEST_MS + 1), 'failed', 'the detector saw hands up - never long enough');

        // One view's signal (a worker configured to it) counts for that view
        assert.deepEqual(Object.keys(viewSignals({ view: 'topDown', raised: true, score: 0.7 })), ['topDown']);
        assert.deepEqual(viewSignals(null), {});
        assert.deepEqual(roundSignal({ captureTimeMs: 5, signal: { view: 'any', angled: { raised: true, score: 0.6 }, topDown: { raised: false, score: 0 } } }, 'angled'), { captureMs: 5, raised: true, score: 0.6 });
        assert.deepEqual(roundSignal({ captureTimeMs: 6, signal: { view: 'topDown', raised: true, score: 0.8, reason: 'raised' } }, 'topDown'), { captureMs: 6, raised: true, score: 0.8 });
        assert.deepEqual(roundSignal({ captureTimeMs: 7, signal: { view: 'angled', raised: true, score: 0.8 } }, 'topDown'), { captureMs: 7, raised: false, score: 0 }, 'another view never counts');
    },

    async 'a candidate as the event: moments on the synced clock, the window, the scores, the view, the mode, the version, the cadence'() {
        const candidate = {
            type: 'candidate', id: 2, atMs: 50_000.4, detectedAtMs: 50_600, heldMs: 599.6, windowFromMs: 50_000.4, windowToMs: 50_600,
            upFrames: 4, droppedFrames: 1, score: 0.812, minScore: 0.7, peakScore: 0.9, straddled: false, mode: 'on', shadow: false, version: FINISH_MACHINE_VERSION,
        };
        const data = candidateData(candidate, { toServerMs: (perf) => OFFSET + perf, view: 'topDown', mode: 'on', stats: { fps: 4.6, inferenceMs: 72.4 } });

        assert.deepEqual(data, {
            candidateId: 2,
            detectedAtMs: OFFSET + 50_600,
            windowFromMs: OFFSET + 50_000,
            windowToMs: OFFSET + 50_600,
            heldMs: 600,
            score: 0.812,
            minScore: 0.7,
            peakScore: 0.9,
            upFrames: 4,
            droppedFrames: 1,
            straddled: false,
            view: 'topDown',
            mode: 'on',
            machineVersion: FINISH_MACHINE_VERSION,
            fps: 4.6,
            inferenceMs: 72.4,
        });
        assert.equal(JSON.stringify(data).length < 2048, true, 'within the server\'s data limit (OnlineEntryQuota::MAX_EVENT_DATA_BYTES)');
        assert.equal(candidateData(candidate, { toServerMs: (perf) => perf, view: 'nowhere', mode: 'shadow' }).view, 'angled');
        assert.equal(candidateData(candidate, { toServerMs: (perf) => perf, view: 'angled', mode: 'shadow' }).fps, null);
    },

    async 'the candidate photo: the sharpest still taken while the hands were up'() {
        const still = (captureMs, sharpness) => ({ blob: { size: 1 }, captureMs, sharpness });

        assert.equal(sharpestStill([]), null);
        assert.equal(sharpestStill([still(10, 5), still(20, 9), still(30, 7)]).captureMs, 20);
        assert.equal(sharpestStill([still(1_000, 5), still(1_300, 9), still(1_600, 70)], 1_250).captureMs, 1_300, 'taken after the hands went down: not in the window');
        assert.equal(sharpestStill([still(1_000, 5), still(1_300, 9), still(1_500, 70)], 1_250).captureMs, 1_500, 'one spacing after the last raised frame still counts (the detector looks at 5 fps)');
        assert.equal(sharpestStill([still(10, null), still(20, null)]).captureMs, 10, 'nobody knows how sharp - the first');
        assert.equal(sharpestStill([still(1000, 9)], 10).captureMs, 1000, 'nothing in the window - the first still rather than none');
        assert.equal(sharpestStill([still(10, 3), { captureMs: 11 }]).captureMs, 10, 'a still without its blob is left out');
    },

    async 'what the participant sees: `on` - the latest detection of the run, the flash, the cooldown hint; `shadow` - nothing'() {
        const detections = [{ eventId: 'a', momentMs: START + 2_830_000 }, { eventId: 'b', momentMs: START + 2_845_000 }, { eventId: 'old', momentMs: START - 5_000 }];
        const shown = feedback({ mode: 'on', detections, startedAtMs: START, serverNowMs: START + 2_846_000, flashUntilMs: START + 2_846_500, suppressedUntilMs: null, screen: 'running' });

        assert.deepEqual(shown, { detected: true, momentMs: START + 2_845_000, elapsedMs: 2_845_000, flash: true, suppressed: false, hint: false, buttonOnly: false });
        assert.equal(feedback({ mode: 'on', detections, startedAtMs: START, serverNowMs: START + 2_847_000, flashUntilMs: START + 2_846_500, screen: 'running' }).flash, false, 'the flash ends');
        assert.deepEqual(feedback({ mode: 'on', detections: [], startedAtMs: START, serverNowMs: START, screen: 'running' }).hint, true, 'before a finish: "Done? Raise both hands"');
        assert.equal(feedback({ mode: 'on', detections: [], startedAtMs: START, serverNowMs: START, suppressedUntilMs: START + 1, screen: 'running' }).suppressed, true);
        assert.equal(feedback({ mode: 'on', detections, startedAtMs: START, serverNowMs: START, screen: 'timeup' }).detected, true, 'time\'s up says it too');
        assert.equal(feedback({ mode: 'on', detections, startedAtMs: START, serverNowMs: START, screen: 'waiting' }).detected, false);
        assert.deepEqual(feedback({ mode: 'on', detections, startedAtMs: START, serverNowMs: START, buttonOnly: true, screen: 'running' }).buttonOnly, true);
        // A finish recorded stays shown when the detector stopped later; the hint never asks for a raise nobody sees
        assert.equal(feedback({ mode: 'on', detections, startedAtMs: START, serverNowMs: START, buttonOnly: true, screen: 'running' }).detected, true);
        const blind = feedback({ mode: 'on', detections: [], startedAtMs: START, serverNowMs: START, suppressedUntilMs: START + 1, watching: false, screen: 'running' });
        assert.deepEqual([blind.hint, blind.suppressed, blind.buttonOnly], [false, false, true], 'not watching (a frozen camera, still loading): "Tap Finished"');
        assert.equal(feedback({ mode: 'on', detections: [], startedAtMs: START, serverNowMs: START, watching: false, screen: 'finished' }).buttonOnly, false);

        const silent = feedback({ mode: 'shadow', detections, startedAtMs: START, serverNowMs: START + 2_846_000, flashUntilMs: START + 2_846_500, screen: 'running' });
        assert.deepEqual(silent, { detected: false, momentMs: null, elapsedMs: null, flash: false, suppressed: false, hint: false, buttonOnly: false }, 'shadow shows nothing');
        assert.equal(feedback({ mode: 'off', detections, startedAtMs: START, serverNowMs: START, screen: 'running' }).detected, false);

        assert.equal(latestDetection(detections, START).eventId, 'b');
        assert.equal(latestDetection(detections.slice(2), START), null, 'an earlier run\'s');
        assert.equal(latestDetection([], null), null);
    },

    async 'the checklist row: loading (a skip after a while), testing, passed by view, failed, skipped, unsupported, error - every code has its text'() {
        const ready = { state: 'ready', test: null, view: 'angled' };

        assert.deepEqual(checklistRow({ ...ready, state: 'loading', loadingForMs: 1_000 }), { level: 'warn', code: 'loading', skip: false, retry: false, test: false });
        assert.deepEqual(checklistRow({ ...ready, state: 'loading', loadingForMs: LOAD_SKIP_AFTER_MS }), { level: 'warn', code: 'loading_slow', skip: true, retry: false, test: false });
        assert.deepEqual(checklistRow({ ...ready, setUp: createSetUpTest({ startedAtMs: 0 }) }), { level: 'info', code: 'testing', skip: false, retry: false, test: false });
        assert.deepEqual(checklistRow({ ...ready, test: 'passed', view: 'topDown' }), { level: 'ok', code: 'passed_topDown', skip: false, retry: false, test: true });
        assert.equal(checklistRow({ ...ready, test: 'passed' }).code, 'passed_angled');
        assert.deepEqual(checklistRow({ ...ready, test: 'failed' }), { level: 'warn', code: 'failed', skip: false, retry: false, test: true });
        assert.deepEqual(checklistRow({ ...ready, state: 'loading', test: 'skipped' }), { level: 'warn', code: 'skipped', skip: false, retry: false, test: true });
        assert.deepEqual(checklistRow({ ...ready, state: 'unsupported' }), { level: 'warn', code: 'unsupported', skip: false, retry: false, test: false });
        assert.deepEqual(checklistRow({ ...ready, state: 'error' }), { level: 'warn', code: 'error', skip: false, retry: true, test: false });
        assert.deepEqual(checklistRow(ready), { level: 'info', code: 'untested', skip: false, retry: false, test: true });

        const holding = createSetUpTest({ startedAtMs: 0 });
        for (const at of [100, 400, 700]) {
            holding.push({ captureTimeMs: at, signal: { view: 'any', angled: { raised: false, score: 0 }, topDown: { raised: false, score: 0 } } });
        }

        holding.push({ captureTimeMs: 900, signal: { view: 'any', angled: { raised: true, score: 0.9 }, topDown: { raised: false, score: 0 } } });
        assert.equal(checklistRow({ ...ready, setUp: holding }).code, 'holding');

        // `shadow` never holds "I'm ready": no "still preparing", no skip
        assert.deepEqual(checklistRow({ ...ready, state: 'loading', loadingForMs: LOAD_SKIP_AFTER_MS * 2, mode: 'shadow' }), { level: 'warn', code: 'loading', skip: false, retry: false, test: false });

        for (const code of ['loading', 'loading_slow', 'testing', 'holding', 'passed_angled', 'passed_topDown', 'untested', 'failed', 'skipped', 'unsupported', 'error']) {
            assert.ok(CHECK_CODES.includes(code), code);
        }
    },

    async 'one worker for the session: init with both models from the page\'s URLs; ready → the set-up test starts by itself and its frames go out transferred'() {
        const t = page();

        assert.equal(t.detection.state, 'off');
        t.detection.plan(input());
        assert.equal(t.workers.length, 1);
        assert.equal(t.detection.state, 'loading');
        assert.deepEqual(t.workers[0].of('init')[0].message, { type: 'init', view: 'any', ...ASSETS });
        assert.equal(t.detection.blocksReady(), true, '"I\'m ready" waits for it');
        assert.equal(t.grabber.isFeeding(), false, 'nothing to look with yet');

        t.detection.plan(input());
        assert.equal(t.workers.length, 1, 'never a second worker');

        t.clock.set(500);
        t.workers[0].answer({ type: 'ready', view: 'any', models: ['pose', 'gesture'], worker: 'classic', loadMs: 840 });
        assert.equal(t.detection.state, 'ready');
        assert.equal(t.detection.blocksReady(), false);

        t.detection.plan(input());
        assert.notEqual(t.detection.setUp, null, 'the set-up test started by itself');
        assert.equal(t.detection.run, 'setup');
        assert.equal(t.grabber.isFeeding(), true);
        assert.equal(t.workers[0].of('configure').length, 0, 'both models already run');

        t.clock.set(700);
        const bitmap = t.grabber.frame(700);
        const sent = t.workers[0].of('frame')[0];
        assert.equal(sent.message.bitmap, bitmap);
        assert.deepEqual(sent.transfer, [bitmap], 'transferred, never copied');
        assert.equal(sent.message.captureTimeMs, 700);
        assert.equal(t.grabber.frame(750), null, 'one frame in flight');

        t.detection.tick();
        assert.equal(t.paint.fields.get('detectionCheck'), 'detection_check_testing{"seconds":20}');
        assert.equal(t.paint.shown.get('detectionTest'), false);
        assert.equal(t.detection.readyDetails().loadMs, 840);
    },

    async 'the set-up test passes with the view that saw the raise - kept on the device, the round runs that view only'() {
        const t = page();
        await readyDetector(t);
        t.clock.set(1_000);
        t.detection.plan(input());

        await frames(t, 1_000, 2_400, (at) => ({ angled: 0, topDown: at >= 1_800 ? 0.85 : 0 }));
        assert.equal(t.detection.setUp, null);
        assert.deepEqual(loadDetectorChoice(t.store, 'p1').view, 'topDown');
        assert.equal(loadDetectorChoice(t.store, 'p1').test, 'passed');
        assert.equal(t.chimes.length, 1, 'the phone says it heard');

        t.detection.plan(input());
        t.detection.tick();
        assert.equal(t.paint.fields.get('detectionCheck'), 'detection_check_passed_topDown{"seconds":0}');
        assert.equal(t.paint.shown.get('detectionTest'), true);
        assert.equal(t.paint.fields.get('detectionTestLabel'), 'detection_test_again');
        assert.equal(t.detection.run, 'off', 'nothing to look for on the checklist after the test');

        // The round: the worker runs the chosen view; frames wait until it said so
        t.detection.plan(input({ screen: 'running', ready: true }));
        assert.equal(t.detection.run, 'round');
        assert.deepEqual(t.workers[0].of('configure').at(-1).message, { type: 'configure', view: 'topDown' });
        t.clock.set(10_000);
        assert.equal(t.grabber.frame(10_000), null, 'not configured yet');
        t.workers[0].answer({ type: 'configured', view: 'topDown' });
        assert.notEqual(t.grabber.frame(10_200), null);
        assert.deepEqual(t.detection.telemetry(), { ok: true, fps: 5, mode: 'on', state: 'ready', inferenceMs: 60, view: 'topDown' });
    },

    async 'a failed set-up test is never a blocker: button-only on this device, "Test again" can bring it back'() {
        const t = page();
        await readyDetector(t);
        t.clock.set(1_000);
        t.detection.plan(input());

        // The page's own test (started once the detector was ready) and nobody raised a hand: untested, not failed
        await frames(t, 1_000, 3_000, () => ({ angled: 0, topDown: 0 }));
        t.clock.set(1_000 + SETUP_TEST_MS + 1);
        t.detection.plan(input());
        assert.equal(t.detection.setUp, null);
        assert.equal(loadDetectorChoice(t.store, 'p1').test, null, 'nobody tried');
        assert.equal(t.detection.isButtonOnly(), false);
        t.detection.tick();
        assert.equal(t.paint.fields.get('detectionCheck'), 'detection_check_untested{"seconds":0}');
        t.detection.plan(input());
        assert.equal(t.detection.setUp, null, 'the page starts its own test once');

        // "Test": the hands go up too briefly, then nothing - the detector saw them, never long enough: failed
        t.clock.set(25_000);
        t.detection.startTest();
        t.detection.plan(input());
        await frames(t, 25_000, 27_000, (at) => ({ angled: at >= 25_400 && at <= 25_600 ? 0.9 : 0, topDown: 0 }));
        t.clock.set(25_000 + SETUP_TEST_MS + 1);
        t.detection.plan(input());
        assert.equal(t.detection.setUp, null);
        assert.equal(loadDetectorChoice(t.store, 'p1').test, 'failed');
        assert.equal(t.detection.isButtonOnly(), true);
        assert.equal(t.detection.blocksReady(), false);
        t.detection.tick();
        assert.equal(t.paint.fields.get('detectionCheck'), 'detection_check_failed{"seconds":0}');

        // The round competes with the button: nothing looks, the heartbeat says off
        t.detection.plan(input({ screen: 'running', ready: true }));
        assert.equal(t.detection.run, 'off');
        assert.equal(t.grabber.isFeeding(), false);
        assert.equal(t.detection.telemetry().state, 'off');
        t.detection.tick();
        assert.equal(t.paint.shown.get('detectionButtonOnly'), true, '"Tap Finished when your puzzle is complete"');

        // Back on the checklist (a reload before the start): Test again passes
        t.detection.plan(input());
        t.detection.startTest();
        t.detection.plan(input());
        assert.equal(t.detection.run, 'setup', 'a failed device may try again');
        t.clock.set(40_000);
        await frames(t, 40_000, 41_600, (at) => ({ angled: at >= 40_800 ? 0.9 : 0, topDown: 0 }));
        assert.equal(loadDetectorChoice(t.store, 'p1').test, 'passed');
        assert.equal(t.detection.isButtonOnly(), false);
    },

    async 'skip while it loads: "I\'m ready" at once, button-only - a reload remembers it; the test can be tried later'() {
        const t = page();
        t.detection.plan(input());
        assert.equal(t.detection.blocksReady(), true);

        t.clock.set(LOAD_SKIP_AFTER_MS);
        t.detection.tick();
        assert.equal(t.paint.shown.get('detectionSkip'), true, 'offered once it has loaded for a while');

        t.detection.skip();
        assert.equal(t.detection.blocksReady(), false);
        assert.equal(loadDetectorChoice(t.store, 'p1').test, 'skipped');

        // The detector finishes loading anyway: no test by itself, nothing looks
        t.workers[0].answer({ type: 'ready', view: 'any', models: ['pose', 'gesture'], worker: 'classic', loadMs: 20000 });
        t.detection.plan(input());
        assert.equal(t.detection.setUp, null);
        t.detection.plan(input({ screen: 'running', ready: true }));
        assert.equal(t.detection.run, 'off');

        const reloaded = page({ store: t.store });
        assert.equal(reloaded.detection.choice.test, 'skipped');

        // "Try the camera check" from the skipped row
        t.detection.plan(input());
        t.detection.startTest();
        assert.notEqual(t.detection.setUp, null);
        assert.equal(t.detection.choice.test, null);
    },

    async 'no detector in this browser, no WebGL2 in the worker, a worker that breaks: button-only, reported - never a second worker'() {
        const none = page({ supported: false });
        none.detection.plan(input());
        assert.equal(none.workers.length, 0);
        assert.equal(none.detection.state, 'unsupported');
        assert.equal(none.detection.blocksReady(), false);
        assert.equal(none.detection.telemetry().state, 'unsupported');
        none.detection.tick();
        assert.equal(none.paint.fields.get('detectionCheck'), 'detection_check_unsupported{"seconds":0}');

        const noGl = page();
        noGl.detection.plan(input());
        noGl.workers[0].answer({ type: 'error', phase: 'init', name: 'NotSupportedError', message: 'No WebGL2' });
        assert.equal(noGl.detection.state, 'unsupported');
        assert.equal(noGl.detection.readyDetails().state, 'unsupported');

        // A load that failed (the CDN, a network error): "Try again" inits the same worker
        const failed = page();
        failed.detection.plan(input());
        failed.workers[0].answer({ type: 'error', phase: 'init', name: 'TypeError', message: 'Failed to fetch' });
        assert.equal(failed.detection.state, 'error');
        failed.detection.tick();
        assert.equal(failed.paint.shown.get('detectionRetry'), true);
        failed.detection.retry();
        assert.equal(failed.workers.length, 1);
        assert.equal(failed.workers[0].of('init').length, 2);
        assert.equal(failed.detection.state, 'loading');

        // The worker itself breaks: error, terminated at once - "Try again" starts ONE new worker (never two alive), the
        // old one is not listened to any more
        failed.workers[0].fail();
        assert.equal(failed.detection.state, 'error');
        assert.equal(failed.workers[0].terminated, true);
        failed.detection.retry();
        failed.detection.plan(input());
        assert.equal(failed.workers.length, 2);
        assert.equal(failed.workers[0].of('init').length, 2, 'the broken worker is not asked again');
        assert.equal(failed.workers[1].of('init').length, 1);
        assert.equal(failed.detection.state, 'loading');
        failed.workers[0].answer({ type: 'ready', view: 'any', models: ['pose'], worker: 'classic', loadMs: 1 });
        assert.equal(failed.detection.state, 'loading', 'the old worker\'s answer is ignored');
        failed.workers[1].answer({ type: 'ready', view: 'any', models: ['pose', 'gesture'], worker: 'classic', loadMs: 900 });
        assert.equal(failed.detection.state, 'ready');
        assert.equal(failed.workers.filter((worker) => !worker.terminated).length, 1);

        // A worker that can not even be made
        const refused = page({ createWorker: () => { throw new Error('blocked'); } });
        refused.detection.plan(input());
        assert.equal(refused.detection.state, 'unsupported');
    },

    async 'frames the worker can not analyse: tolerated, but MAX_FRAME_ERRORS in a row = button-only, the feed stops'() {
        const t = page();
        await readyDetector(t);
        saveDetectorChoice(t.store, 'p1', { view: 'angled', test: 'passed' });
        t.detection.choice = loadDetectorChoice(t.store, 'p1');
        t.detection.plan(input({ screen: 'running', ready: true }));
        t.workers[0].answer({ type: 'configured', view: 'angled' });

        let at = 1_000;
        const fail = () => {
            at += 500;
            t.clock.set(at);
            assert.notEqual(t.grabber.frame(at), null, `frame at ${at}`);
            const frame = t.workers[0].of('frame').at(-1).message;
            t.workers[0].answer({ type: 'error', phase: 'frame', frameId: frame.frameId, name: 'Error', message: 'activeTexture' });
        };

        for (let i = 0; i < MAX_FRAME_ERRORS - 1; i++) {
            fail();
        }

        // One good answer resets the count
        at += 500;
        t.clock.set(at);
        assert.notEqual(t.grabber.frame(at), null);
        answer(t, { angled: 0 });

        for (let i = 0; i < MAX_FRAME_ERRORS - 1; i++) {
            fail();
        }

        assert.equal(t.detection.state, 'ready', 'one good answer in between');
        fail();
        assert.equal(t.detection.state, 'error');
        assert.equal(t.grabber.isFeeding(), false);
        assert.equal(t.detection.telemetry().state, 'error');
        assert.equal(t.workers[0].terminated, true, 'a broken worker is let go at once');
        t.detection.plan(input({ screen: 'running', ready: true }));
        assert.deepEqual(t.usages.at(-1), { state: 'error', running: false, reason: 'error', view: null }, 'one detector.changed records it');

        // "Try again" works afterwards: ONE new worker, init with both models - never two alive
        t.detection.retry();
        assert.equal(t.workers.length, 2);
        assert.equal(t.workers[1].of('init').length, 1);
        assert.equal(t.detection.state, 'loading');
        t.workers[1].answer({ type: 'ready', view: 'any', models: ['pose', 'gesture'], worker: 'classic', loadMs: 700 });
        t.detection.plan(input({ screen: 'running', ready: true }));
        assert.equal(t.detection.run, 'round');
        assert.equal(t.detection.isButtonOnly(), false);
        t.detection.retry();
        assert.equal(t.workers.length, 2, 'nothing to try again while it works');
    },

    async 'a raise in `on` mode: the event at the first raised frame, the burst, the sharpest still, the flash and the chime'() {
        const t = page();
        await readyDetector(t);
        saveDetectorChoice(t.store, 'p1', { view: 'angled', test: 'passed' });
        t.detection.choice = loadDetectorChoice(t.store, 'p1');
        t.clock.set(0);
        t.detection.plan(input({ screen: 'running', ready: true }));
        t.workers[0].answer({ type: 'configured', view: 'angled' });

        // Hands up from 2 000 ms (perf) on, at 5 fps
        await frames(t, 1_000, 2_600, (at) => ({ angled: at >= 2_000 ? 0.8 : 0 }));
        assert.equal(t.recorded.length, 1, 'one candidate');
        const event = t.recorded[0];
        assert.equal(event.clientMs, OFFSET + 2_000, 'the first raised frame\'s capture time - not the hold, not the inference');
        assert.equal(event.data.view, 'angled');
        assert.equal(event.data.mode, 'on');
        assert.equal(event.data.detectedAtMs, OFFSET + 2_600);
        assert.equal(event.data.heldMs, 600);
        assert.deepEqual(t.bursts, [{ eventId: event.id, momentMs: OFFSET + 2_000 }], 'the ±5 s burst around the moment');
        assert.deepEqual(t.detections, [{ eventId: event.id, momentMs: OFFSET + 2_000 }]);
        assert.equal(t.chimes.length, 1);

        // The stills: one at once, then every STILL_SPACING_MS while the hands are up - the sharpest wins
        assert.equal(t.grabber.stills.length, 1);
        t.grabber.stills[0]({ blob: { size: 300 }, captureMs: OFFSET + 2_640, sharpness: 12 });
        await t.clock.advance(STILL_SPACING_MS);
        assert.equal(t.grabber.stills.length, 2);
        t.grabber.stills[1]({ blob: { size: 300 }, captureMs: OFFSET + 2_940, sharpness: 40 });
        await t.clock.advance(STILL_SPACING_MS);
        assert.equal(t.grabber.stills.length, CANDIDATE_STILLS);
        t.grabber.stills[2]({ blob: { size: 300 }, captureMs: OFFSET + 3_240, sharpness: 25 });
        await settle();
        assert.equal(t.photos.length, 1);
        assert.equal(t.photos[0].eventId, event.id);
        assert.equal(t.photos[0].still.sharpness, 40);

        // What the participant sees: "Finish recorded at 00:00", the flash, the running clock small
        t.detection.plan(input({ screen: 'running', ready: true, detections: t.detections, serverNowMs: OFFSET + t.clock.now(), startedAtMs: OFFSET }));
        t.detection.tick();
        assert.equal(t.paint.shown.get('detectionDetected'), true);
        assert.equal(t.paint.fields.get('detectionRecorded'), 'detection_recorded{"time":"00:02"}');
        assert.equal(t.paint.data.get('detected'), '1');
        assert.equal(t.paint.data.get('detectionFlash'), '1');
        t.clock.set(t.clock.now() + FLASH_MS);
        t.detection.tick();
        assert.equal(t.paint.data.get('detectionFlash'), '0');

        // Still holding them up: one raise is one candidate; after the cooldown a new raise is a new one
        await frames(t, 2_800, 4_000, () => ({ angled: 0.8 }));
        assert.equal(t.recorded.length, 1);
        await frames(t, 4_200, 13_000, () => ({ angled: 0 }));
        await frames(t, 13_200, 14_000, () => ({ angled: 0.9 }));
        assert.equal(t.recorded.length, 2, 'a raise after the cooldown is a new candidate');
        assert.equal(t.recorded[1].data.candidateId, 2);
    },

    async 'a raise during the cooldown in `on` mode: "raise again in a moment"; the hands going down early end the stills'() {
        const t = page();
        await readyDetector(t);
        t.detection.choice = { view: 'angled', test: 'passed', atMs: null };
        t.detection.plan(input({ screen: 'running', ready: true }));
        t.workers[0].answer({ type: 'configured', view: 'angled' });

        await frames(t, 200, 1_600, (at) => ({ angled: at >= 1_000 ? 0.8 : 0 }));
        assert.equal(t.recorded.length, 1);
        // Down at once: the second still is never taken - the hands are on the puzzle again
        await frames(t, 1_800, 2_200, () => ({ angled: 0 }));
        t.grabber.stills[0]({ blob: { size: 1 }, captureMs: OFFSET + 1_640, sharpness: 3 });
        await t.clock.advance(STILL_SPACING_MS);
        assert.equal(t.grabber.stills.length, 1);
        assert.equal(t.photos.length, 1);

        // Up again 2 s later, held - inside the cooldown (the frames go on meanwhile: a gap would unarm the machine)
        await frames(t, 2_600, 5_000, (at) => ({ angled: at >= 4_000 ? 0.9 : 0 }));
        assert.equal(t.recorded.length, 1);
        t.detection.plan(input({ screen: 'running', ready: true, serverNowMs: OFFSET + 5_000 }));
        t.detection.tick();
        assert.equal(t.paint.shown.get('detectionSuppressed'), true);
    },

    async '`shadow` records everything silently: the event, the burst, the photo - no detection kept, no flash, no chime'() {
        const t = page();
        await readyDetector(t);
        t.detection.choice = { view: 'angled', test: null, atMs: null };
        t.detection.plan(input({ mode: 'shadow', screen: 'running', ready: true }));
        t.workers[0].answer({ type: 'configured', view: 'angled' });

        await frames(t, 200, 1_800, (at) => ({ angled: at >= 1_000 ? 0.8 : 0 }));
        assert.equal(t.recorded.length, 1);
        assert.equal(t.recorded[0].data.mode, 'shadow');
        assert.equal(t.bursts.length, 1);
        assert.equal(t.grabber.stills.length, 1);
        assert.deepEqual(t.detections, [], 'nothing shown, nothing kept for the screen');
        assert.equal(t.chimes.length, 0);

        t.detection.plan(input({ mode: 'shadow', screen: 'running', ready: true, detections: [{ eventId: 'x', momentMs: OFFSET + 1_000 }] }));
        t.detection.tick();
        assert.equal(t.paint.shown.get('detectionDetected'), false);
        assert.equal(t.paint.shown.get('detectionHint'), false);
        assert.equal(t.paint.data.get('detectionFlash'), '0');
        assert.equal(t.detection.detectedMoment(), null);
    },

    async 'a hidden page feeds nothing; a new run or another mode starts a new machine; a confirmed result stops it; `off` looks at nothing'() {
        let visible = true;
        const t = page({ visible: () => visible });
        await readyDetector(t);
        t.detection.choice = { view: 'angled', test: 'passed', atMs: null };
        t.detection.plan(input({ screen: 'running', ready: true }));
        t.workers[0].answer({ type: 'configured', view: 'angled' });

        visible = false;
        assert.equal(t.grabber.frame(100), null, 'hidden: its timers fire, nothing goes out');
        visible = true;

        await frames(t, 200, 1_600, (at) => ({ angled: at >= 1_200 ? 0.8 : 0 }));
        const machine = t.detection.machine;
        assert.equal(machine.state().phase, 'holding');

        t.detection.plan(input({ screen: 'running', ready: true, runKey: START + 60_000 }));
        assert.notEqual(t.detection.machine, machine, 'a Reset and a new start: a fresh machine');
        t.detection.plan(input({ mode: 'shadow', screen: 'running', ready: true, runKey: START + 60_000 }));
        assert.equal(t.detection.machine.rules.mode, 'shadow');

        t.detection.plan(input({ screen: 'running', ready: true, entryConfirmed: true }));
        assert.equal(t.detection.run, 'off');
        assert.equal(t.grabber.isFeeding(), false);

        t.detection.plan(input({ mode: 'off', screen: 'running', ready: true }));
        assert.equal(t.detection.run, 'off');
        assert.equal(t.detection.telemetry().ok, false);

        t.detection.close();
        assert.deepEqual(t.workers[0].posted.at(-1).message, { type: 'dispose' });
        assert.equal(t.workers[0].terminated, true);
    },

    async 'hands up at GO are never a candidate: the round\'s machine arms on hands seen down - the real raise later is one'() {
        const t = page();
        await running(t, { at: 1_500 });

        // GO + 1.5 s: the hands are up already (and stay up for 3 s)
        await frames(t, 1_600, 4_600, () => ({ angled: 0.9 }));
        assert.equal(t.recorded.length, 0, 'no candidate at 0:01');
        assert.equal(t.detection.machine.state().armed, false);
        t.detection.plan(input({ screen: 'running', ready: true, serverNowMs: OFFSET + 4_600 }));
        t.detection.tick();
        assert.equal(t.paint.shown.get('detectionHint'), true, 'it watches - "Done? Raise both hands"');

        await frames(t, 4_800, 9_000, (at) => ({ angled: at >= 7_000 ? 0.9 : 0 }));
        assert.equal(t.recorded.length, 1);
        assert.equal(t.recorded[0].clientMs, OFFSET + 7_000, 'the real raise - its first raised frame');
        assert.equal(t.recorded[0].data.machineVersion, 2);
    },

    async 'Finished stops the detector: no candidate after the tap; "I tapped by mistake" brings it back unarmed'() {
        const t = page();
        await running(t);

        // Hands down, then up and holding when the participant taps Finished (a frame in flight)
        await frames(t, 200, 1_400, (at) => ({ angled: at >= 1_200 ? 0.9 : 0 }));
        assert.equal(t.detection.machine.state().phase, 'holding');
        t.clock.set(1_600);
        assert.notEqual(t.grabber.frame(1_600), null);

        t.detection.plan(input({ screen: 'finished', ready: true }));
        assert.equal(t.detection.run, 'off');
        assert.equal(t.grabber.isFeeding(), false, 'the finished screen runs the detector off');
        assert.equal(t.detection.machine.state().armed, false, 'interrupted: unarmed');
        assert.equal(t.detection.machine.state().phase, 'idle');
        answerLast(t, 0.9);
        assert.equal(t.recorded.length, 0, 'the frame in flight completes no hold');

        // Undone: running again - hands still up = nothing; down, then up = a candidate
        t.clock.set(3_000);
        t.detection.plan(input({ screen: 'running', ready: true }));
        assert.equal(t.detection.run, 'round');
        await frames(t, 3_000, 4_000, () => ({ angled: 0.9 }));
        assert.equal(t.recorded.length, 0);
        await frames(t, 4_200, 6_600, (at) => ({ angled: at >= 5_600 ? 0.9 : 0 }));
        assert.deepEqual(t.recorded.map((event) => event.clientMs), [OFFSET + 5_600]);
    },

    async 'an answer captured before the detector began to look (a pause, a reset) never reaches the round\'s machine'() {
        const t = page();
        await running(t);
        await frames(t, 200, 1_000, () => ({ angled: 0 }));
        t.clock.set(1_200);
        assert.notEqual(t.grabber.frame(1_200), null);

        // The camera drops out and comes back: the detector stops looking, then looks again from 1 500
        t.detection.plan(input({ screen: 'running', ready: true, cameraLive: false }));
        t.clock.set(1_500);
        t.detection.plan(input({ screen: 'running', ready: true }));
        answerLast(t, 0);
        assert.equal(t.detection.counts.stale, 1);
        assert.equal(t.detection.machine.state().frames, 5, 'only the frames before the pause');
    },

    async '20 candidates hold no stills afterwards - each session lets its stills go once the photo is chosen'() {
        const t = page();
        await running(t);
        const sessions = [];
        let at = 200;

        for (let cycle = 0; cycle < 20; cycle++) {
            // Down 1 s, up 1 s (a candidate), down past the cooldown
            const raiseAt = at + 1_000;
            await frames(t, at, raiseAt + 1_000, (moment) => ({ angled: moment >= raiseAt ? 0.9 : 0 }));
            assert.equal(t.recorded.length, cycle + 1, `candidate ${cycle + 1}`);
            sessions.push(...t.detection.sessions.values());

            for (let still = 0; still < CANDIDATE_STILLS; still++) {
                const resolve = t.grabber.stills.at(-1);
                resolve({ blob: { size: 2_000_000 }, captureMs: OFFSET + raiseAt + 700 + still * STILL_SPACING_MS, sharpness: still });
                await t.clock.advance(STILL_SPACING_MS);
            }

            at = raiseAt + 10_400;
        }

        assert.equal(t.photos.length, 20);
        assert.equal(t.detection.sessions.size, 0, 'no session kept');
        assert.equal(new Set(sessions).size, 20);
        assert.ok(sessions.every((session) => session.done && session.stills.length === 0), 'no session holds a still');
    },

    async 'a mode switch ends every photo session: its timers cancelled, the best still so far is the photo'() {
        const t = page();
        await running(t);
        const scheduled = [];
        const schedule = t.detection.schedule;
        t.detection.schedule = (task, ms) => {
            const timer = schedule(task, ms);
            scheduled.push(timer);

            return timer;
        };

        await frames(t, 200, 1_600, (at) => ({ angled: at >= 1_000 ? 0.9 : 0 }));
        assert.equal(t.recorded.length, 1);
        t.grabber.stills[0]({ blob: { size: 1 }, captureMs: OFFSET + 1_640, sharpness: 9 });
        await settle();
        assert.equal(scheduled.filter((timer) => !timer.cancelled).length, 1, 'the next still is due');

        // The organiser switches on → shadow while the hands are still up
        t.detection.plan(input({ mode: 'shadow', screen: 'running', ready: true }));
        assert.equal(scheduled.filter((timer) => !timer.cancelled).length, 0, 'no timer left running');
        assert.equal(t.detection.sessions.size, 0);
        assert.equal(t.photos.length, 1, 'the still taken so far is the photo');
        assert.equal(t.photos[0].still.sharpness, 9);
    },

    async 'a bitmap the worker could not take (postMessage threw) is closed - it holds its pixels until then'() {
        const t = page();
        await running(t);
        t.workers[0].postMessage = () => {
            throw new Error('DataCloneError');
        };
        t.clock.set(200);
        const bitmap = t.grabber.frame(200);
        assert.notEqual(bitmap, null);
        assert.equal(bitmap.closed, true);
        assert.equal(t.detection.pacing.stats().inFlight, false, 'the next frame may go');
    },

    async '`shadow` never holds "I\'m ready": ready while it loads - once loaded it looks (angled, untested), detector.changed says so'() {
        const t = page();
        t.detection.plan(input({ mode: 'shadow' }));
        assert.equal(t.detection.state, 'loading');
        assert.equal(t.detection.blocksReady(), false, 'no wait, no skip button');
        t.clock.set(LOAD_SKIP_AFTER_MS + 1);
        t.detection.tick();
        assert.equal(t.paint.shown.get('detectionSkip'), false);
        assert.equal(t.paint.fields.get('detectionCheck'), 'detection_check_loading{"seconds":0}');

        // "I'm ready" now: device.ready says loading
        assert.equal(t.detection.readyDetails().state, 'loading');
        t.detection.plan(input({ mode: 'shadow', screen: 'waiting', ready: true }));
        assert.deepEqual(t.usages, [], 'device.ready told it already');

        // Loaded during the wait: no test by itself (not on the checklist), the round looks with the default view
        t.workers[0].answer({ type: 'ready', view: 'any', models: ['pose', 'gesture'], worker: 'classic', loadMs: 30_000 });
        t.detection.plan(input({ mode: 'shadow', screen: 'waiting', ready: true }));
        assert.equal(t.detection.setUp, null);
        assert.deepEqual(t.usages, [{ state: 'ready', running: true, reason: null, view: 'angled' }], 'the server learns it watches now');
        t.detection.plan(input({ mode: 'shadow', screen: 'running', ready: true }));
        assert.equal(t.detection.run, 'round');
        assert.deepEqual(t.workers[0].of('configure').at(-1).message, { type: 'configure', view: DEFAULT_VIEW });
        assert.equal(t.usages.length, 1, 'once per change');
    },

    async 'a reload of a ready page: what the detector does is told once it is known - loading, then watching'() {
        const t = page();
        t.detection.plan(input({ screen: 'waiting', ready: true }));
        assert.deepEqual(t.usages.map((usage) => usage.reason), ['loading']);
        t.workers[0].answer({ type: 'ready', view: 'any', models: ['pose', 'gesture'], worker: 'classic', loadMs: 500 });
        t.detection.plan(input({ screen: 'waiting', ready: true }));
        assert.deepEqual(t.usages.map((usage) => usage.running), [false, true]);

        // Nothing recorded (no consent yet on this page, a preview): asked again on the next plan
        const silent = page({ recording: false });
        silent.detection.plan(input({ screen: 'waiting', ready: true }));
        silent.detection.plan(input({ screen: 'waiting', ready: true }));
        assert.equal(silent.detection.reportedUsage, null);
    },

    async 'a detector too slow to ever hold a raise: button-only (`slow`) - said, reported, one detector.changed'() {
        const t = page();
        await running(t);
        await frames(t, 200, 1_000, () => ({ angled: 0 }));
        t.detection.plan(input({ screen: 'running', ready: true }));
        t.detection.tick();
        assert.equal(t.paint.shown.get('detectionHint'), true);

        // Answers 2 s apart from here on (inference ~2 s)
        let at = 1_000;
        while (t.detection.stopped === null && at < 1_000 + SLOW_FOR_MS * 2) {
            at += 2_000;
            t.clock.set(at);
            assert.notEqual(t.grabber.frame(at), null, `frame at ${at}`);
            answerLast(t, 0, { inferenceMs: 1_950 });
            t.detection.plan(input({ screen: 'running', ready: true }));
        }

        assert.equal(t.detection.stopped, 'slow');
        assert.ok(at - 1_000 >= SLOW_FOR_MS && at - 1_000 <= SLOW_FOR_MS + 2_000, `after ~10 s (${at - 1_000} ms)`);
        assert.equal(t.detection.run, 'off');
        assert.equal(t.grabber.isFeeding(), false);
        assert.equal(t.workers[0].terminated, false, 'a slow worker works - it is kept, nothing more is sent');
        assert.deepEqual(t.detection.telemetry(), { ok: false, fps: null, mode: 'on', state: 'off', inferenceMs: null, view: null });
        // (this page was ready without device.ready - its first plan told the server it watches)
        assert.deepEqual(t.usages, [{ state: 'ready', running: true, reason: null, view: 'angled' }, { state: 'ready', running: false, reason: 'slow', view: null }]);
        t.detection.tick();
        assert.equal(t.paint.shown.get('detectionHint'), false);
        assert.equal(t.paint.shown.get('detectionButtonOnly'), true, '"Tap Finished when your puzzle is complete."');
        t.detection.plan(input({ screen: 'running', ready: true }));
        assert.equal(t.usages.length, 2, 'once');
    },

    async 'a detector that hangs while the camera gives frames: button-only (`stalled`), the worker terminated'() {
        const t = page();
        await running(t);
        await frames(t, 200, 600, () => ({ angled: 0 }));

        // Fresh frames every 100 ms, the worker never answers
        let at = 600;

        while (t.detection.stopped === null && at < 600 + STALL_FOR_MS * 2) {
            at += 100;
            t.clock.set(at);
            t.grabber.frame(at);
        }

        assert.equal(t.detection.stopped, 'stalled');
        assert.ok(at - 700 >= STALL_FOR_MS && at - 700 < STALL_FOR_MS + 200, `after ~10 s of frames (${at - 700} ms)`);
        assert.equal(t.workers[0].terminated, true);
        assert.equal(t.detection.state, 'error');
        t.detection.plan(input({ screen: 'running', ready: true }));
        assert.equal(t.usages.at(-1).reason, 'stalled');
        assert.equal(t.detection.telemetry().state, 'error');
    },

    async 'a frozen camera (no fresh frames) is no stall: the hint becomes "Tap Finished" while nothing is seen, then back'() {
        const t = page();
        await running(t);
        await frames(t, 200, 1_000, () => ({ angled: 0 }));

        // 20 s without a fresh frame (the page drawn every 50 ms meanwhile): nothing offered, nothing stalls
        for (let at = 1_050; at <= 1_000 + WATCH_STALE_MS + 50; at += 50) {
            t.clock.set(at);
            t.detection.plan(input({ screen: 'running', ready: true }));
        }

        t.detection.tick();
        assert.equal(t.paint.shown.get('detectionHint'), false);
        assert.equal(t.paint.shown.get('detectionButtonOnly'), true, 'not watching: the button');

        for (let at = 4_100; at <= 21_000; at += 50) {
            t.clock.set(at);
            t.detection.plan(input({ screen: 'running', ready: true }));
        }

        assert.equal(t.detection.stopped, null, 'a frozen camera is not a broken detector');

        // It thaws: answers again - the hint is back; the machine saw a gap (unarmed: hands up now are no candidate)
        await frames(t, 21_000, 22_000, () => ({ angled: 0.9 }));
        t.detection.plan(input({ screen: 'running', ready: true }));
        t.detection.tick();
        assert.equal(t.paint.shown.get('detectionHint'), true);
        assert.equal(t.detection.machine.state().armed, false);
        assert.equal(t.recorded.length, 0);
    },

    async 'the watch: slow needs a sustained stretch, one long interval is a gap; the stall counts only frames offered'() {
        const watch = createDetectorWatch();
        watch.start(0);
        assert.equal(watch.watching(100), true, 'just began to look');
        assert.equal(watch.watching(WATCH_STALE_MS), false);

        // A freeze: one interval of 12 s, then normal - not slow
        assert.equal(watch.answer(1_000, 1_100), null);
        assert.equal(watch.answer(13_000, 13_100), null);
        assert.equal(watch.answer(13_200, 13_300), null);

        // Every answer 1.6 s after the last: slow once 10 s of them (at least 3) passed
        let capture = 13_200;
        let verdict = null;
        let count = 0;

        while (verdict === null) {
            capture += 1_600;
            count += 1;
            verdict = watch.answer(capture, capture + 1_500);
        }

        assert.equal(verdict, 'slow');
        assert.equal(count, 7, '7 × 1.6 s = 11.2 s');

        // The stall: fresh frames for 10 s without an answer; a pause in the frames (a hidden page) starts it again
        const stall = createDetectorWatch();
        stall.start(0);

        for (let at = 0; at < 6_000; at += 100) {
            stall.frame(at);
        }

        assert.equal(stall.check(6_000), null);

        for (let at = 6_000 + WATCH_STALE_MS + 1; at < 16_000; at += 100) {
            stall.frame(at);
        }

        assert.equal(stall.check(16_000), null, 'counted from after the pause (9 001)');

        for (let at = 16_001; at <= 19_001; at += 100) {
            stall.frame(at);
        }

        assert.equal(stall.check(19_001), 'stalled');
        stall.answer(19_000, 19_200);
        assert.equal(stall.check(19_200), null, 'an answer clears it');
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
