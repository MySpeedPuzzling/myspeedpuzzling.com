/**
 * The detection bench (docs/features/online-competitions/spikes.md S2): a local page, served from the repository root
 * by any static server, that steps a recorded solve frame by frame through THE detector worker the phones will run
 * (assets/online/detector.worker.js), draws what it saw, runs the finish machine over it (finish_machine.js), exports
 * the per-frame signals for score.mjs - and writes the S1 label file in its labelling mode.
 *
 * Stepping: seek, then wait for the frame to be presented (`requestVideoFrameCallback` - its `mediaTime` is the
 * frame's own time, the "capture time" of a recorded frame), grab it as an ImageBitmap (downscaled like the phone will:
 * 640 px wide by default; optionally mirrored, as a front camera shows), send it, wait for the answer - one frame in
 * flight, like the phone.
 *
 * Keys (labelling, not while typing): ←/→ one video frame, Shift+←/→ one second, Alt+←/→ 0.1 s, Space play/pause,
 * M empty mat (start, then end), L last piece placed, H hands up (complete), I hands up (incomplete - a premature raise
 * or a missing piece), E end of the latest raise (hands down), Backspace/Delete the nearest mark (within 1 s),
 * S save the label file.
 *
 * For scripted runs (the end-to-end proof drives it through WebDriver): window.bench.
 */

import { DEFAULT_RULES } from '../../assets/online/finish_machine.js';
import { DETECTOR_ASSETS, TASKS_VISION_VERSION, VIEW_ANY, VIEWS } from '../../assets/online/hands_up_signal.js';
import { contentBox, drawOverlay, drawTimeline, timeAtX } from './lib/draw.js';
import { emptyLabels, formatLabels, formatTime, parseLabels } from './lib/labels.js';
import { distribution, runMachine, scoreVideo, SIGNALS_FORMAT, SIGNALS_FORMAT_VERSION, signalsOf } from './lib/score_core.js';

const $ = (id) => document.getElementById(id);

const ui = {
    video: $('video'),
    overlay: $('overlay'),
    timeline: $('timeline'),
    time: $('time'),
    frameInfo: $('frame-info'),
    status: $('status'),
    fileInput: $('file-input'),
    view: $('view'),
    fps: $('fps'),
    inputWidth: $('input-width'),
    mirror: $('mirror'),
    videoFps: $('video-fps'),
    assets: {
        tasksVisionUrl: $('tasks-vision-url'),
        wasmBaseUrl: $('wasm-base-url'),
        poseModelUrl: $('pose-model-url'),
        gestureModelUrl: $('gesture-model-url'),
    },
    load: $('load'),
    analyse: $('analyse'),
    stop: $('stop'),
    progress: $('progress'),
    rules: {
        holdMs: $('rule-hold'),
        cooldownMs: $('rule-cooldown'),
        minScore: $('rule-min-score'),
        maxDropFrames: $('rule-drop-frames'),
        maxDropMs: $('rule-drop-ms'),
        maxGapMs: $('rule-gap'),
    },
    candidates: $('candidates'),
    stats: $('stats'),
    labelFields: {
        video: $('label-video'),
        phone: $('label-phone'),
        placement: $('label-placement'),
        light: $('label-light'),
        puzzle: $('label-puzzle'),
        complete: $('label-complete'),
        notes: $('label-notes'),
    },
    marks: $('marks'),
    saveLabels: $('save-labels'),
    exportSignals: $('export-signals'),
};

const state = {
    videoName: null,
    videoUrl: null,
    durationMs: 0,
    currentMs: 0,
    labels: emptyLabels(),
    emptyMatFromMs: null,
    frames: [],
    events: [],
    score: null,
    analysis: null,
    rules: { ...DEFAULT_RULES },
    detector: { worker: null, workerType: null, status: 'none', view: null, loadMs: null, ready: null, loading: null, pending: new Map(), nextFrameId: 1, errors: [] },
    analysing: false,
    stopRequested: false,
};

// ---- status

function setStatus(text, isError = false) {
    ui.status.textContent = text;
    ui.status.classList.toggle('error', isError);
}

// ---- footage

function stemOf(name) {
    return String(name ?? 'video').replace(/\.[^.]+$/, '');
}

async function loadVideo(url, name) {
    if (state.videoUrl?.startsWith('blob:')) {
        URL.revokeObjectURL(state.videoUrl);
    }

    state.videoUrl = url;
    state.videoName = name;
    state.frames = [];
    state.events = [];
    state.score = null;
    state.analysis = null;

    if (state.labels.video === null) {
        state.labels.video = name;
    }

    ui.video.src = url;
    await new Promise((resolve, reject) => {
        ui.video.addEventListener('loadeddata', resolve, { once: true });
        ui.video.addEventListener('error', () => reject(new Error(`The video cannot be played: ${name}`)), { once: true });
    });
    ui.video.pause();
    state.durationMs = Math.round(ui.video.duration * 1000);
    state.currentMs = 0;
    setStatus(`${name}: ${ui.video.videoWidth}×${ui.video.videoHeight}, ${formatTime(state.durationMs)}`);
    renderLabels();
    render();
}

function loadLabelsText(text) {
    const labels = parseLabels(text);
    state.labels = labels;

    if (labels.warnings.length > 0) {
        setStatus(`Labels: ${labels.warnings.join('; ')}`, true);
    }

    renderLabels();
    refreshMachine();
    render();
}

async function handleFiles(files) {
    const list = [...files];
    const labelsFile = list.find((file) => file.name.endsWith('.txt'));
    const videoFile = list.find((file) => file.type.startsWith('video/') || /\.(webm|mp4|mov|m4v|ogv|mkv)$/i.test(file.name));

    if (labelsFile) {
        loadLabelsText(await labelsFile.text());
    }

    if (videoFile) {
        await loadVideo(URL.createObjectURL(videoFile), videoFile.name);
    }
}

// ---- the detector worker

function assets() {
    return Object.fromEntries(Object.entries(ui.assets).map(([key, input]) => [key, input.value.trim()]));
}

function disposeDetector() {
    const detector = state.detector;

    if (detector.worker !== null) {
        detector.worker.postMessage({ type: 'dispose' });
        detector.worker.terminate();
    }

    for (const resolve of detector.pending.values()) {
        resolve({ type: 'error', phase: 'frame', message: 'The detector went away' });
    }

    detector.pending.clear();
    detector.worker = null;
    detector.status = 'none';
    detector.view = null;
}

function onWorkerMessage(event) {
    const message = event.data;
    const detector = state.detector;

    if (message?.frameId !== undefined && message.frameId !== null && detector.pending.has(message.frameId)) {
        detector.pending.get(message.frameId)(message);
        detector.pending.delete(message.frameId);

        return;
    }

    if (message?.type === 'ready') {
        detector.status = 'ready';
        detector.view = message.view;
        detector.loadMs = message.loadMs;
        detector.workerType = message.worker ?? null;
        detector.ready?.resolve(message);
        setStatus(`Detector ready: ${message.models.join(' + ')} in ${message.loadMs} ms (tasks-vision ${TASKS_VISION_VERSION}, CPU, ${message.worker} worker)`);
    } else if (message?.type === 'error') {
        detector.errors.push(message);

        if (message.phase === 'init') {
            detector.status = 'failed';
            detector.ready?.reject(new Error(message.message));
        }

        setStatus(`Detector error (${message.phase}): ${message.message}`, true);
    }
}

async function loadDetector() {
    const view = ui.view.value;
    const detector = state.detector;

    if (detector.status === 'ready' && detector.view === view) {
        return;
    }

    if (detector.status === 'loading') {
        await detector.loading;

        return;
    }

    if (detector.status === 'ready' && view !== VIEW_ANY && detector.view === VIEW_ANY) {
        // Fewer models than loaded: the worker switches without loading anything (as the phone's set-up does)
        detector.worker.postMessage({ type: 'configure', view });
        detector.view = view;

        return;
    }

    // The bench may start over with other models; the phone never does (one detector per session)
    disposeDetector();
    setStatus('Loading the detector…');
    detector.status = 'loading';
    detector.worker = new Worker(new URL('../../assets/online/detector.worker.js', import.meta.url), { type: 'module' });
    detector.worker.addEventListener('message', onWorkerMessage);
    detector.worker.addEventListener('error', (event) => {
        detector.status = 'failed';
        detector.ready?.reject(new Error(event.message || 'The worker did not start'));
        setStatus(`Worker error: ${event.message || 'the worker did not start'}`, true);
    });

    detector.loading = new Promise((resolve, reject) => {
        detector.ready = { resolve, reject };
    });

    detector.worker.postMessage({ type: 'init', view, ...assets() });
    await detector.loading;
}

function detect(bitmap, captureTimeMs) {
    const detector = state.detector;
    const frameId = detector.nextFrameId++;

    return new Promise((resolve) => {
        detector.pending.set(frameId, resolve);
        detector.worker.postMessage({ type: 'frame', bitmap, captureTimeMs, frameId }, [bitmap]);
    });
}

// ---- stepping through the video

/**
 * Shows the frame at `ms` and resolves with its own time (the presented frame's mediaTime, ms).
 */
function seekTo(ms) {
    const video = ui.video;
    const target = Math.max(0, Math.min(ms, state.durationMs)) / 1000;

    return new Promise((resolve) => {
        let settled = false;
        let handle = null;
        let fallback = null;
        const timeout = setTimeout(() => finish(video.currentTime * 1000), 3000);

        function finish(mediaMs) {
            if (settled) {
                return;
            }

            settled = true;
            clearTimeout(timeout);
            clearTimeout(fallback);

            if (handle !== null && typeof video.cancelVideoFrameCallback === 'function') {
                video.cancelVideoFrameCallback(handle);
            }

            state.currentMs = Math.round(mediaMs * 10) / 10;
            resolve(state.currentMs);
        }

        if (Math.abs(video.currentTime - target) < 0.0005 && !video.seeking) {
            finish(state.currentMs);

            return;
        }

        if (typeof video.requestVideoFrameCallback === 'function') {
            handle = video.requestVideoFrameCallback((now, metadata) => finish(metadata.mediaTime * 1000));
        }

        video.addEventListener('seeked', () => {
            // A browser that never calls back for a paused seek: the frame on screen is the seeked one
            fallback = setTimeout(() => finish(video.currentTime * 1000), handle === null ? 0 : 250);
        }, { once: true });
        video.currentTime = target;
    });
}

async function grab() {
    const video = ui.video;
    const full = ui.inputWidth.value === 'full';
    const width = full ? video.videoWidth : Math.min(video.videoWidth, parseInt(ui.inputWidth.value, 10));
    const height = Math.max(2, Math.round((width * video.videoHeight) / video.videoWidth / 2) * 2);

    if (!ui.mirror.checked) {
        return createImageBitmap(video, { resizeWidth: width, resizeHeight: height, resizeQuality: 'medium' });
    }

    const canvas = new OffscreenCanvas(width, height);
    const context = canvas.getContext('2d');
    context.translate(width, 0);
    context.scale(-1, 1);
    context.drawImage(video, 0, 0, width, height);

    return canvas.transferToImageBitmap();
}

function frameRecord(result) {
    return {
        t: result.captureTimeMs,
        inferenceMs: result.inferenceMs,
        timings: result.timings,
        poses: result.poses,
        pose: result.landmarks?.pose ?? null,
        hands: result.landmarks?.hands ?? null,
        signal: { raised: result.signal.raised, score: result.signal.score, reason: result.signal.reason, view: result.signal.view },
        angled: result.signal.angled ?? null,
        topDown: result.signal.topDown ?? null,
    };
}

/**
 * Every frame from `fromMs` to `toMs` at `fps` through the detector.
 */
async function analyse({ fromMs = 0, toMs = null, fps = null } = {}) {
    if (state.analysing || !state.videoUrl) {
        return null;
    }

    await loadDetector();
    const rate = fps ?? parseFloat(ui.fps.value);
    const end = toMs ?? state.durationMs;
    const stepMs = 1000 / rate;
    const started = performance.now();
    state.analysing = true;
    state.stopRequested = false;
    state.frames = [];
    state.analysis = {
        fps: rate,
        view: state.detector.view,
        inputWidth: ui.inputWidth.value,
        mirrored: ui.mirror.checked,
        fromMs,
        toMs: end,
        width: null,
        height: null,
        errors: 0,
    };
    state.detector.worker.postMessage({ type: 'restart' });
    ui.video.pause();
    let lastMs = -Infinity;

    for (let target = fromMs; target <= end + 0.5 && !state.stopRequested; target += stepMs) {
        const mediaMs = await seekTo(target);

        if (mediaMs <= lastMs) {
            // The video has fewer frames than the analysis rate - the same frame again
            continue;
        }

        lastMs = mediaMs;
        const bitmap = await grab();
        state.analysis.width = bitmap.width;
        state.analysis.height = bitmap.height;
        const result = await detect(bitmap, mediaMs);

        if (result.type === 'result') {
            state.frames.push(frameRecord(result));
        } else {
            state.analysis.errors += 1;
            setStatus(`Frame ${formatTime(mediaMs)}: ${result.type} ${result.reason ?? result.message ?? ''}`, true);
        }

        ui.progress.value = end > fromMs ? (mediaMs - fromMs) / (end - fromMs) : 1;

        if (state.frames.length % 10 === 0) {
            refreshMachine();
        }

        render();
    }

    state.analysing = false;
    state.analysis.wallMs = Math.round(performance.now() - started);
    refreshMachine();
    render();
    setStatus(`Analysed ${state.frames.length} frames in ${(state.analysis.wallMs / 1000).toFixed(1)} s${state.stopRequested ? ' (stopped)' : ''}`);

    return { frames: state.frames.length, wallMs: state.analysis.wallMs, errors: state.analysis.errors };
}

// ---- the finish machine over the analysed frames

function readRules() {
    const rules = { ...DEFAULT_RULES };

    for (const [key, input] of Object.entries(ui.rules)) {
        const value = parseFloat(input.value);

        if (Number.isFinite(value)) {
            rules[key] = value;
        }
    }

    state.rules = rules;
}

function refreshMachine() {
    const { events } = runMachine(signalsOf(state.frames), state.rules);
    state.events = events;
    state.score = state.labels.raises.length > 0
        ? scoreVideo({ name: state.videoName, frames: state.frames, labels: state.labels }, { rules: state.rules })
        : null;
    renderPanel();
}

// ---- labelling

function markAt(kind) {
    const at = state.currentMs;

    switch (kind) {
        case 'mat':
            if (state.emptyMatFromMs === null) {
                state.emptyMatFromMs = at;
                state.labels.emptyMat = { fromMs: at, toMs: null };
            } else {
                state.labels.emptyMat = { fromMs: Math.min(state.emptyMatFromMs, at), toMs: Math.max(state.emptyMatFromMs, at) };
                state.emptyMatFromMs = null;
            }
            break;
        case 'last':
            state.labels.lastPiecePlacedMs = at;
            break;
        case 'complete':
        case 'incomplete':
            state.labels.raises = [...state.labels.raises, { atMs: at, toMs: null, complete: kind === 'complete' }]
                .sort((a, b) => a.atMs - b.atMs);
            break;
        case 'end': {
            const raise = [...state.labels.raises].reverse().find((candidate) => candidate.atMs < at);

            if (raise) {
                raise.toMs = at;
            }
            break;
        }
        default:
    }

    renderLabels();
    refreshMachine();
    render();
}

function removeNearestMark() {
    const at = state.currentMs;
    const marks = [
        ...state.labels.raises.map((raise, index) => ({ ms: raise.atMs, remove: () => state.labels.raises.splice(index, 1) })),
        ...(Number.isFinite(state.labels.lastPiecePlacedMs) ? [{ ms: state.labels.lastPiecePlacedMs, remove: () => { state.labels.lastPiecePlacedMs = null; } }] : []),
        ...(state.labels.emptyMat ? [{ ms: state.labels.emptyMat.fromMs, remove: () => { state.labels.emptyMat = null; state.emptyMatFromMs = null; } }] : []),
    ].filter((mark) => Math.abs(mark.ms - at) <= 1000).sort((a, b) => Math.abs(a.ms - at) - Math.abs(b.ms - at));

    if (marks.length > 0) {
        marks[0].remove();
        renderLabels();
        refreshMachine();
        render();
    }
}

function readLabelFields() {
    for (const key of ['video', 'phone', 'placement', 'light', 'puzzle', 'notes']) {
        const value = ui.labelFields[key].value.trim();
        state.labels[key] = value === '' ? null : value;
    }

    const complete = ui.labelFields.complete.value;
    state.labels.complete = complete === 'yes' ? true : (complete === 'no' ? false : null);
}

function labelsText() {
    readLabelFields();

    return formatLabels(state.labels);
}

function download(name, text, type) {
    const link = document.createElement('a');
    link.href = URL.createObjectURL(new Blob([text], { type }));
    link.download = name;
    document.body.append(link);
    link.click();
    link.remove();
    setTimeout(() => URL.revokeObjectURL(link.href), 1000);
}

// ---- export

function inferenceStats() {
    const all = distribution(state.frames.map((frame) => frame.inferenceMs));
    const pose = distribution(state.frames.map((frame) => frame.timings?.poseMs));
    const gesture = distribution(state.frames.map((frame) => frame.timings?.gestureMs));

    return { all, pose, gesture };
}

function signalsJson() {
    const stats = inferenceStats();

    return JSON.stringify({
        format: SIGNALS_FORMAT,
        formatVersion: SIGNALS_FORMAT_VERSION,
        createdAt: new Date().toISOString(),
        video: { name: state.videoName, durationMs: state.durationMs, width: ui.video.videoWidth, height: ui.video.videoHeight },
        analysis: state.analysis,
        detector: {
            ...assets(),
            tasksVisionVersion: TASKS_VISION_VERSION,
            delegate: 'CPU',
            worker: state.detector.workerType ?? null,
            loadMs: state.detector.loadMs,
            userAgent: navigator.userAgent,
            hardwareConcurrency: navigator.hardwareConcurrency ?? null,
        },
        inference: {
            medianMs: stats.all.median,
            p90Ms: stats.all.p90,
            meanMs: stats.all.mean,
            maxMs: stats.all.max,
            poseMedianMs: stats.pose.median,
            gestureMedianMs: stats.gesture.median,
        },
        frames: state.frames,
    });
}

// ---- rendering

function nearestFrame(ms) {
    let best = null;

    for (const frame of state.frames) {
        if (best === null || Math.abs(frame.t - ms) < Math.abs(best.t - ms)) {
            best = frame;
        }
    }

    const step = state.analysis ? 1000 / state.analysis.fps : 200;

    return best !== null && Math.abs(best.t - ms) <= step / 2 + 1 ? best : null;
}

function render() {
    const video = ui.video;
    const overlay = ui.overlay;
    const rect = video.getBoundingClientRect();
    overlay.width = Math.round(rect.width);
    overlay.height = Math.round(rect.height);
    const frame = nearestFrame(state.currentMs);
    drawOverlay(overlay.getContext('2d'), contentBox(overlay.width, overlay.height, video.videoWidth, video.videoHeight), frame, {
        mirrored: state.analysis?.mirrored ?? false,
    });

    const timeline = ui.timeline;
    timeline.width = Math.round(timeline.getBoundingClientRect().width);
    drawTimeline(timeline.getContext('2d'), {
        durationMs: state.durationMs,
        frames: state.frames,
        events: state.events,
        labels: state.labels,
        currentMs: state.currentMs,
    });

    ui.time.textContent = `${formatTime(state.currentMs)} (${state.currentMs.toFixed(1)} ms)`;
    ui.frameInfo.textContent = frame === null
        ? 'not analysed'
        : `inference ${frame.inferenceMs} ms${frame.poses > 1 ? `, ${frame.poses} people` : ''}`;
}

function renderPanel() {
    const list = ui.candidates;
    list.replaceChildren();
    const pairs = state.score?.pairs ?? [];

    for (const event of state.events.filter((candidate) => candidate.type === 'candidate')) {
        const pair = pairs.find((candidate) => candidate.candidate?.id === event.id);
        const item = document.createElement('li');
        const error = pair ? ` - error ${pair.errorMs > 0 ? '+' : ''}${pair.errorMs} ms vs the label at ${formatTime(pair.raise.atMs)}` : (state.score ? ' - matches no label (false raise)' : '');
        item.textContent = `#${event.id} at ${formatTime(event.atMs)} (known ${formatTime(event.detectedAtMs)}, held ${event.heldMs} ms, score ${event.score})${error}`;
        item.addEventListener('click', () => seekTo(event.atMs).then(render));
        list.append(item);
    }

    for (const pair of pairs.filter((candidate) => candidate.candidate === null)) {
        const item = document.createElement('li');
        item.className = 'missed';
        item.textContent = `missed: the raise at ${formatTime(pair.raise.atMs)} (${pair.raise.complete ? 'complete' : 'incomplete'})${pair.inCooldown ? ' - held back by the cooldown' : ''}`;
        list.append(item);
    }

    const stats = inferenceStats();
    const ms = (value) => (value === null ? '-' : `${Math.round(value * 10) / 10} ms`);
    ui.stats.textContent = `${state.frames.length} frames · inference median ${ms(stats.all.median)}, p90 ${ms(stats.all.p90)}, max ${ms(stats.all.max)}`
        + (stats.pose.count > 0 ? ` · pose ${ms(stats.pose.median)}` : '')
        + (stats.gesture.count > 0 ? ` · gesture ${ms(stats.gesture.median)}` : '')
        + (state.detector.loadMs !== null ? ` · loaded in ${state.detector.loadMs} ms` : '');
}

function renderLabels() {
    const labels = state.labels;

    for (const key of ['video', 'phone', 'placement', 'light', 'puzzle', 'notes']) {
        ui.labelFields[key].value = labels[key] ?? '';
    }

    ui.labelFields.complete.value = labels.complete === true ? 'yes' : (labels.complete === false ? 'no' : '');
    const list = ui.marks;
    list.replaceChildren();
    const rows = [];

    if (labels.emptyMat) {
        rows.push([labels.emptyMat.fromMs, `empty mat ${formatTime(labels.emptyMat.fromMs)}${Number.isFinite(labels.emptyMat.toMs) ? `-${formatTime(labels.emptyMat.toMs)}` : ' (M again for its end)'}`]);
    }

    if (Number.isFinite(labels.lastPiecePlacedMs)) {
        rows.push([labels.lastPiecePlacedMs, `last piece placed ${formatTime(labels.lastPiecePlacedMs)}`]);
    }

    for (const raise of labels.raises) {
        rows.push([raise.atMs, `hands up ${formatTime(raise.atMs)}${Number.isFinite(raise.toMs) ? `-${formatTime(raise.toMs)}` : ''} ${raise.complete === true ? 'complete' : (raise.complete === false ? 'incomplete' : '')}`]);
    }

    for (const [ms, text] of rows.sort((a, b) => a[0] - b[0])) {
        const item = document.createElement('li');
        item.textContent = text;
        item.addEventListener('click', () => seekTo(ms).then(render));
        list.append(item);
    }
}

// ---- wiring

function videoFrameMs() {
    const fps = parseFloat(ui.videoFps.value);

    return 1000 / (Number.isFinite(fps) && fps > 0 ? fps : 30);
}

function isTyping(target) {
    return ['INPUT', 'SELECT', 'TEXTAREA'].includes(target?.tagName) && target.type !== 'checkbox' && target.type !== 'range';
}

document.addEventListener('keydown', (event) => {
    if (isTyping(event.target) || event.ctrlKey || event.metaKey) {
        return;
    }

    if (state.analysing) {
        if (event.key === 'Escape') {
            state.stopRequested = true;
        }

        return;
    }

    const step = event.shiftKey ? 1000 : (event.altKey ? 100 : videoFrameMs());

    switch (event.key) {
        case 'ArrowRight':
            // A hair past the next frame's time, so a 29.97 fps video still moves on
            seekTo(state.currentMs + step + 1).then(render);
            break;
        case 'ArrowLeft':
            seekTo(state.currentMs - step + 1).then(render);
            break;
        case ' ':
            if (ui.video.paused) {
                ui.video.play();
            } else {
                ui.video.pause();
            }
            break;
        case 'm':
        case 'M':
            markAt('mat');
            break;
        case 'l':
        case 'L':
            markAt('last');
            break;
        case 'h':
        case 'H':
            markAt('complete');
            break;
        case 'i':
        case 'I':
            markAt('incomplete');
            break;
        case 'e':
        case 'E':
            markAt('end');
            break;
        case 'Backspace':
        case 'Delete':
            removeNearestMark();
            break;
        case 's':
        case 'S':
            download(`${stemOf(state.videoName)}.txt`, labelsText(), 'text/plain');
            break;
        default:
            return;
    }

    event.preventDefault();
});

ui.video.addEventListener('timeupdate', () => {
    if (!ui.video.paused) {
        state.currentMs = ui.video.currentTime * 1000;
        render();
    }
});
ui.video.addEventListener('pause', () => {
    if (!state.analysing) {
        seekTo(ui.video.currentTime * 1000 + 1).then(render);
    }
});
ui.timeline.addEventListener('click', (event) => {
    const rect = ui.timeline.getBoundingClientRect();
    seekTo(timeAtX((event.clientX - rect.left) * (ui.timeline.width / rect.width), ui.timeline.width, state.durationMs)).then(render);
});
ui.fileInput.addEventListener('change', () => handleFiles(ui.fileInput.files).catch((error) => setStatus(error.message, true)));
document.addEventListener('dragover', (event) => event.preventDefault());
document.addEventListener('drop', (event) => {
    event.preventDefault();
    handleFiles(event.dataTransfer.files).catch((error) => setStatus(error.message, true));
});
ui.load.addEventListener('click', () => loadDetector().catch((error) => setStatus(error.message, true)));
ui.analyse.addEventListener('click', () => analyse().catch((error) => {
    state.analysing = false;
    setStatus(error.message, true);
}));
ui.stop.addEventListener('click', () => {
    state.stopRequested = true;
});
ui.saveLabels.addEventListener('click', () => download(`${stemOf(state.videoName)}.txt`, labelsText(), 'text/plain'));
ui.exportSignals.addEventListener('click', () => download(`${stemOf(state.videoName)}.signals.json`, signalsJson(), 'application/json'));

for (const input of Object.values(ui.rules)) {
    input.addEventListener('change', () => {
        readRules();
        refreshMachine();
        render();
    });
}

for (const input of Object.values(ui.labelFields)) {
    input.addEventListener('change', readLabelFields);
}

window.addEventListener('resize', render);

// Defaults: the pinned assets, the rules
for (const [key, input] of Object.entries(ui.assets)) {
    input.value = DETECTOR_ASSETS[key];
}

for (const [key, input] of Object.entries(ui.rules)) {
    input.value = DEFAULT_RULES[key];
}

for (const view of VIEWS) {
    const option = document.createElement('option');
    option.value = view;
    option.textContent = view;
    ui.view.append(option);
}

ui.view.value = VIEW_ANY;

// ?video=…&labels=…&view=…&fps=…&input=… (URLs on the same server - the footage folder mounted next to the repository)
const params = new URLSearchParams(location.search);

for (const [param, input] of [['view', ui.view], ['fps', ui.fps], ['input', ui.inputWidth]]) {
    if (params.has(param)) {
        input.value = params.get(param);
    }
}

// ?assets=local: the copies fetch-models.mjs downloaded next to this page
if (params.get('assets') === 'local') {
    const local = new URL('./models/', import.meta.url).href;
    ui.assets.tasksVisionUrl.value = `${local}tasks-vision/vision_bundle.js`;
    ui.assets.wasmBaseUrl.value = `${local}tasks-vision/wasm`;
    ui.assets.poseModelUrl.value = `${local}pose_landmarker_lite.task`;
    ui.assets.gestureModelUrl.value = `${local}gesture_recognizer.task`;
}

async function loadFromUrls(videoUrl, labelsUrl = null) {
    if (labelsUrl) {
        const response = await fetch(labelsUrl, { cache: 'no-store' });

        if (response.ok) {
            loadLabelsText(await response.text());
        }
    }

    await loadVideo(videoUrl, decodeURIComponent(videoUrl.split('/').pop()));
}

if (params.has('video')) {
    loadFromUrls(params.get('video'), params.get('labels')).catch((error) => setStatus(error.message, true));
}

renderPanel();

// For scripted runs (WebDriver): the same functions the buttons and keys use
window.bench = {
    state,
    loadFromUrls,
    loadDetector,
    analyse,
    seek: (ms) => seekTo(ms).then((at) => {
        render();

        return at;
    }),
    mark: (kind) => markAt(kind),
    setLabelField: (key, value) => {
        ui.labelFields[key].value = value;
        readLabelFields();
    },
    setRules: (rules) => {
        for (const [key, value] of Object.entries(rules)) {
            if (ui.rules[key]) {
                ui.rules[key].value = value;
            }
        }

        readRules();
        refreshMachine();
        render();
    },
    labelsText,
    signalsJson,
    inferenceStats,
    candidates: () => state.events.filter((event) => event.type === 'candidate'),
    score: () => state.score,
};
