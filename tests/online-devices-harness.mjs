// Runs the participant page's device modules - assets/online/camera.js (front / back, the Android main-lens re-pick,
// mute / unmute / ended), wake_lock.js (asked on a tap, again on every return), beeps.js (the countdown on the audio
// clock, the chime of a detected finish), frame_grabber.js (frames with their capture time, photos) and detector_feed.js
// (the finish detector's frames, how sharp a still is) - against fake browser APIs, and prints
// {scenario: "ok" | error} as JSON. tests/OnlineDevicesScriptTest.php asserts every one is "ok".

import assert from 'node:assert/strict';
import {
    Camera,
    cameraErrorStatus,
    DEFAULT_FACING,
    FACING_BACK,
    FACING_FRONT,
    mainBackCamera,
    STORAGE_BACK_LENS,
    STORAGE_FACING,
} from '../assets/online/camera.js';
import { ScreenWakeLock } from '../assets/online/wake_lock.js';
import { ALARM_PATTERN_MS, Beeps, CHIME_PATTERN, CHIME_VIBRATION, countdownPlan, GO_VIBRATION, TONES } from '../assets/online/beeps.js';
import {
    captureTimeOf,
    fitWithin,
    FRAME_FALLBACK_MS,
    FRAME_QUALITY,
    FrameGrabber,
    PHOTO_QUALITY,
    STILL_QUALITY,
} from '../assets/online/frame_grabber.js';
import { canvasSharpness, DETECTOR_SIDE, DetectorFeed, laplacianVariance, SHARPNESS_WIDTH } from '../assets/online/detector_feed.js';

const START = 1_792_000_000_000;

async function settle() {
    for (let i = 0; i < 20; i++) {
        await new Promise((resolve) => setImmediate(resolve));
    }
}

function storage(initial = {}) {
    const items = new Map(Object.entries(initial));

    return {
        items,
        getItem: (key) => (items.has(key) ? items.get(key) : null),
        setItem: (key, value) => items.set(key, String(value)),
        removeItem: (key) => items.delete(key),
    };
}

// ---- a fake camera

class FakeTrack {
    constructor(device, { width = 1920, height = 1080, frameRate = 30, focusMode = undefined } = {}) {
        this.device = device;
        this.settings = { deviceId: device.deviceId, width, height, frameRate };
        this.focusMode = focusMode;
        this.readyState = 'live';
        this.muted = false;
        this.stopped = false;
        this.listeners = {};
    }

    getSettings() {
        return { ...this.settings };
    }

    getCapabilities() {
        return this.focusMode === undefined ? {} : { focusMode: this.focusMode };
    }

    addEventListener(type, listener) {
        (this.listeners[type] ??= new Set()).add(listener);
    }

    removeEventListener(type, listener) {
        this.listeners[type]?.delete(listener);
    }

    listenerCount() {
        return Object.values(this.listeners).reduce((sum, set) => sum + set.size, 0);
    }

    emit(type) {
        if (type === 'ended') {
            this.readyState = 'ended';
        } else if (type === 'mute') {
            this.muted = true;
        } else if (type === 'unmute') {
            this.muted = false;
        }

        for (const listener of [...(this.listeners[type] ?? [])]) {
            listener();
        }
    }

    stop() {
        this.stopped = true;
        this.readyState = 'ended';
    }
}

class FakeStream {
    constructor(track) {
        this.track = track;
    }

    getTracks() {
        return [this.track];
    }

    getVideoTracks() {
        return [this.track];
    }
}

function namedError(name) {
    const error = new Error(name);
    error.name = name;

    return error;
}

/**
 * Android-like: three cameras, facingMode 'environment' opens the highest-index back lens (the ultra-wide).
 */
function mediaDevices({ devices = null, fail = null, focus = {}, hold = false } = {}) {
    const list = devices ?? [
        { kind: 'videoinput', deviceId: 'front-id', label: 'camera2 1, facing front' },
        { kind: 'videoinput', deviceId: 'main-id', label: 'camera2 0, facing back' },
        { kind: 'videoinput', deviceId: 'wide-id', label: 'camera2 2, facing back' },
    ];
    const media = {
        calls: [],
        tracks: [],
        held: [],
        async enumerateDevices() {
            return list;
        },
        getUserMedia(constraints) {
            media.calls.push(constraints);

            if (fail) {
                return Promise.reject(namedError(fail));
            }

            const video = constraints.video;
            let device;

            if (video.deviceId?.exact) {
                device = list.find((item) => item.deviceId === video.deviceId.exact);

                if (!device) {
                    return Promise.reject(namedError('OverconstrainedError'));
                }
            } else if (video.facingMode?.ideal === 'user') {
                device = list.find((item) => /front/.test(item.label));
            } else {
                device = [...list].reverse().find((item) => /back/.test(item.label));
            }

            const track = new FakeTrack(device, { focusMode: focus[device.deviceId] });
            media.tracks.push(track);
            const stream = new FakeStream(track);

            if (hold) {
                return new Promise((resolve) => media.held.push(() => resolve(stream)));
            }

            return Promise.resolve(stream);
        },
    };

    return media;
}

const ANDROID = 'Mozilla/5.0 (Linux; Android 14; SM-A546B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Mobile Safari/537.36';
const IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1';

// ---- a fake document and wake lock

function fakeDocument(visibilityState = 'visible') {
    const listeners = new Set();

    return {
        visibilityState,
        addEventListener: (type, listener) => listeners.add(listener),
        removeEventListener: (type, listener) => listeners.delete(listener),
        listenerCount: () => listeners.size,
        show(state) {
            this.visibilityState = state;
            [...listeners].forEach((listener) => listener());
        },
    };
}

class FakeSentinel {
    constructor() {
        this.released = false;
        this.listeners = [];
    }

    addEventListener(type, listener) {
        this.listeners.push(listener);
    }

    async release() {
        this.letGo();
    }

    // The browser lets go (the page was hidden, power saving)
    letGo() {
        if (!this.released) {
            this.released = true;
            this.listeners.forEach((listener) => listener());
        }
    }
}

function fakeWakeLock({ fail = null } = {}) {
    const api = {
        sentinels: [],
        requests: 0,
        fail,
        async request(type) {
            assert.equal(type, 'screen');
            api.requests += 1;

            if (api.fail) {
                throw namedError(api.fail);
            }

            const sentinel = new FakeSentinel();
            api.sentinels.push(sentinel);

            return sentinel;
        },
    };

    return api;
}

// ---- a fake audio context

class FakeAudioContext {
    static instances = [];

    constructor() {
        this.state = 'suspended';
        this.currentTime = 100;
        this.oscillators = [];
        this.sources = 0;
        this.destination = {};
        FakeAudioContext.instances.push(this);
    }

    resume() {
        this.state = 'running';

        return Promise.resolve();
    }

    createBuffer() {
        return {};
    }

    createBufferSource() {
        this.sources += 1;

        return { connect() {}, start() {} };
    }

    createOscillator() {
        const oscillator = {
            type: null,
            frequency: { setValueAtTime: (value) => { oscillator.hz = value; } },
            connect() {},
            disconnect() { oscillator.disconnected = true; },
            start: (at) => { oscillator.startAt = at; },
            stop: (at) => {
                if (at === undefined) {
                    oscillator.cancelled = true;
                } else {
                    oscillator.stopAt = at;
                }
            },
        };
        this.oscillators.push(oscillator);

        return oscillator;
    }

    createGain() {
        return { gain: { setValueAtTime() {}, exponentialRampToValueAtTime() {} }, connect() {}, disconnect() {} };
    }

    close() {
        this.state = 'closed';

        return Promise.resolve();
    }
}

// ---- a fake video, canvas and timers

function fakeVideo({ width = 1920, height = 1080, frameCallbacks = true } = {}) {
    const callbacks = new Map();
    let next = 1;

    return {
        videoWidth: width,
        videoHeight: height,
        readyState: 4,
        requestVideoFrameCallback: frameCallbacks
            ? (callback) => {
                const handle = next++;
                callbacks.set(handle, callback);

                return handle;
            }
            : undefined,
        cancelVideoFrameCallback: (handle) => callbacks.delete(handle),
        // The camera delivers a frame: every waiting callback gets it
        present(now, metadata) {
            const waiting = [...callbacks.values()];
            callbacks.clear();
            waiting.forEach((callback) => callback(now, metadata));
        },
        waiting: () => callbacks.size,
    };
}

function canvases() {
    const made = [];
    const encodes = [];

    return {
        made,
        encodes,
        create() {
            const canvas = {
                width: 0,
                height: 0,
                drawn: [],
                getContext: () => ({
                    drawImage: (video, x, y, width, height) => canvas.drawn.push({ width, height }),
                }),
                toBlob: (callback, type, quality) => {
                    const blob = { size: 1000, type, quality, width: canvas.width, height: canvas.height };
                    encodes.push(() => callback(blob));
                },
            };
            made.push(canvas);

            return canvas;
        },
        // The browser finishes the encodings started so far
        async finish() {
            const done = encodes.splice(0);
            done.forEach((complete) => complete());
            await settle();
        },
    };
}

function timers() {
    let now = 0;
    const list = [];

    return {
        now: () => now,
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
        active: () => list.filter((item) => !item.cancelled).length,
    };
}

const scenarios = {
    async 'the front camera by default, the choice remembered; 1080p asked as ideal, no audio'() {
        const media = mediaDevices();
        const store = storage();
        const changes = [];
        const camera = new Camera({ mediaDevices: media, storage: store, userAgent: ANDROID, onChange: (c) => changes.push(c.status) });

        assert.equal(camera.facing, DEFAULT_FACING);
        assert.equal(DEFAULT_FACING, FACING_FRONT);
        assert.equal(await camera.open(), 'live');
        assert.deepEqual(changes, ['opening', 'live']);
        assert.deepEqual(media.calls[0], { audio: false, video: { facingMode: { ideal: 'user' }, width: { ideal: 1920 }, height: { ideal: 1080 }, frameRate: { ideal: 30 } } });
        assert.equal(camera.pick, 'front');
        assert.deepEqual(camera.settings(), { deviceId: 'front-id', width: 1920, height: 1080, frameRate: 30 });

        // Switched to the back camera: remembered for the next page
        await camera.switchFacing();
        assert.equal(store.getItem(STORAGE_FACING), FACING_BACK);
        assert.equal(media.tracks[0].stopped, true, 'the front camera was let go first');
        assert.equal(new Camera({ mediaDevices: media, storage: store }).facing, FACING_BACK);
    },

    async 'the back camera on Android: the main lens picked by its label and remembered under the scanner\'s key'() {
        const media = mediaDevices();
        const store = storage({ [STORAGE_FACING]: FACING_BACK });
        const camera = new Camera({ mediaDevices: media, storage: store, userAgent: ANDROID });

        assert.equal(await camera.open(), 'live');
        // facingMode opened the ultra-wide; it was stopped and the main lens opened
        assert.equal(media.calls[0].video.facingMode.ideal, 'environment');
        assert.equal(media.tracks[0].device.deviceId, 'wide-id');
        assert.equal(media.tracks[0].stopped, true);
        assert.deepEqual(media.calls[1].video.deviceId, { exact: 'main-id' });
        assert.equal(camera.pick, 'main lens');
        assert.equal(store.getItem(STORAGE_BACK_LENS), 'main-id', 'the barcode scanner uses it too');

        // Next time straight to it
        const again = new Camera({ mediaDevices: media, storage: store, userAgent: ANDROID });
        await again.open();
        assert.deepEqual(media.calls[2].video.deviceId, { exact: 'main-id' });
        assert.equal(again.pick, 'remembered main lens');
    },

    async 'the back lens: a remembered one gone is picked again; iPhones and a "facing" choice keep facingMode'() {
        const media = mediaDevices();
        const store = storage({ [STORAGE_FACING]: FACING_BACK, [STORAGE_BACK_LENS]: 'gone-id' });
        const camera = new Camera({ mediaDevices: media, storage: store, userAgent: ANDROID });

        assert.equal(await camera.open(), 'live');
        assert.deepEqual(media.calls[0].video.deviceId, { exact: 'gone-id' });
        assert.equal(store.getItem(STORAGE_BACK_LENS), 'main-id', 'picked again');

        const iphoneMedia = mediaDevices();
        const iphoneStore = storage({ [STORAGE_FACING]: FACING_BACK });
        const iphone = new Camera({ mediaDevices: iphoneMedia, storage: iphoneStore, userAgent: IPHONE });
        await iphone.open();
        assert.equal(iphoneMedia.calls.length, 1, 'Safari picks the right lens itself');
        assert.equal(iphone.pick, 'facingMode');
        assert.equal(iphoneStore.getItem(STORAGE_BACK_LENS), null);

        const facingMedia = mediaDevices();
        const facing = new Camera({ mediaDevices: facingMedia, storage: storage({ [STORAGE_FACING]: FACING_BACK, [STORAGE_BACK_LENS]: 'facing' }), userAgent: ANDROID });
        await facing.open();
        assert.equal(facingMedia.calls.length, 1);
        assert.equal(facing.pick, 'facingMode');
    },

    async 'a main lens without autofocus is not taken (the scanner\'s rule); no label to go by keeps facingMode'() {
        const media = mediaDevices({ focus: { 'main-id': ['manual'] } });
        const store = storage({ [STORAGE_FACING]: FACING_BACK });
        const camera = new Camera({ mediaDevices: media, storage: store, userAgent: ANDROID });

        assert.equal(await camera.open(), 'live');
        assert.equal(store.getItem(STORAGE_BACK_LENS), 'facing');
        assert.equal(camera.pick, 'facingMode');
        assert.equal(media.tracks[1].stopped, true, 'the main lens was closed again');

        const unlabelled = mediaDevices({
            devices: [
                { kind: 'videoinput', deviceId: 'a', label: '' },
                { kind: 'videoinput', deviceId: 'b', label: 'Back Camera, facing back' },
            ],
        });
        const plainStore = storage({ [STORAGE_FACING]: FACING_BACK });
        await new Camera({ mediaDevices: unlabelled, storage: plainStore, userAgent: ANDROID }).open();
        assert.equal(unlabelled.calls.length, 1);
        assert.equal(plainStore.getItem(STORAGE_BACK_LENS), 'facing');

        assert.equal(mainBackCamera([{ kind: 'videoinput', deviceId: 'm', label: 'camera 0, facing back' }], 'm'), null, 'already the main one');
        assert.equal(mainBackCamera([{ kind: 'videoinput', deviceId: 'm', label: 'camera 0, facing back' }], 'x').deviceId, 'm');
        assert.equal(mainBackCamera([{ kind: 'videoinput', deviceId: 'm', label: 'camera 10, facing back' }], 'x'), null, 'lens 10 is not lens 0');
    },

    async 'what a refusal means: blocked, none, busy - and no camera API at all'() {
        assert.equal(cameraErrorStatus(namedError('NotAllowedError')), 'denied');
        assert.equal(cameraErrorStatus(namedError('SecurityError')), 'denied');
        assert.equal(cameraErrorStatus(namedError('NotFoundError')), 'unavailable');
        assert.equal(cameraErrorStatus(namedError('OverconstrainedError')), 'unavailable');
        assert.equal(cameraErrorStatus(namedError('NotReadableError')), 'busy');
        assert.equal(cameraErrorStatus(new Error('?')), 'error');

        for (const [fail, status] of [['NotAllowedError', 'denied'], ['NotReadableError', 'busy'], ['NotFoundError', 'unavailable'], ['TypeError', 'error']]) {
            const camera = new Camera({ mediaDevices: mediaDevices({ fail }), storage: storage() });
            assert.equal(await camera.open(), status, fail);
            assert.equal(camera.stream, null);
        }

        // A blocked back camera on Android is not "picked again" in a loop of prompts
        const blocked = mediaDevices({ fail: 'NotAllowedError' });
        const backStore = storage({ [STORAGE_FACING]: FACING_BACK, [STORAGE_BACK_LENS]: 'main-id' });
        assert.equal(await new Camera({ mediaDevices: blocked, storage: backStore, userAgent: ANDROID }).open(), 'denied');
        assert.equal(blocked.calls.length, 1);
        assert.equal(backStore.getItem(STORAGE_BACK_LENS), 'main-id', 'the lens choice stays');

        const none = new Camera({ mediaDevices: null, storage: storage() });
        assert.equal(none.isSupported(), false);
        assert.equal(await none.open(), 'unavailable');
    },

    async 'the track\'s mute / unmute / ended reach the page; stop() lets the camera go and listens no more'() {
        const media = mediaDevices();
        const events = [];
        const camera = new Camera({ mediaDevices: media, storage: storage(), onTrackEvent: (event) => events.push(event.type) });
        await camera.open();
        const track = media.tracks[0];

        track.emit('mute');
        assert.equal(camera.status, 'muted');
        track.emit('unmute');
        assert.equal(camera.status, 'live');
        track.emit('ended');
        assert.equal(camera.status, 'ended');
        assert.deepEqual(events, ['mute', 'unmute', 'ended']);

        // Opened again (back from the background)
        assert.equal(await camera.reopen(), 'live');
        assert.equal(track.listenerCount(), 0, 'the old track is not listened to');
        assert.equal(media.tracks[1].listenerCount(), 3);

        camera.stop();
        assert.equal(camera.status, 'idle');
        assert.equal(media.tracks[1].stopped, true);
        assert.equal(media.tracks[1].listenerCount(), 0);
        media.tracks[1].emit('ended');
        assert.deepEqual(events, ['mute', 'unmute', 'ended'], 'nothing after stop()');

        // A track muted from the start (opened while hidden) reports muted
        const quiet = mediaDevices();
        const mutedCamera = new Camera({ mediaDevices: { ...quiet, getUserMedia: async (constraints) => {
            const stream = await quiet.getUserMedia(constraints);
            stream.track.muted = true;

            return stream;
        } }, storage: storage() });
        assert.equal(await mutedCamera.open(), 'muted');
    },

    async 'opened twice at once: the later call wins, the earlier stream is stopped when it arrives'() {
        const media = mediaDevices({ hold: true });
        const camera = new Camera({ mediaDevices: media, storage: storage() });

        const first = camera.open();
        const second = camera.open();
        await settle();
        assert.equal(media.held.length, 2);

        media.held[1]();
        assert.equal(await second, 'live');
        media.held[0]();
        await first;
        assert.equal(media.tracks[0].stopped, true, 'the late first stream');
        assert.equal(media.tracks[1].stopped, false);
        assert.equal(camera.track, media.tracks[1]);

        // stop() while opening: the stream that arrives is stopped
        const later = mediaDevices({ hold: true });
        const stopped = new Camera({ mediaDevices: later, storage: storage() });
        const opening = stopped.open();
        await settle();
        stopped.stop();
        later.held[0]();
        await opening;
        assert.equal(stopped.status, 'idle');
        assert.equal(later.tracks[0].stopped, true);
    },

    async 'the wake lock: asked on a tap, let go when hidden, asked again on the way back - and not when no longer wanted'() {
        const document = fakeDocument();
        const api = fakeWakeLock();
        const states = [];
        const lock = new ScreenWakeLock({ wakeLock: api, document, onChange: (state) => states.push(state) }).attach();

        assert.equal(lock.state, 'idle');
        assert.equal(await lock.request(), 'active');
        assert.equal(api.requests, 1);

        // Hidden: the browser lets go
        document.show('hidden');
        api.sentinels[0].letGo();
        assert.equal(lock.state, 'released');
        assert.equal(api.requests, 1, 'not asked while hidden');

        document.show('visible');
        await settle();
        assert.equal(api.requests, 2);
        assert.equal(lock.state, 'active');

        // Asked again while active: nothing new
        await lock.request();
        assert.equal(api.requests, 2);

        await lock.release();
        assert.equal(lock.state, 'idle');
        assert.equal(api.sentinels[1].released, true);
        document.show('hidden');
        document.show('visible');
        await settle();
        assert.equal(api.requests, 2, 'not wanted any more');
        assert.deepEqual(states, ['pending', 'active', 'released', 'pending', 'active', 'idle']);

        lock.detach();
        assert.equal(document.listenerCount(), 0);
    },

    async 'the wake lock: missing, refused (power saving), wanted while hidden'() {
        const unsupported = new ScreenWakeLock({ wakeLock: null, document: fakeDocument() });
        assert.equal(unsupported.state, 'unsupported');
        assert.equal(await unsupported.request(), 'unsupported');

        const refusing = fakeWakeLock({ fail: 'NotAllowedError' });
        const document = fakeDocument();
        const denied = new ScreenWakeLock({ wakeLock: refusing, document }).attach();
        assert.equal(await denied.request(), 'denied');

        // Allowed later (the battery saver went off): the next return asks again
        refusing.fail = null;
        document.show('hidden');
        document.show('visible');
        await settle();
        assert.equal(denied.state, 'active');

        const hiddenDocument = fakeDocument('hidden');
        const api = fakeWakeLock();
        const waiting = new ScreenWakeLock({ wakeLock: api, document: hiddenDocument }).attach();
        assert.equal(await waiting.request(), 'pending');
        assert.equal(api.requests, 0);
        hiddenDocument.show('visible');
        await settle();
        assert.equal(waiting.state, 'active');
    },

    async 'the countdown\'s beeps: 3, 2, 1 and GO, those still ahead within 4 s'() {
        assert.deepEqual(countdownPlan(START, START - 10_000), []);
        assert.deepEqual(countdownPlan(START, START - 3_500), [
            { atMs: START - 3000, kind: 'tick' },
            { atMs: START - 2000, kind: 'tick' },
            { atMs: START - 1000, kind: 'tick' },
            { atMs: START, kind: 'go' },
        ]);
        assert.deepEqual(countdownPlan(START, START - 1_500).map((beep) => beep.kind), ['tick', 'go']);
        assert.deepEqual(countdownPlan(START, START - 2_050).map((beep) => beep.atMs), [START - 2000, START - 1000, START], 'a beep 50 ms late still plays');
        assert.deepEqual(countdownPlan(START, START + 500), [], 'GO is over');
        assert.deepEqual(countdownPlan(null, START), []);
    },

    async 'the beeps go on the audio clock once each; a new start cancels the old plan; nothing before the tap'() {
        FakeAudioContext.instances = [];
        const vibrations = [];
        const beeps = new Beeps({ AudioContext: FakeAudioContext, vibrate: (pattern) => vibrations.push(pattern) });

        assert.equal(beeps.scheduleCountdown(START, START - 3_500), 0, 'not unlocked: no sound');

        assert.equal(beeps.unlock(), true);
        const context = FakeAudioContext.instances[0];
        assert.equal(context.state, 'running', 'resumed inside the tap');
        assert.equal(context.sources, 1, 'a silent sound unlocks iOS');

        assert.equal(beeps.scheduleCountdown(START, START - 3_500), 4);
        assert.deepEqual(context.oscillators.map((oscillator) => oscillator.startAt), [100.5, 101.5, 102.5, 103.5]);
        assert.deepEqual(context.oscillators.map((oscillator) => oscillator.hz), [TONES.tick.frequency, TONES.tick.frequency, TONES.tick.frequency, TONES.go.frequency]);
        assert.equal(beeps.scheduleCountdown(START, START - 3_400), 0, 'every render asks again - each beep once');

        // Cancelled and scheduled again 30 s later: the old beeps are silenced
        beeps.scheduleCountdown(START + 30_000, START - 3_000);
        assert.ok(context.oscillators.slice(0, 4).every((oscillator) => oscillator.cancelled), 'old plan cancelled');
        assert.equal(context.oscillators.length, 4, 'the new start is not within reach yet');

        beeps.goVibration();
        assert.deepEqual(vibrations, [GO_VIBRATION]);

        assert.equal(beeps.alarm(), true);
        assert.equal(context.oscillators.length, 4 + ALARM_PATTERN_MS.length);
        assert.equal(vibrations.length, 2);

        // A detected finish: two rising tones and a short vibration
        const before = context.oscillators.length;
        assert.equal(beeps.chime(), true);
        assert.deepEqual(context.oscillators.slice(before).map((oscillator) => oscillator.hz), CHIME_PATTERN.map((tone) => tone.frequency));
        assert.deepEqual(vibrations.at(-1), CHIME_VIBRATION);

        beeps.close();
        assert.equal(context.state, 'closed');

        // No WebAudio: the vibration alone, nothing throws
        const silent = new Beeps({ AudioContext: null, vibrate: (pattern) => vibrations.push(pattern) });
        assert.equal(silent.unlock(), false);
        assert.equal(silent.alarm(), false);
        assert.equal(vibrations.length, 4, 'GO, the alarm, the chime and the silent alarm');
        const still = new Beeps({ AudioContext: null, vibrate: null });
        still.tap();
        still.goVibration();
        assert.equal(still.chime(), false, 'not unlocked: no chime, nothing throws');
    },

    async 'frame sizes: fitted into 640×360 (turned for a portrait frame), never upscaled, even'() {
        assert.deepEqual(fitWithin(1920, 1080), { width: 640, height: 360 });
        assert.deepEqual(fitWithin(1080, 1920), { width: 360, height: 640 });
        assert.deepEqual(fitWithin(1280, 960), { width: 480, height: 360 });
        assert.deepEqual(fitWithin(320, 240), { width: 320, height: 240 });
        assert.deepEqual(fitWithin(1919, 1079), { width: 640, height: 360 });
        assert.deepEqual(fitWithin(0, 1080), { width: 0, height: 0 });
    },

    async 'a frame\'s moment: its captureTime, else its presentationTime, else now - never a time from another clock'() {
        assert.equal(captureTimeOf(1000, { captureTime: 980.5, presentationTime: 990 }), 980.5);
        assert.equal(captureTimeOf(1000, { presentationTime: 990 }), 990);
        assert.equal(captureTimeOf(1000, {}), 1000);
        assert.equal(captureTimeOf(1000, null), 1000);
        assert.equal(captureTimeOf(10_000, { captureTime: START }), 10_000, 'an epoch value');
        assert.equal(captureTimeOf(10_000, { captureTime: 2_000 }), 10_000, 'too old');
        assert.equal(captureTimeOf(10_000, { captureTime: 0 }), 10_000);
    },

    async 'the frame loop: each camera frame with its capture time, asked first, one encoding at a time'() {
        const video = fakeVideo();
        const paint = canvases();
        const clock = timers();
        const frames = [];
        const asked = [];
        const grabber = new FrameGrabber({
            video,
            toServerMs: (perf) => START + perf,
            createCanvas: paint.create,
            now: clock.now,
            schedule: clock.schedule,
            cancel: clock.cancel,
        });

        grabber.start({
            wants: (captureMs) => {
                asked.push(captureMs);

                return captureMs !== START + 1_050;
            },
            onFrame: (blob, captureMs) => frames.push({ blob, captureMs }),
        });
        assert.equal(grabber.isRunning(), true);
        assert.equal(video.waiting(), 1);

        video.present(1_010, { captureTime: 1_000 });
        assert.deepEqual(asked, [START + 1_000]);
        assert.equal(paint.made[0].drawn.length, 1);
        assert.deepEqual(paint.made[0].drawn[0], { width: 640, height: 360 });
        assert.equal(video.waiting(), 1, 'waiting for the next frame already');

        // The encoding takes a while: the next frame is skipped, not queued
        video.present(1_110, { captureTime: 1_100 });
        assert.equal(asked.length, 1);
        assert.equal(grabber.skippedFrames, 1);

        await paint.finish();
        assert.deepEqual(frames.map((frame) => frame.captureMs), [START + 1_000]);
        assert.equal(frames[0].blob.quality, FRAME_QUALITY);
        assert.equal(frames[0].blob.type, 'image/jpeg');

        // Not wanted (the ring buffer has one this close): no drawing, no encoding
        video.present(1_060, { captureTime: 1_050 });
        assert.equal(paint.made[0].drawn.length, 1);

        video.present(1_210, { captureTime: 1_199.7 });
        await paint.finish();
        assert.deepEqual(frames.map((frame) => frame.captureMs), [START + 1_000, START + 1_200], 'whole milliseconds');

        // Stopped: no callback left, a late encoding is not handed over
        video.present(1_310, { captureTime: 1_300 });
        grabber.stop();
        assert.equal(video.waiting(), 0);
        await paint.finish();
        assert.equal(frames.length, 2);
        assert.equal(clock.active(), 0);
    },

    async 'no frame callback: the frame on screen after 250 ms; without requestVideoFrameCallback the animation frame'() {
        const video = fakeVideo();
        const paint = canvases();
        const clock = timers();
        const frames = [];
        const grabber = new FrameGrabber({ video, toServerMs: (perf) => START + perf, createCanvas: paint.create, now: clock.now, schedule: clock.schedule, cancel: clock.cancel });

        grabber.start({ wants: () => true, onFrame: (blob, captureMs) => frames.push(captureMs) });
        await clock.advance(FRAME_FALLBACK_MS);
        await paint.finish();
        assert.deepEqual(frames, [START + FRAME_FALLBACK_MS], 'the moment of the fallback');
        assert.equal(video.waiting(), 1, 'the frame callback asked again');
        grabber.stop();

        const plain = fakeVideo({ frameCallbacks: false });
        const animationFrames = [];
        const rafGrabber = new FrameGrabber({
            video: plain,
            toServerMs: (perf) => START + perf,
            createCanvas: paint.create,
            now: clock.now,
            schedule: clock.schedule,
            cancel: clock.cancel,
            requestAnimationFrame: (callback) => animationFrames.push(callback),
        });
        const rafFrames = [];
        rafGrabber.start({ wants: () => true, onFrame: (blob, captureMs) => rafFrames.push(captureMs) });
        assert.equal(animationFrames.length, 1);
        animationFrames[0]();
        await paint.finish();
        assert.equal(rafFrames.length, 1);
        rafGrabber.stop();
    },

    async 'photos: the next frame, downscaled or at full resolution, with its capture time - none without frames'() {
        const video = fakeVideo();
        const paint = canvases();
        const clock = timers();
        const grabber = new FrameGrabber({ video, toServerMs: (perf) => START + perf, createCanvas: paint.create, now: clock.now, schedule: clock.schedule, cancel: clock.cancel });

        const small = grabber.small();
        await settle();
        video.present(5_010, { captureTime: 5_000 });
        await settle();
        await paint.finish();
        const photo = await small;
        assert.equal(photo.captureMs, START + 5_000);
        assert.deepEqual([photo.width, photo.height], [640, 360]);
        assert.equal(photo.blob.quality, PHOTO_QUALITY);
        assert.deepEqual([photo.blob.width, photo.blob.height], [640, 360]);
        assert.equal(paint.made.at(-1).width, 0, 'the canvas is given back');

        const still = grabber.still();
        await settle();
        video.present(6_010, { captureTime: 5_999.6 });
        await settle();
        await paint.finish();
        const full = await still;
        assert.deepEqual([full.width, full.height], [1920, 1080]);
        assert.deepEqual([full.blob.width, full.blob.height], [1920, 1080]);
        assert.equal(full.blob.quality, STILL_QUALITY);
        assert.equal(full.captureMs, START + 6_000, 'whole milliseconds, as the server stores them');
        assert.equal(paint.made.at(-1).width, 0);

        const dark = new FrameGrabber({ video: fakeVideo({ width: 0, height: 0 }), toServerMs: (perf) => perf, createCanvas: paint.create, now: clock.now, schedule: clock.schedule, cancel: clock.cancel });
        assert.equal(dark.hasFrames(), false);
        assert.equal(await dark.small(), null);
        assert.equal(await dark.still(), null);
    },

    async 'the detector feed: each camera frame asked first, one bitmap at a time, 320 px on the longer side, its capture time on the performance clock'() {
        const video = fakeVideo();
        const paint = canvases();
        const clock = timers();
        const made = [];
        const pending = [];
        const grabber = new FrameGrabber({ video, toServerMs: (perf) => START + perf, createCanvas: paint.create, now: clock.now, schedule: clock.schedule, cancel: clock.cancel });
        const feed = new DetectorFeed({
            grabber,
            createImageBitmap: (source, options) => new Promise((resolve) => {
                made.push({ source, options });
                pending.push(() => resolve({ width: options.resizeWidth, height: options.resizeHeight, closed: false, close() { this.closed = true; } }));
            }),
        });
        const asked = [];
        const bitmaps = [];
        let wanted = true;

        feed.startFeed({
            wants: (perf) => {
                asked.push(perf);

                return wanted;
            },
            onBitmap: (bitmap, perf) => bitmaps.push({ bitmap, perf }),
        });
        assert.equal(feed.isFeeding(), true);
        assert.equal(video.waiting(), 1);

        video.present(1_010, { captureTime: 1_000 });
        assert.deepEqual(asked, [1_000], 'the capture time on performance.now()\'s clock - the finish machine\'s');
        assert.equal(made.length, 1);
        assert.equal(made[0].source, video, 'the very <video> - the camera track is never touched');
        assert.deepEqual(made[0].options, { resizeWidth: DETECTOR_SIDE, resizeHeight: 180, resizeQuality: 'low' });

        // The bitmap is not made yet: the next frame is not even asked for
        video.present(1_210, { captureTime: 1_200 });
        assert.deepEqual(asked, [1_000]);

        pending.shift()();
        await settle();
        assert.equal(bitmaps.length, 1);
        assert.equal(bitmaps[0].perf, 1_000);
        assert.deepEqual([bitmaps[0].bitmap.width, bitmaps[0].bitmap.height], [320, 180]);

        // Not wanted (the pacing says wait): nothing made
        wanted = false;
        video.present(1_410, { captureTime: 1_400 });
        assert.equal(made.length, 1);

        // The ring buffer's loop runs next to it on the same <video>, each with its own frame callback
        const frames = [];
        grabber.start({ wants: () => true, onFrame: (blob, captureMs) => frames.push(captureMs) });
        assert.equal(video.waiting(), 2);
        wanted = true;
        video.present(1_610, { captureTime: 1_600 });
        await paint.finish();
        assert.deepEqual(frames, [START + 1_600]);
        assert.equal(made.length, 2);

        // Stopped while a bitmap is being made: it is closed, never handed over; the ring buffer goes on
        feed.stopFeed();
        assert.equal(feed.isFeeding(), false);
        assert.equal(video.waiting(), 1, 'only the ring buffer\'s callback is left');
        pending.shift()();
        await settle();
        assert.equal(bitmaps.length, 1, 'a bitmap of a stopped feed is not handed over');
        assert.equal(grabber.isRunning(), true);
        grabber.stop();
    },

    async 'the detector feed falls back to a small canvas where createImageBitmap refuses its resize options; a portrait frame stays portrait'() {
        const video = fakeVideo({ width: 1080, height: 1920 });
        const paint = canvases();
        const clock = timers();
        const sources = [];
        const grabber = new FrameGrabber({ video, toServerMs: (perf) => START + perf, createCanvas: paint.create, now: clock.now, schedule: clock.schedule, cancel: clock.cancel });
        const feed = new DetectorFeed({
            grabber,
            createImageBitmap: (source, options) => {
                sources.push({ source, options });

                if (options !== undefined) {
                    return Promise.reject(new TypeError('resize options not supported'));
                }

                return Promise.resolve({ width: source.width, height: source.height, close() {} });
            },
        });
        const bitmaps = [];
        feed.startFeed({ wants: () => true, onBitmap: (bitmap) => bitmaps.push(bitmap) });

        video.present(10, { captureTime: 5 });
        await settle();
        assert.deepEqual([bitmaps[0].width, bitmaps[0].height], [180, 320], 'portrait: 320 on the longer side');
        assert.equal(sources.length, 2, 'tried with the options once, then the canvas');

        video.present(220, { captureTime: 215 });
        await settle();
        assert.equal(sources.length, 3, 'the options are not tried again');
        assert.equal(sources[2].options, undefined);
        assert.equal(bitmaps.length, 2);
        feed.stopFeed();
    },

    async 'how sharp a still is: the Laplacian variance - edges score, a flat or blurred frame does not; still({judge}) asks it of the canvas'() {
        const size = 8;
        const flat = new Uint8ClampedArray(size * size * 4).fill(128);
        const stripes = new Uint8ClampedArray(size * size * 4);
        const soft = new Uint8ClampedArray(size * size * 4);

        for (let y = 0; y < size; y++) {
            for (let x = 0; x < size; x++) {
                const p = (y * size + x) * 4;
                const sharp = x % 2 === 0 ? 255 : 0;
                const blurred = 128 + (x % 2 === 0 ? 20 : -20);
                stripes.fill(sharp, p, p + 3);
                soft.fill(blurred, p, p + 3);
                stripes[p + 3] = 255;
                soft[p + 3] = 255;
            }
        }

        assert.equal(laplacianVariance(flat, size, size), 0);
        assert.ok(laplacianVariance(stripes, size, size) > laplacianVariance(soft, size, size) * 10);
        assert.ok(laplacianVariance(soft, size, size) > 0);
        assert.equal(laplacianVariance(flat, 2, 2), 0, 'too small to judge');
        assert.equal(laplacianVariance(new Uint8ClampedArray(4), size, size), 0, 'missing pixels');

        // The judge draws the full-resolution canvas to a 160 px copy and reads its pixels
        const drawnOn = [];
        const judge = canvasSharpness(() => ({
            width: 0,
            height: 0,
            getContext: () => ({
                drawImage: (source, x, y, width, height) => drawnOn.push({ width, height }),
                getImageData: (x, y, width, height) => ({ data: new Uint8ClampedArray(width * height * 4).fill(200) }),
            }),
        }));
        assert.equal(judge({}, { width: 1920, height: 1080 }), 0);
        assert.deepEqual(drawnOn, [{ width: SHARPNESS_WIDTH, height: 90 }]);
        assert.equal(canvasSharpness(() => ({ getContext: () => null }))({}, { width: 10, height: 10 }), null, 'pixels that can not be read');

        // still({judge}): the frame's sharpness with the photo - null when the judge fails; a plain still as always
        const video = fakeVideo();
        const paint = canvases();
        const clock = timers();
        const grabber = new FrameGrabber({ video, toServerMs: (perf) => START + perf, createCanvas: paint.create, now: clock.now, schedule: clock.schedule, cancel: clock.cancel });
        const judged = [];
        const shot = grabber.still({ judge: (canvas, frameSize) => {
            judged.push(frameSize);

            return 42.5;
        } });
        await settle();
        video.present(8_010, { captureTime: 8_000 });
        await settle();
        await paint.finish();
        const result = await shot;
        assert.equal(result.captureMs, START + 8_000);
        assert.equal(result.sharpness, 42.5);
        assert.deepEqual(judged, [{ width: 1920, height: 1080 }], 'the full-resolution frame, before it is encoded');

        const failing = grabber.still({ judge: () => { throw new Error('tainted'); } });
        await settle();
        video.present(8_510, { captureTime: 8_500 });
        await settle();
        await paint.finish();
        assert.equal((await failing).sharpness, null);

        const plain = grabber.still();
        await settle();
        video.present(9_010, { captureTime: 9_000 });
        await settle();
        await paint.finish();
        assert.equal('sharpness' in (await plain), false, 'a plain still is as it always was');
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
