/**
 * The finish detector - a module Web Worker, one instance for the whole session (docs/features/online-competitions/
 * README.md §5.7 "Tech"; implementation-plan.md WP 2.2). MediaPipe's calls are synchronous and block their thread,
 * so they run here, never on the page's.
 *
 * Messages in:
 * - `init` {view: 'angled' | 'topDown' | 'any', tasksVisionUrl, wasmBaseUrl, poseModelUrl, gestureModelUrl,
 *   numPoses = 1, numHands = 2, signal?: {pose?, gesture?} (hands_up_signal.js thresholds)} - loads MediaPipe
 *   tasks-vision by `import()` of a pinned ESM URL (no build step: the same file runs from Encore and from the bench),
 *   the models the view needs (`angled` = pose, `topDown` = gesture, `any` = both - the set-up's test raise and the
 *   bench), CPU delegate (README §5.7: GPU = WebGL2, wrong on iOS), `runningMode: 'VIDEO'`. MediaPipe still uploads
 *   each frame through WebGL2 - a worker without it fails init at once (`NotSupportedError`: button-only). Once per
 *   worker: a second init is refused (creating and disposing detectors runs Safari out of memory, MediaPipe #5036) -
 *   after a failed one it may be retried. Runs as a module worker (the bench) or a classic one (webpack 5 bundles
 *   it as classic) - the WASM loader is chosen to match (isModuleWorker()). → `ready` {view, models, worker:
 *   'module' | 'classic', loadMs}
 * - `frame` {bitmap: ImageBitmap (transferred), captureTimeMs, frameId?} → `result` {frameId, captureTimeMs,
 *   inferenceMs, timings: {poseMs, gestureMs}, width, height, poses, landmarks: {pose, hands} (compact -
 *   hands_up_signal.js), signal: {raised, score, reason, view, angled?, topDown?}}. The bitmap is closed whatever
 *   happens. A frame before `ready` or after `dispose` → `skipped` {frameId, captureTimeMs, reason}.
 * - `configure` {view?, signal?} - another view among the loaded models (`any` → `angled` once the set-up chose),
 *   other thresholds → `configured` {view}
 * - `restart` - a new timeline (the bench analyses a video again): MediaPipe's VIDEO mode wants ever increasing
 *   timestamps, so they continue after the last one.
 * - `dispose` - the models are closed → `disposed`; the page then terminates the worker.
 *
 * Errors are messages - `error` {phase: 'init' | 'frame' | 'configure' | 'message' | 'dispose' | 'worker', frameId?,
 * captureTimeMs?, name, message} - never thrown into the void.
 *
 * Frames are paced by the page (detector_pacing.js: one in flight, 4-5 fps, backing off by `inferenceMs`). The capture
 * time travels through unchanged - the finish machine's moments are the frames' own (finish_machine.js).
 *
 * The logic is createDetectorHost() - its I/O (the module import, postMessage, the clock) injected, so
 * tests/online-hands-up-harness.mjs drives it with a fake MediaPipe; the bottom of the file wires it to the worker.
 */

import { compactHands, compactPose, frameSignal, VIEW_ANGLED, VIEW_ANY, VIEW_TOP_DOWN, VIEWS } from './hands_up_signal.js';

export const MODEL_POSE = 'pose';
export const MODEL_GESTURE = 'gesture';

/**
 * The models a view needs.
 */
export function modelsFor(view) {
    if (view === VIEW_ANGLED) {
        return [MODEL_POSE];
    }

    if (view === VIEW_TOP_DOWN) {
        return [MODEL_GESTURE];
    }

    return view === VIEW_ANY ? [MODEL_POSE, MODEL_GESTURE] : [];
}

/**
 * Of several poses, the most prominent one: the largest box around its visible landmarks.
 */
export function pickPose(poses) {
    let best = null;
    let bestArea = -1;

    for (const pose of Array.isArray(poses) ? poses : []) {
        const seen = (Array.isArray(pose) ? pose : []).filter((point) => Number.isFinite(point?.x) && Number.isFinite(point?.y) && (point.visibility ?? 1) >= 0.5);

        if (seen.length === 0) {
            continue;
        }

        const xs = seen.map((point) => point.x);
        const ys = seen.map((point) => point.y);
        const area = (Math.max(...xs) - Math.min(...xs)) * (Math.max(...ys) - Math.min(...ys));

        if (area > bestArea) {
            best = pose;
            bestArea = area;
        }
    }

    return best ?? (Array.isArray(poses) && poses.length > 0 ? poses[0] : null);
}

/**
 * MediaPipe uploads every frame as a WebGL2 texture - with the CPU delegate too (without it a frame fails with
 * "Cannot read properties of undefined (reading 'activeTexture')"). A worker without WebGL2 on an OffscreenCanvas
 * (iOS before 17, a browser without any GPU path) cannot run the detector: init says so at once, the page competes
 * button-only (README §5.7).
 */
export function workerHasWebGl2(scope = globalThis) {
    try {
        if (typeof scope.OffscreenCanvas !== 'function') {
            return false;
        }

        const context = new scope.OffscreenCanvas(1, 1).getContext('webgl2');

        if (!context) {
            return false;
        }

        context.getExtension('WEBGL_lose_context')?.loseContext();

        return true;
    } catch (e) {
        return false;
    }
}

/**
 * A module worker, or a classic one? In a module worker importScripts() throws even with nothing to load; in a
 * classic worker it does nothing. The bench starts this file as a module worker; webpack 5 (Encore) bundles
 * `new Worker(new URL('./detector.worker.js', import.meta.url), {type: 'module'})` into a CLASSIC worker (it emits
 * `type: undefined`) - and MediaPipe loads its WASM loader differently in each (below).
 */
export function isModuleWorker(scope = globalThis) {
    if (typeof scope.importScripts !== 'function') {
        return true;
    }

    try {
        scope.importScripts();

        return false;
    } catch (e) {
        return true;
    }
}

function describe(error) {
    return {
        name: typeof error?.name === 'string' ? error.name : 'Error',
        message: typeof error?.message === 'string' ? error.message : String(error),
    };
}

function closeBitmap(bitmap) {
    try {
        bitmap?.close?.();
    } catch (e) {
        // Closed already
    }
}

/**
 * @param {object} io
 * @param {function(object): void} io.post                      postMessage
 * @param {function(string): Promise<object>} io.importModule    import() of an absolute URL
 * @param {function(): number} [io.now]                          performance.now()
 * @param {object} [io.scope]                                    where MediaPipe reads `ModuleFactory` (the worker's global)
 * @param {function(): boolean} [io.hasWebGl2]                   workerHasWebGl2()
 */
export function createDetectorHost({
    post,
    importModule,
    now = () => globalThis.performance.now(),
    scope = globalThis,
    hasWebGl2 = () => workerHasWebGl2(scope),
    moduleWorker = () => isModuleWorker(scope),
}) {
    const host = {
        status: 'idle', // idle | loading | ready | failed | disposed
        view: null,
        models: [],
        signalOptions: {},
        tasks: { pose: null, gesture: null },
        base: null,
        lastTimestamp: 0,
        workerType: null,
    };

    const send = (message) => {
        try {
            post(message);
        } catch (e) {
            // The page is gone
        }
    };

    async function init(message) {
        if (host.status === 'loading' || host.status === 'ready' || host.status === 'disposed') {
            send({ type: 'error', phase: 'init', name: 'InvalidStateError', message: `The detector is ${host.status} - one per session` });

            return;
        }

        const view = VIEWS.includes(message.view) ? message.view : null;

        if (view === null) {
            send({ type: 'error', phase: 'init', name: 'TypeError', message: `Unknown view ${String(message.view)}` });

            return;
        }

        const models = modelsFor(view);
        const urls = { tasksVision: message.tasksVisionUrl, wasmBase: message.wasmBaseUrl };

        if (models.includes(MODEL_POSE)) {
            urls.pose = message.poseModelUrl;
        }

        if (models.includes(MODEL_GESTURE)) {
            urls.gesture = message.gestureModelUrl;
        }

        const missing = Object.entries(urls).filter(([, url]) => typeof url !== 'string' || url === '').map(([name]) => name);

        if (missing.length > 0) {
            send({ type: 'error', phase: 'init', name: 'TypeError', message: `Missing URL: ${missing.join(', ')}` });

            return;
        }

        if (!hasWebGl2()) {
            host.status = 'failed';
            send({ type: 'error', phase: 'init', name: 'NotSupportedError', message: 'No WebGL2 in this worker - the detector cannot run here (button-only)' });

            return;
        }

        host.status = 'loading';
        const startedAt = now();
        const tasks = { pose: null, gesture: null };
        // Disposed while loading: what was made is closed, nothing is announced
        const abandoned = () => host.status !== 'loading';

        try {
            const vision = await importModule(urls.tasksVision);
            // A module worker cannot importScripts() MediaPipe's classic WASM loader: it takes the ES-module build,
            // which MediaPipe import()s and then reads `ModuleFactory` from the global scope - and clears it after
            // each task, while a second import() is served from the module cache and would set nothing. So the
            // factory is set from the loader's export before every task. A classic worker (Encore's bundle)
            // importScripts() the classic loader, which sets the global itself, every time.
            const asModule = moduleWorker();
            const fileset = await vision.FilesetResolver.forVisionTasks(urls.wasmBase, asModule);
            const factory = asModule ? (await importModule(fileset.wasmLoaderPath)).default : null;
            const prepare = () => {
                if (asModule) {
                    scope.ModuleFactory = factory;
                }
            };
            host.workerType = asModule ? 'module' : 'classic';

            if (models.includes(MODEL_POSE) && !abandoned()) {
                prepare();
                tasks.pose = await vision.PoseLandmarker.createFromOptions(fileset, {
                    baseOptions: { modelAssetPath: urls.pose, delegate: 'CPU' },
                    runningMode: 'VIDEO',
                    numPoses: Number.isInteger(message.numPoses) && message.numPoses > 0 ? message.numPoses : 1,
                    outputSegmentationMasks: false,
                });
            }

            if (models.includes(MODEL_GESTURE) && !abandoned()) {
                prepare();
                tasks.gesture = await vision.GestureRecognizer.createFromOptions(fileset, {
                    baseOptions: { modelAssetPath: urls.gesture, delegate: 'CPU' },
                    runningMode: 'VIDEO',
                    numHands: Number.isInteger(message.numHands) && message.numHands > 0 ? message.numHands : 2,
                });
            }
        } catch (error) {
            closeAll(tasks);

            if (!abandoned()) {
                host.status = 'failed';
                send({ type: 'error', phase: 'init', ...describe(error) });
            }

            return;
        }

        if (abandoned()) {
            closeAll(tasks);

            return;
        }

        host.tasks = tasks;
        host.view = view;
        host.models = models;
        host.signalOptions = message.signal && typeof message.signal === 'object' ? message.signal : {};
        host.status = 'ready';
        send({ type: 'ready', view, models, worker: host.workerType, loadMs: Math.round(now() - startedAt) });
    }

    function closeAll(tasks) {
        for (const task of [tasks.pose, tasks.gesture]) {
            try {
                task?.close();
            } catch (e) {
                // Half-made
            }
        }
    }

    function timestamp(captureTimeMs) {
        if (host.base === null) {
            host.base = captureTimeMs - (host.lastTimestamp + 1);
        }

        let next = Math.round(captureTimeMs - host.base);

        if (!(next > host.lastTimestamp)) {
            next = host.lastTimestamp + 1;
        }

        host.lastTimestamp = next;

        return next;
    }

    function frame(message) {
        const { bitmap, captureTimeMs } = message;
        const frameId = message.frameId ?? null;

        try {
            if (host.status !== 'ready') {
                send({ type: 'skipped', frameId, captureTimeMs, reason: host.status === 'disposed' ? 'disposed' : 'not_ready' });

                return;
            }

            if (!bitmap || !(bitmap.width > 0) || !(bitmap.height > 0)) {
                send({ type: 'skipped', frameId, captureTimeMs, reason: 'no_bitmap' });

                return;
            }

            if (!Number.isFinite(captureTimeMs)) {
                send({ type: 'skipped', frameId, captureTimeMs, reason: 'bad_time' });

                return;
            }

            const width = bitmap.width;
            const height = bitmap.height;
            const at = timestamp(captureTimeMs);
            const usePose = host.tasks.pose !== null && host.view !== VIEW_TOP_DOWN;
            const useGesture = host.tasks.gesture !== null && host.view !== VIEW_ANGLED;
            const timings = { poseMs: null, gestureMs: null };
            const started = now();
            let poses = [];
            let gestures = null;

            if (usePose) {
                poses = host.tasks.pose.detectForVideo(bitmap, at)?.landmarks ?? [];
                timings.poseMs = round1(now() - started);
            }

            if (useGesture) {
                const gestureStarted = now();
                gestures = host.tasks.gesture.recognizeForVideo(bitmap, at);
                timings.gestureMs = round1(now() - gestureStarted);
            }

            const inferenceMs = round1(now() - started);
            const landmarks = {
                pose: usePose ? compactPose(pickPose(poses)) : null,
                hands: useGesture ? compactHands(gestures) : null,
            };

            send({
                type: 'result',
                frameId,
                captureTimeMs,
                inferenceMs,
                timings,
                width,
                height,
                poses: Array.isArray(poses) ? poses.length : 0,
                landmarks,
                signal: frameSignal(landmarks, { ...host.signalOptions, view: host.view, aspect: width / height }),
            });
        } catch (error) {
            send({ type: 'error', phase: 'frame', frameId, captureTimeMs, ...describe(error) });
        } finally {
            closeBitmap(bitmap);
        }
    }

    function configure(message) {
        if (host.status !== 'ready') {
            send({ type: 'error', phase: 'configure', name: 'InvalidStateError', message: `The detector is ${host.status}` });

            return;
        }

        if (message.view !== undefined) {
            const needed = modelsFor(message.view);

            if (needed.length === 0 || needed.some((model) => !host.models.includes(model))) {
                send({ type: 'error', phase: 'configure', name: 'NotSupportedError', message: `View ${String(message.view)} needs models that are not loaded` });

                return;
            }

            host.view = message.view;
        }

        if (message.signal && typeof message.signal === 'object') {
            host.signalOptions = message.signal;
        }

        send({ type: 'configured', view: host.view });
    }

    function dispose() {
        const tasks = [host.tasks.pose, host.tasks.gesture].filter((task) => task !== null);
        host.tasks = { pose: null, gesture: null };
        host.status = 'disposed';

        for (const task of tasks) {
            try {
                task.close();
            } catch (error) {
                send({ type: 'error', phase: 'dispose', ...describe(error) });
            }
        }

        send({ type: 'disposed' });
    }

    return {
        state: host,

        /**
         * One message from the page. Never throws; a promise for `init` (the rest is synchronous).
         */
        async handle(message) {
            try {
                switch (message?.type) {
                    case 'init':
                        await init(message);
                        break;
                    case 'frame':
                        frame(message);
                        break;
                    case 'configure':
                        configure(message);
                        break;
                    case 'restart':
                        host.base = null;
                        break;
                    case 'dispose':
                        dispose();
                        break;
                    default:
                        closeBitmap(message?.bitmap);
                        send({ type: 'error', phase: 'message', name: 'TypeError', message: `Unknown message ${String(message?.type)}` });
                }
            } catch (error) {
                send({ type: 'error', phase: 'message', ...describe(error) });
            }
        },
    };
}

function round1(value) {
    return Math.round(value * 10) / 10;
}

// ---- the worker itself (a module worker - nothing of this runs when the file is imported elsewhere)

if (typeof globalThis.WorkerGlobalScope === 'function' && globalThis instanceof globalThis.WorkerGlobalScope) {
    const worker = globalThis;
    const host = createDetectorHost({
        post: (message) => worker.postMessage(message),
        // Pinned CDN / static URLs: never bundled - webpackIgnore keeps webpack from resolving them at build time
        importModule: (url) => import(/* webpackIgnore: true */ url),
        now: () => worker.performance.now(),
        scope: worker,
    });

    worker.addEventListener('message', (event) => {
        host.handle(event.data);
    });
    worker.addEventListener('error', (event) => {
        worker.postMessage({ type: 'error', phase: 'worker', name: 'Error', message: String(event?.message ?? 'Worker error') });
    });
    worker.addEventListener('unhandledrejection', (event) => {
        worker.postMessage({ type: 'error', phase: 'worker', ...describe(event?.reason) });
    });
}
