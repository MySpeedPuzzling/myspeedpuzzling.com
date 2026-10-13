// Runs the finish detector's pure parts through their scenarios and prints {scenario: "ok" | error} as JSON -
// tests/OnlineHandsUpScriptTest.php asserts every one is "ok":
// - assets/online/hands_up_signal.js: pose landmarks / gesture results → {raised, score, reason}, mirrored cameras,
//   visibility, scale, the views;
// - assets/online/detector_pacing.js: one frame in flight, 4-5 fps, backing off by the inference time, stalls;
// - assets/online/detector.worker.js: its message protocol (createDetectorHost) against a fake MediaPipe - init,
//   frames, errors as messages, bitmaps always closed, ever increasing timestamps, dispose.

import assert from 'node:assert/strict';
import {
    aspectOf,
    compactHands,
    compactPose,
    DEFAULT_GESTURE_OPTIONS,
    DEFAULT_POSE_OPTIONS,
    frameSignal,
    gestureSignal,
    mirrorPose,
    POSE,
    POSE_LANDMARKS,
    poseSignal,
    REASON_HANDS_DOWN,
    REASON_LOW_VISIBILITY,
    REASON_NO_HANDS,
    REASON_NO_PALM,
    REASON_NO_PERSON,
    REASON_ONE_HAND,
    REASON_ONE_PALM,
    REASON_RAISED,
    REASON_SAME_HAND,
    REASON_TOO_SMALL,
    VIEW_ANGLED,
    VIEW_ANY,
    VIEW_TOP_DOWN,
} from '../assets/online/hands_up_signal.js';
import { createDetectorPacing, MAX_DUTY, TARGET_FPS } from '../assets/online/detector_pacing.js';
import { createDetectorHost, isModuleWorker, modelsFor, pickPose, workerHasWebGl2 } from '../assets/online/detector.worker.js';

const WIDE = 16 / 9;

/**
 * A person facing the camera in a 16:9 frame (compact pose: [x, y, visibility] × 33): shoulders at y 0.40, hips at
 * 0.70, hands down at the sides unless `wrists` say otherwise.
 */
function person({
    wrists = [[0.40, 0.66], [0.60, 0.66]],
    shoulders = [[0.42, 0.40], [0.58, 0.40]],
    hips = [[0.45, 0.70], [0.55, 0.70]],
    visibility = 0.95,
    wristVisibility = visibility,
    shoulderVisibility = visibility,
    hipVisibility = visibility,
} = {}) {
    const pose = Array.from({ length: POSE_LANDMARKS }, () => [0.5, 0.3, visibility]);
    pose[POSE.NOSE] = [0.5, 0.28, visibility];
    pose[POSE.LEFT_SHOULDER] = [...shoulders[0], shoulderVisibility];
    pose[POSE.RIGHT_SHOULDER] = [...shoulders[1], shoulderVisibility];
    pose[POSE.LEFT_WRIST] = [...wrists[0], wristVisibility];
    pose[POSE.RIGHT_WRIST] = [...wrists[1], wristVisibility];
    const middle = (a, b) => Math.round(((a + b) / 2) * 10_000) / 10_000;
    pose[POSE.LEFT_ELBOW] = [middle(shoulders[0][0], wrists[0][0]), middle(shoulders[0][1], wrists[0][1]), visibility];
    pose[POSE.RIGHT_ELBOW] = [middle(shoulders[1][0], wrists[1][0]), middle(shoulders[1][1], wrists[1][1]), visibility];
    pose[POSE.LEFT_HIP] = [...hips[0], hipVisibility];
    pose[POSE.RIGHT_HIP] = [...hips[1], hipVisibility];

    return pose;
}

const handsUp = (options = {}) => person({ wrists: [[0.40, 0.12], [0.60, 0.12]], ...options });

function hand(gesture, gestureScore, wrist = [0.3, 0.5], handedness = 'Left') {
    return {
        handedness,
        handednessScore: 0.9,
        gesture,
        gestureScore,
        landmarks: Array.from({ length: 21 }, (_, index) => (index === 0 ? wrist : [wrist[0] + 0.01, wrist[1] - 0.02])),
    };
}

/**
 * A fake ImageBitmap that remembers being closed.
 */
function bitmap(width = 1280, height = 720) {
    return {
        width,
        height,
        closed: false,
        close() {
            this.closed = true;
        },
    };
}

const TASKS_URL = 'https://cdn.example/tasks-vision@1.1.0/vision_bundle.mjs';
const WASM_BASE = 'https://cdn.example/tasks-vision@1.1.0/wasm';
const POSE_URL = 'https://models.example/pose_landmarker_lite/float16/1/pose_landmarker_lite.task';
const GESTURE_URL = 'https://models.example/gesture_recognizer/float16/1/gesture_recognizer.task';
const INIT = { type: 'init', tasksVisionUrl: TASKS_URL, wasmBaseUrl: WASM_BASE, poseModelUrl: POSE_URL, gestureModelUrl: GESTURE_URL };

/**
 * MediaPipe as the worker sees it: the tasks-vision module, the WASM loader module (whose default export is the
 * ModuleFactory MediaPipe reads from the global scope - and clears after each task, as the real one does).
 */
function fakeMediaPipe({ failModel = null, throwOnFrame = false, poses = null, gestures = null, classic = false } = {}) {
    const scope = {};
    const calls = { imports: [], filesets: [], created: [], closed: [], timestamps: [] };
    const loader = { default: function ModuleFactory() {} };
    let clock = 0;

    const task = (kind, options) => ({
        kind,
        options,
        detectForVideo(image, timestamp) {
            calls.timestamps.push(timestamp);
            clock += 12;

            if (throwOnFrame) {
                throw new Error('Packet timestamp mismatch');
            }

            return { landmarks: poses ?? [handsUp().map(([x, y, visibility]) => ({ x, y, z: 0, visibility }))] };
        },
        recognizeForVideo(image, timestamp) {
            calls.timestamps.push(timestamp);
            clock += 9;

            return gestures ?? { landmarks: [], handedness: [], gestures: [] };
        },
        close() {
            calls.closed.push(kind);
        },
    });

    const creator = (kind, url) => ({
        async createFromOptions(fileset, options) {
            if (classic) {
                // A classic worker: MediaPipe importScripts() the classic loader, which sets the global itself
                assert.equal(scope.ModuleFactory, undefined, 'nothing is set for a classic worker');
                scope.ModuleFactory = loader.default;
            }

            assert.equal(scope.ModuleFactory, loader.default, `ModuleFactory is set before the ${kind} task`);
            // As MediaPipe does after creating a task
            scope.ModuleFactory = undefined;

            if (failModel === kind) {
                throw new Error(`Failed to fetch ${url}`);
            }

            calls.created.push({ kind, fileset, options });

            return task(kind, options);
        },
    });

    const vision = {
        FilesetResolver: {
            async forVisionTasks(base, useModule) {
                calls.filesets.push([base, useModule]);

                return { wasmLoaderPath: `${base}/vision_wasm_module_internal.js`, wasmBinaryPath: `${base}/vision_wasm_module_internal.wasm` };
            },
        },
        PoseLandmarker: creator('pose', POSE_URL),
        GestureRecognizer: creator('gesture', GESTURE_URL),
    };

    return {
        scope,
        calls,
        now: () => clock,
        async importModule(url) {
            calls.imports.push(url);

            if (url === TASKS_URL) {
                return vision;
            }

            if (url === `${WASM_BASE}/vision_wasm_module_internal.js`) {
                return loader;
            }

            throw new TypeError(`Failed to fetch dynamically imported module: ${url}`);
        },
    };
}

function host(options = {}) {
    const mediaPipe = fakeMediaPipe(options);
    const messages = [];
    const detector = createDetectorHost({
        post: (message) => messages.push(message),
        importModule: mediaPipe.importModule,
        now: mediaPipe.now,
        scope: mediaPipe.scope,
        hasWebGl2: () => options.webGl2 ?? true,
        moduleWorker: () => !options.classic,
    });

    return { detector, messages, mediaPipe, last: () => messages[messages.length - 1] };
}

const scenarios = {
    // ---- hands_up_signal.js: the pose (angled view)

    'both wrists well above the shoulders: raised, a clear score'() {
        const signal = poseSignal(handsUp(), { aspect: WIDE });

        assert.equal(signal.raised, true);
        assert.equal(signal.reason, REASON_RAISED);
        // 0.28 frame heights above the shoulder line / 0.284 shoulder width (0.16 × 16/9)
        assert.ok(signal.lift.every((lift) => lift > 0.9 && lift < 1.05), JSON.stringify(signal.lift));
        assert.ok(signal.score > 0.85 && signal.score <= 0.95, `score ${signal.score}`);
    },

    'hands at the sides, one hand up, hands at the collar: not raised - each with its reason'() {
        assert.deepEqual(
            [poseSignal(person(), { aspect: WIDE }).raised, poseSignal(person(), { aspect: WIDE }).reason],
            [false, REASON_HANDS_DOWN],
        );

        const one = poseSignal(person({ wrists: [[0.40, 0.12], [0.60, 0.66]] }), { aspect: WIDE });
        assert.deepEqual([one.raised, one.reason], [false, REASON_ONE_HAND]);

        // Scratching the neck: wrists just above the shoulders (0.04 frame heights = 0.14 shoulder widths)
        const collar = poseSignal(person({ wrists: [[0.45, 0.36], [0.55, 0.36]] }), { aspect: WIDE });
        assert.deepEqual([collar.raised, collar.reason], [false, REASON_HANDS_DOWN]);
        assert.equal(poseSignal(person({ wrists: [[0.45, 0.36], [0.55, 0.36]] }), { aspect: WIDE, minLift: 0.1 }).raised, true, 'minLift is the knob');
    },

    'a mirrored front camera gives the same answer - the rule never pairs a wrist with its own shoulder'() {
        const cases = [
            handsUp(),
            person(),
            person({ wrists: [[0.40, 0.12], [0.60, 0.66]] }),
            // A tilted body: the right shoulder higher, the left wrist just above the left shoulder only
            person({ shoulders: [[0.42, 0.44], [0.58, 0.36]], wrists: [[0.38, 0.40], [0.62, 0.10]] }),
            handsUp({ wristVisibility: 0.4 }),
        ];

        for (const pose of cases) {
            assert.deepEqual(poseSignal(mirrorPose(pose), { aspect: WIDE }), poseSignal(pose, { aspect: WIDE }));
        }

        // The tilted body really is another set of landmarks mirrored - its left wrist now carries the right one
        const tilted = cases[3];
        assert.notDeepEqual(mirrorPose(tilted), tilted);
        assert.deepEqual(mirrorPose(tilted)[POSE.LEFT_WRIST], [0.38, 0.10, 0.95]);
        assert.equal(poseSignal(tilted, { aspect: WIDE }).reason, REASON_ONE_HAND);

        assert.deepEqual(mirrorPose(mirrorPose(handsUp())), handsUp(), 'mirroring twice gives the pose back');
    },

    'visibility: a hidden wrist or shoulder is not believed'() {
        const wrists = poseSignal(handsUp({ wristVisibility: 0.3 }), { aspect: WIDE });
        assert.deepEqual([wrists.raised, wrists.reason], [false, REASON_LOW_VISIBILITY]);
        assert.ok(Array.isArray(wrists.lift), 'the lift is still told (the bench shows it)');

        const shoulders = poseSignal(handsUp({ shoulderVisibility: 0.2 }), { aspect: WIDE });
        assert.deepEqual([shoulders.raised, shoulders.reason], [false, REASON_LOW_VISIBILITY]);

        assert.equal(poseSignal(handsUp({ wristVisibility: 0.3 }), { aspect: WIDE, minVisibility: 0.25 }).raised, true);

        // The score carries the visibility
        assert.ok(poseSignal(handsUp({ wristVisibility: 0.6 }), { aspect: WIDE }).score < poseSignal(handsUp(), { aspect: WIDE }).score);
    },

    'no person, a person too small, nonsense: not raised'() {
        for (const nothing of [null, undefined, [], person().slice(0, 20), 'pose']) {
            assert.deepEqual(poseSignal(nothing), { raised: false, score: 0, reason: REASON_NO_PERSON, lift: null });
        }

        const broken = handsUp();
        broken[POSE.LEFT_WRIST] = [Number.NaN, 0.1, 0.9];
        assert.equal(poseSignal(broken).reason, REASON_NO_PERSON);

        const tiny = person({ shoulders: [[0.500, 0.40], [0.505, 0.40]], hipVisibility: 0.1, wrists: [[0.49, 0.3], [0.51, 0.3]] });
        assert.equal(poseSignal(tiny, { aspect: WIDE }).reason, REASON_TOO_SMALL);
    },

    'the scale is the person: near or far, the same verdict; side-on the torso takes over'() {
        // The same raise, half the size, elsewhere in the frame
        const far = person({
            shoulders: [[0.71, 0.40], [0.79, 0.40]],
            hips: [[0.725, 0.55], [0.775, 0.55]],
            wrists: [[0.70, 0.26], [0.80, 0.26]],
        });
        const near = handsUp();
        assert.equal(poseSignal(far, { aspect: WIDE }).raised, true);
        assert.ok(Math.abs(poseSignal(far, { aspect: WIDE }).lift[0] - poseSignal(near, { aspect: WIDE }).lift[0]) < 0.02);

        // The aspect ratio: x is a fraction of the width - ignoring it makes the shoulders look narrow and every lift huge
        assert.ok(poseSignal(handsUp(), { aspect: 1 }).lift[0] > poseSignal(handsUp(), { aspect: WIDE }).lift[0] * 1.5);

        // Side-on: the shoulders overlap, half the torso is the scale
        const side = person({ shoulders: [[0.50, 0.40], [0.51, 0.41]], hips: [[0.50, 0.70], [0.51, 0.70]], wrists: [[0.49, 0.30], [0.52, 0.30]] });
        const signal = poseSignal(side, { aspect: WIDE });
        assert.equal(signal.raised, true);
        assert.ok(signal.lift[0] > 0.6 && signal.lift[0] < 0.7, `0.10 / 0.15 → ${signal.lift[0]}`);
    },

    'the score grows with the height of the lower wrist, capped at fullLift'() {
        const at = (y) => poseSignal(person({ wrists: [[0.4, y], [0.6, 0.05]] }), { aspect: WIDE, fullLift: 1 }).score;

        assert.ok(at(0.30) < at(0.20));
        assert.ok(at(0.20) < at(0.12));
        assert.equal(at(0.05), at(0.0), 'saturated');
        assert.equal(poseSignal(handsUp(), { aspect: WIDE, fullLift: 4 }).score < 0.3, true, 'fullLift 4 shoulder widths');
        assert.equal(DEFAULT_POSE_OPTIONS.minLift, 0.25);
        assert.equal(DEFAULT_POSE_OPTIONS.minVisibility, 0.5);
    },

    // ---- hands_up_signal.js: the gestures (top-down view)

    'two open palms apart: raised, scored by the weaker one'() {
        const signal = gestureSignal([hand('Open_Palm', 0.92, [0.3, 0.5]), hand('Open_Palm', 0.71, [0.7, 0.5], 'Right')], { aspect: WIDE });

        assert.deepEqual([signal.raised, signal.score, signal.reason, signal.palms], [true, 0.71, REASON_RAISED, 2]);
    },

    'gestures: no hands, one palm, fists, a weak palm, one hand seen twice - not raised'() {
        assert.equal(gestureSignal([]).reason, REASON_NO_HANDS);
        assert.equal(gestureSignal(null).reason, REASON_NO_HANDS);
        assert.equal(gestureSignal([hand('Open_Palm', 0.9)]).reason, REASON_ONE_PALM);
        assert.equal(gestureSignal([hand('Closed_Fist', 0.9), hand('Victory', 0.8, [0.7, 0.5])]).reason, REASON_NO_PALM);
        assert.equal(gestureSignal([hand('Open_Palm', 0.9), hand('Open_Palm', 0.4, [0.7, 0.5])]).reason, REASON_ONE_PALM, 'below minGestureScore');
        assert.equal(gestureSignal([hand('Open_Palm', 0.9, [0.5, 0.5]), hand('Open_Palm', 0.8, [0.51, 0.5])]).reason, REASON_SAME_HAND);

        // The weak palm counts with a lower threshold
        assert.equal(gestureSignal([hand('Open_Palm', 0.9), hand('Open_Palm', 0.4, [0.7, 0.5])], { minGestureScore: 0.35 }).raised, true);
        assert.equal(DEFAULT_GESTURE_OPTIONS.minGestureScore, 0.6);
    },

    'gestures ignore handedness (a mirrored camera labels the hands the other way)'() {
        const bothLeft = gestureSignal([hand('Open_Palm', 0.9, [0.3, 0.5], 'Left'), hand('Open_Palm', 0.8, [0.7, 0.5], 'Left')]);
        assert.equal(bothLeft.raised, true);

        // Three hands (someone else's in view): the best two that lie apart
        const three = gestureSignal([
            hand('Open_Palm', 0.95, [0.5, 0.5]),
            hand('Open_Palm', 0.9, [0.505, 0.5]),
            hand('Open_Palm', 0.7, [0.8, 0.5]),
        ]);
        assert.deepEqual([three.raised, three.score], [true, 0.7]);
    },

    // ---- hands_up_signal.js: views and the compact form

    'frameSignal: the chosen view decides; "any" takes whichever model sees the raise'() {
        const landmarks = { pose: person(), hands: [hand('Open_Palm', 0.9), hand('Open_Palm', 0.8, [0.7, 0.5])] };

        const angled = frameSignal(landmarks, { view: VIEW_ANGLED, aspect: WIDE });
        assert.deepEqual([angled.raised, angled.reason, angled.view], [false, REASON_HANDS_DOWN, VIEW_ANGLED]);
        assert.equal(angled.topDown, undefined);

        const topDown = frameSignal(landmarks, { view: VIEW_TOP_DOWN, aspect: WIDE });
        assert.deepEqual([topDown.raised, topDown.score, topDown.view], [true, 0.8, VIEW_TOP_DOWN]);
        assert.equal(topDown.angled, undefined);

        const any = frameSignal(landmarks, { view: VIEW_ANY, aspect: WIDE });
        assert.deepEqual([any.raised, any.score], [true, 0.8]);
        assert.equal(any.angled.raised, false);
        assert.equal(any.topDown.raised, true);

        const neither = frameSignal({ pose: person(), hands: [] }, { view: VIEW_ANY, aspect: WIDE });
        assert.equal(neither.raised, false);

        // Unknown view: angled; thresholds per model
        assert.equal(frameSignal({ pose: handsUp() }, { view: 'sideways', aspect: WIDE }).view, VIEW_ANGLED);
        assert.equal(frameSignal({ pose: person({ wrists: [[0.45, 0.36], [0.55, 0.36]] }) }, { aspect: WIDE, pose: { minLift: 0.1 } }).raised, true);
        assert.equal(frameSignal({}, { view: VIEW_TOP_DOWN }).reason, REASON_NO_HANDS);
        assert.equal(frameSignal(null).reason, REASON_NO_PERSON);
    },

    'compactPose / compactHands: MediaPipe results in a few numbers, rounded'() {
        const landmarks = Array.from({ length: 33 }, (_, index) => ({ x: index / 100 + 0.123456, y: 0.987654, z: -0.3, visibility: 0.99987 }));
        const compact = compactPose(landmarks);

        assert.equal(compact.length, 33);
        assert.deepEqual(compact[0], [0.1235, 0.9877, 1]);
        assert.equal(compactPose(landmarks.slice(0, 10)), null);
        assert.equal(compactPose(null), null);

        const hands = compactHands({
            landmarks: [Array.from({ length: 21 }, () => ({ x: 0.111111, y: 0.222222, z: 0 }))],
            handedness: [[{ categoryName: 'Right', score: 0.98765, index: 1 }]],
            gestures: [[{ categoryName: 'Open_Palm', score: 0.87654, index: -1 }]],
        });
        assert.deepEqual(hands[0].landmarks[0], [0.1111, 0.2222]);
        assert.deepEqual([hands[0].handedness, hands[0].handednessScore, hands[0].gesture, hands[0].gestureScore], ['Right', 0.988, 'Open_Palm', 0.877]);

        // The deprecated `handednesses`, a hand without a gesture, nothing at all
        const older = compactHands({ landmarks: [[{ x: 0.1, y: 0.2 }]], handednesses: [[{ categoryName: 'Left', score: 0.5 }]], gestures: [] });
        assert.deepEqual([older[0].handedness, older[0].gesture, older[0].gestureScore], ['Left', null, 0]);
        assert.deepEqual(compactHands(null), []);
        assert.equal(aspectOf(1920, 1080), 16 / 9);
        assert.equal(aspectOf(0, 1080), 1);
    },

    // ---- detector_pacing.js

    'pacing: a 30 fps camera and a fast detector - 5 frames a second, never two in flight'() {
        const pacing = createDetectorPacing();
        let sent = 0;
        let inFlightAt = null;

        for (let frame = 0; frame < 90; frame++) {
            const now = frame * (1000 / 30);

            if (inFlightAt !== null && now - inFlightAt >= 20) {
                pacing.answered(20);
                inFlightAt = null;
            }

            if (pacing.wants(now)) {
                assert.equal(inFlightAt, null, 'one in flight');
                pacing.sent(now);
                inFlightAt = now;
                sent += 1;
            }
        }

        assert.ok(sent >= 14 && sent <= 16, `${sent} frames in 3 s`);
        assert.equal(pacing.stats().fps, TARGET_FPS);
        assert.equal(pacing.stats().inferenceMs, 20);
        assert.equal(pacing.stats().slow, false);
    },

    'pacing backs off by the inference time - the detector busy at most half the time'() {
        const pacing = createDetectorPacing({ smoothing: 1 });
        pacing.sent(0);
        pacing.answered(150);

        assert.equal(pacing.intervalMs(), 150 / MAX_DUTY);
        assert.equal(pacing.stats().fps, 3.3);
        assert.equal(pacing.wants(299), false);
        assert.equal(pacing.wants(300), true, 'counted from the send');

        // Smoothed: one slow frame moves the interval by 30 % of the difference
        const smooth = createDetectorPacing();
        smooth.sent(0);
        smooth.answered(100);
        smooth.sent(200);
        smooth.answered(200);
        assert.equal(smooth.stats().inferenceMs, 130);
        assert.equal(smooth.intervalMs(), 260);

        // Faster again: back to the target
        const recovering = createDetectorPacing({ smoothing: 1 });
        recovering.sent(0);
        recovering.answered(400);
        recovering.sent(800);
        recovering.answered(30);
        assert.equal(recovering.intervalMs(), 200);
    },

    'pacing never goes below 1 fps - a detector slower than that is reported slow'() {
        const pacing = createDetectorPacing({ smoothing: 1 });
        pacing.sent(0);
        pacing.answered(800);

        assert.equal(pacing.intervalMs(), 1000);
        assert.equal(pacing.stats().fps, 1);
        assert.equal(pacing.stats().slow, true);

        const fine = createDetectorPacing({ smoothing: 1 });
        fine.sent(0);
        fine.answered(450);
        assert.equal(fine.stats().slow, false, '450 ms fits 1 fps at half duty');
    },

    'pacing: a lost answer is given up after stallMs, a late answer to it ignored; an error waits one interval'() {
        const pacing = createDetectorPacing({ stallMs: 3000 });
        pacing.sent(0, 'frame-1');

        assert.equal(pacing.wants(2999), false);
        assert.equal(pacing.wants(3000), true);
        assert.equal(pacing.stats().stalls, 1);

        pacing.sent(3000, 'frame-2');
        pacing.answered(5, 'frame-1');
        assert.equal(pacing.stats().late, 1);
        assert.equal(pacing.stats().inFlight, true, 'frame-2 still waits');
        assert.equal(pacing.stats().inferenceMs, null);

        pacing.failed(3100, 'frame-2');
        assert.equal(pacing.stats().failed, 1);
        assert.equal(pacing.wants(3299), false);
        assert.equal(pacing.wants(3300), true);

        assert.equal(pacing.wants(Number.NaN), false);
        pacing.reset();
        assert.deepEqual([pacing.stats().sent, pacing.stats().failed, pacing.stats().stalls], [0, 0, 0]);
    },

    'pacing: invalid options fall back to the defaults, minFps never above targetFps'() {
        const pacing = createDetectorPacing({ targetFps: -2, minFps: 'x', maxDuty: 0, smoothing: Number.NaN, stallMs: null });
        assert.equal(pacing.intervalMs(), 200);

        const odd = createDetectorPacing({ targetFps: 2, minFps: 4 });
        assert.equal(odd.minFps, 2);
        assert.equal(odd.intervalMs(), 500);
    },

    // ---- detector.worker.js (createDetectorHost) against a fake MediaPipe

    'worker init "angled": the pose model only, CPU, VIDEO, the module build of the WASM loader → ready'() {
        const { detector, messages, mediaPipe } = host();

        return detector.handle({ ...INIT, view: VIEW_ANGLED }).then(() => {
            assert.deepEqual(messages.map((message) => message.type), ['ready']);
            assert.deepEqual(messages[0].models, ['pose']);
            assert.equal(messages[0].view, VIEW_ANGLED);
            assert.deepEqual(mediaPipe.calls.filesets, [[WASM_BASE, true]], 'a module worker takes the ES-module WASM loader');
            assert.deepEqual(mediaPipe.calls.imports, [TASKS_URL, `${WASM_BASE}/vision_wasm_module_internal.js`]);
            assert.equal(messages[0].worker, 'module');
            assert.deepEqual(mediaPipe.calls.created.map((task) => task.kind), ['pose']);
            assert.deepEqual(mediaPipe.calls.created[0].options.baseOptions, { modelAssetPath: POSE_URL, delegate: 'CPU' });
            assert.equal(mediaPipe.calls.created[0].options.runningMode, 'VIDEO');
            assert.equal(mediaPipe.calls.created[0].options.numPoses, 1);
            assert.deepEqual(modelsFor(VIEW_TOP_DOWN), ['gesture']);
            assert.deepEqual(modelsFor('nope'), []);
        });
    },

    async 'worker init in a classic worker (what Encore bundles): the classic WASM loader, nothing pre-set'() {
        const { detector, messages, mediaPipe } = host({ classic: true });
        await detector.handle({ ...INIT, view: VIEW_ANY });

        assert.equal(messages[0].type, 'ready', JSON.stringify(messages[0]));
        assert.equal(messages[0].worker, 'classic');
        assert.deepEqual(mediaPipe.calls.filesets, [[WASM_BASE, false]]);
        assert.deepEqual(mediaPipe.calls.imports, [TASKS_URL], 'the loader is left to MediaPipe');
        assert.deepEqual(mediaPipe.calls.created.map((task) => task.kind), ['pose', 'gesture']);

        // The probe: importScripts() with nothing to load throws only in a module worker
        assert.equal(isModuleWorker({ importScripts() {} }), false);
        assert.equal(isModuleWorker({ importScripts() { throw new TypeError('Module scripts don\'t support importScripts().'); } }), true);
        assert.equal(isModuleWorker({}), true);
    },

    async 'worker init "any": both models - ModuleFactory set again before the second (MediaPipe clears it)'() {
        const { detector, messages, mediaPipe } = host();
        await detector.handle({ ...INIT, view: VIEW_ANY, numHands: 2 });

        assert.equal(messages[0].type, 'ready', JSON.stringify(messages[0]));
        assert.deepEqual(mediaPipe.calls.created.map((task) => task.kind), ['pose', 'gesture']);
        assert.equal(mediaPipe.calls.created[1].options.numHands, 2);
        assert.equal(mediaPipe.calls.created[1].options.baseOptions.delegate, 'CPU');
    },

    async 'worker frames: compact landmarks + the signal + the inference time; the capture time passes through; bitmaps closed'() {
        const { detector, messages } = host();
        const early = bitmap();
        detector.handle({ type: 'frame', bitmap: early, captureTimeMs: 100, frameId: 1 });
        assert.deepEqual([messages[0].type, messages[0].reason, messages[0].frameId], ['skipped', 'not_ready', 1]);
        assert.equal(early.closed, true, 'closed although skipped');

        await detector.handle({ ...INIT, view: VIEW_ANY });
        const frame = bitmap(1280, 720);
        await detector.handle({ type: 'frame', bitmap: frame, captureTimeMs: 1_792_000_000_123.4, frameId: 2 });
        const result = messages[messages.length - 1];

        assert.equal(result.type, 'result', JSON.stringify(result));
        assert.equal(result.frameId, 2);
        assert.equal(result.captureTimeMs, 1_792_000_000_123.4);
        assert.deepEqual([result.width, result.height, result.poses], [1280, 720, 1]);
        assert.equal(result.landmarks.pose.length, 33);
        assert.deepEqual(result.landmarks.hands, []);
        assert.equal(result.signal.raised, true);
        assert.equal(result.signal.view, VIEW_ANY);
        assert.equal(result.signal.angled.raised, true);
        assert.equal(result.signal.topDown.reason, REASON_NO_HANDS);
        assert.deepEqual(result.timings, { poseMs: 12, gestureMs: 9 });
        assert.equal(result.inferenceMs, 21);
        assert.equal(frame.closed, true);
    },

    async 'worker timestamps for MediaPipe only ever increase - repeated or older capture times, a restart'() {
        const { detector, mediaPipe } = host();
        await detector.handle({ ...INIT, view: VIEW_ANGLED });

        for (const captureTimeMs of [5000, 5200, 5200, 5100, 5600]) {
            detector.handle({ type: 'frame', bitmap: bitmap(), captureTimeMs });
        }

        assert.deepEqual(mediaPipe.calls.timestamps, [1, 201, 202, 203, 601]);

        // The bench analyses the video again from 0
        detector.handle({ type: 'restart' });
        detector.handle({ type: 'frame', bitmap: bitmap(), captureTimeMs: 0 });
        detector.handle({ type: 'frame', bitmap: bitmap(), captureTimeMs: 100 });
        assert.deepEqual(mediaPipe.calls.timestamps.slice(5), [602, 702]);
    },

    async 'worker errors are messages: a failing frame, a failing model (then a retry), a second init, nonsense'() {
        const failing = host({ throwOnFrame: true });
        await failing.detector.handle({ ...INIT, view: VIEW_ANGLED });
        const frame = bitmap();
        failing.detector.handle({ type: 'frame', bitmap: frame, captureTimeMs: 10, frameId: 'f' });
        assert.deepEqual(
            [failing.last().type, failing.last().phase, failing.last().frameId, failing.last().message],
            ['error', 'frame', 'f', 'Packet timestamp mismatch'],
        );
        assert.equal(frame.closed, true);

        const broken = host({ failModel: 'gesture' });
        await broken.detector.handle({ ...INIT, view: VIEW_ANY });
        assert.deepEqual([broken.last().type, broken.last().phase], ['error', 'init']);
        assert.match(broken.last().message, /Failed to fetch/);
        assert.deepEqual(broken.mediaPipe.calls.closed, ['pose'], 'the half-made detector is closed');
        assert.equal(broken.detector.state.status, 'failed');

        // A retry may succeed (the model came back)
        const retry = host();
        retry.detector.state.status = 'failed';
        await retry.detector.handle({ ...INIT, view: VIEW_ANGLED });
        assert.equal(retry.last().type, 'ready');

        await retry.detector.handle({ ...INIT, view: VIEW_ANGLED });
        assert.deepEqual([retry.last().type, retry.last().name], ['error', 'InvalidStateError'], 'one detector per session');

        const bad = host();
        await bad.detector.handle({ ...INIT, view: 'sideways' });
        assert.deepEqual([bad.last().type, bad.last().phase], ['error', 'init']);
        await bad.detector.handle({ type: 'init', view: VIEW_ANGLED, tasksVisionUrl: TASKS_URL, wasmBaseUrl: WASM_BASE });
        assert.match(bad.last().message, /Missing URL: pose/);
        const stray = bitmap();
        await bad.detector.handle({ type: 'paint', bitmap: stray });
        assert.deepEqual([bad.last().type, bad.last().phase], ['error', 'message']);
        assert.equal(stray.closed, true);
        await bad.detector.handle(null);
        assert.equal(bad.last().type, 'error');

        const noTasks = host();
        await noTasks.detector.handle({ ...INIT, view: VIEW_ANGLED, tasksVisionUrl: 'https://cdn.example/missing.mjs' });
        assert.deepEqual([noTasks.last().type, noTasks.last().phase, noTasks.last().name], ['error', 'init', 'TypeError']);
    },

    async 'worker configure: another view among the loaded models; dispose closes them, later frames are skipped'() {
        const { detector, messages, mediaPipe, last } = host();
        await detector.handle({ ...INIT, view: VIEW_ANY });

        detector.handle({ type: 'configure', view: VIEW_ANGLED, signal: { pose: { minLift: 0.1 } } });
        assert.deepEqual([last().type, last().view], ['configured', VIEW_ANGLED]);
        detector.handle({ type: 'frame', bitmap: bitmap(), captureTimeMs: 1 });
        assert.equal(last().landmarks.hands, null, 'the gesture model is not run for the angled view');
        assert.equal(last().timings.gestureMs, null);

        const angledOnly = host();
        await angledOnly.detector.handle({ ...INIT, view: VIEW_ANGLED });
        angledOnly.detector.handle({ type: 'configure', view: VIEW_TOP_DOWN });
        assert.deepEqual([angledOnly.last().type, angledOnly.last().name], ['error', 'NotSupportedError']);

        detector.handle({ type: 'dispose' });
        assert.equal(last().type, 'disposed');
        assert.deepEqual(mediaPipe.calls.closed.sort(), ['gesture', 'pose']);
        const after = bitmap();
        detector.handle({ type: 'frame', bitmap: after, captureTimeMs: 2 });
        assert.deepEqual([last().type, last().reason], ['skipped', 'disposed']);
        assert.equal(after.closed, true);
        await detector.handle({ ...INIT, view: VIEW_ANGLED });
        assert.equal(last().type, 'error');
        assert.ok(messages.every((message) => message.type !== 'ready' || message.view === VIEW_ANY));
    },

    async 'worker: disposed while loading - nothing is announced, what was made is closed'() {
        const { detector, messages, mediaPipe } = host();
        const loading = detector.handle({ ...INIT, view: VIEW_ANY });
        detector.handle({ type: 'dispose' });
        await loading;

        assert.deepEqual(messages.map((message) => message.type), ['disposed']);
        assert.deepEqual(mediaPipe.calls.created.map((task) => task.kind).sort(), mediaPipe.calls.closed.sort());
    },

    async 'worker without WebGL2 (MediaPipe needs it even on the CPU): init fails at once, nothing is downloaded'() {
        const { detector, last, mediaPipe } = host({ webGl2: false });
        await detector.handle({ ...INIT, view: VIEW_ANGLED });

        assert.deepEqual([last().type, last().phase, last().name], ['error', 'init', 'NotSupportedError']);
        assert.deepEqual(mediaPipe.calls.imports, []);
        assert.equal(detector.state.status, 'failed');

        // The real check: no OffscreenCanvas, no context, a throwing one
        assert.equal(workerHasWebGl2({}), false);
        assert.equal(workerHasWebGl2({ OffscreenCanvas: class { getContext() { return null; } } }), false);
        assert.equal(workerHasWebGl2({ OffscreenCanvas: class { getContext() { throw new Error('blocked'); } } }), false);
        let lost = false;
        assert.equal(workerHasWebGl2({ OffscreenCanvas: class { getContext() { return { getExtension: () => ({ loseContext: () => { lost = true; } }) }; } } }), true);
        assert.equal(lost, true, 'the probe context is given back');
    },

    'pickPose: of several people, the most prominent one'() {
        const small = [{ x: 0.1, y: 0.1, visibility: 0.9 }, { x: 0.15, y: 0.2, visibility: 0.9 }];
        const big = [{ x: 0.3, y: 0.2, visibility: 0.9 }, { x: 0.7, y: 0.9, visibility: 0.9 }];

        assert.equal(pickPose([small, big]), big);
        assert.equal(pickPose([big]), big);
        assert.equal(pickPose([]), null);
        assert.equal(pickPose(null), null);
    },
};

const results = {};

for (const [name, scenario] of Object.entries(scenarios)) {
    try {
        await scenario();
        results[name] = 'ok';
    } catch (error) {
        results[name] = String(error?.stack ?? error);
    }
}

process.stdout.write(JSON.stringify(results));
