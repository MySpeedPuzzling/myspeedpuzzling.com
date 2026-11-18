// Runs the judge page's continuous scanning (judge-speed J4) under node and prints {scenario: "ok" | error} as JSON -
// tests/LiveEntryScanScriptsTest.php asserts every one is "ok": assets/live_entry_scan_session.js (the warm camera's
// state machine, scanDecision(), resumesAfterSave()) on the real camera / read-loop building blocks of
// assets/official_results_scan.js (openQrCamera(), cameraOf(), detectLoop(), qrReader()) with a fake mediaDevices, fake
// tracks that can end, a fake <video>, a fake reader and fake timers.

import assert from 'node:assert/strict';
import { IDLE_MS, OUT_OF_VIEW_MS, resumesAfterSave, scanDecision, ScanSession } from '../assets/live_entry_scan_session.js';
import { cameraOf, detectLoop, openQrCamera, QR_CAMERA, qrReader } from '../assets/official_results_scan.js';

const TAG_12 = 'https://myspeedpuzzling.com/live/018d0020-0000-0000-0000-000000000001/t/12';
const TAG_13 = 'https://myspeedpuzzling.com/live/018d0020-0000-0000-0000-000000000001/t/13';

function fakeTrack() {
    const listeners = [];

    return {
        readyState: 'live',
        stops: 0,
        stop() {
            // Like a browser: stopping a track ourselves fires no `ended`
            this.stops += 1;
            this.readyState = 'ended';
        },
        addEventListener(type, callback) {
            if (type === 'ended') {
                listeners.push(callback);
            }
        },
        // The browser ends the capture (iOS: a call, another app, Siri)
        end() {
            this.readyState = 'ended';
            listeners.splice(0).forEach((callback) => callback());
        },
    };
}

function fakeMediaDevices({ refuse = false, delay = null } = {}) {
    const devices = {
        calls: [],
        tracks: [],
        pending: [],
        getUserMedia(constraints) {
            devices.calls.push(constraints);

            if (refuse) {
                return Promise.reject(new Error('NotAllowedError'));
            }

            const track = fakeTrack();
            devices.tracks.push(track);
            const stream = { getTracks: () => [track], getVideoTracks: () => [track] };

            if (delay === 'manual') {
                return new Promise((resolve) => devices.pending.push(() => resolve(stream)));
            }

            return Promise.resolve(stream);
        },
    };

    return devices;
}

function fakeVideo() {
    return {
        readyState: 4,
        srcObject: null,
        muted: false,
        plays: 0,
        pauses: 0,
        attributes: {},
        setAttribute(name, value) {
            this.attributes[name] = value;
        },
        play() {
            this.plays += 1;

            return Promise.resolve();
        },
        pause() {
            this.pauses += 1;
        },
    };
}

function fakeTimers() {
    const timers = { now: 1_800_000_000_000, list: new Map(), next: 1 };
    timers.set = (callback, ms) => {
        const id = timers.next++;
        timers.list.set(id, { at: timers.now + ms, callback });

        return id;
    };
    timers.clear = (id) => timers.list.delete(id);
    timers.advance = (ms) => {
        const until = timers.now + ms;

        for (;;) {
            const due = [...timers.list.entries()].filter(([, timer]) => timer.at <= until).sort((a, b) => a[1].at - b[1].at)[0];

            if (!due) {
                break;
            }

            timers.list.delete(due[0]);
            timers.now = due[1].at;
            due[1].callback();
        }

        timers.now = until;
    };

    return timers;
}

// A read loop the scenario drives by hand: frame(raw) = one read with that code (null = nothing)
function fakeLoop() {
    const loop = { running: [], started: 0 };
    loop.start = ({ video, reader, onCode }) => {
        const run = { video, reader, onCode, stopped: false };
        loop.started += 1;
        loop.running.push(run);

        return () => {
            run.stopped = true;
        };
    };
    loop.frame = (raw) => {
        loop.running.filter((run) => !run.stopped).forEach((run) => raw !== null && run.onCode(raw));
    };
    loop.active = () => loop.running.filter((run) => !run.stopped).length;

    return loop;
}

const tick = () => new Promise((resolve) => setImmediate(resolve));

function setup({ devices = fakeMediaDevices(), reader = { detect: async () => [] } } = {}) {
    const timers = fakeTimers();
    const loop = fakeLoop();
    const readerCalls = { count: 0 };
    const session = new ScanSession({
        openCamera: () => openQrCamera({ mediaDevices: devices }),
        reader: async () => {
            readerCalls.count += 1;

            return reader;
        },
        loop: loop.start,
        now: () => timers.now,
        setTimer: timers.set,
        clearTimer: timers.clear,
    });
    const states = [];
    session.subscribe((state, reason) => states.push(reason === null ? state : `${state}:${reason}`));
    const video = fakeVideo();
    const codes = [];
    const errors = [];
    const scan = (options = {}) => session.scan({ video, onCode: (raw) => codes.push(raw), onError: (reason) => errors.push(reason), ...options });

    return { session, devices, timers, loop, video, codes, errors, states, scan, readerCalls };
}

const scenarios = {
    async 'one getUserMedia across scan → park → scan, three tags in a row'() {
        const { session, devices, loop, video, codes, scan, states } = setup();

        assert.equal(await scan(), 'scanning');
        assert.equal(devices.calls.length, 1);
        assert.deepEqual(devices.calls[0], QR_CAMERA);
        assert.notEqual(video.srcObject, null, 'the stream in the video');
        assert.equal(video.attributes.playsinline, '');
        assert.equal(video.muted, true);

        for (const [n, tag] of [TAG_12, TAG_13, `${TAG_12}0`].entries()) {
            if (n > 0) {
                assert.equal(await scan(), 'scanning', 'warm: no new camera');
            }

            loop.frame(tag);
            assert.equal(session.state, 'parked', 'acted on = parked at once');
            assert.equal(video.srcObject, null, 'the video taken off the stream');
            assert.equal(devices.tracks[0].readyState, 'live', 'the camera stays on while parked');
        }

        assert.deepEqual(codes, [TAG_12, TAG_13, `${TAG_12}0`]);
        assert.equal(devices.calls.length, 1, 'getUserMedia exactly once');
        assert.equal(video.plays, 3, 'play() again on every attach (Safari)');
        assert.deepEqual(states.slice(0, 3), ['starting', 'parked', 'scanning']);
    },

    async 'a code is acted on once per scan - a second frame or a read after park never is'() {
        const { session, loop, codes, scan } = setup();

        await scan();
        loop.frame(TAG_12);
        loop.frame(TAG_13);
        loop.frame(TAG_12);
        assert.deepEqual(codes, [TAG_12]);
        assert.equal(loop.active(), 0, 'the read loop stopped');

        await scan();
        session.park();
        loop.frame(TAG_13);
        assert.deepEqual(codes, [TAG_12], 'nothing acted on after park');
    },

    async 'the just-saved tag is ignored while in view, acts again after 1.5 s without it, another tag at once'() {
        const { timers, loop, codes, scan } = setup();

        await scan();
        loop.frame(TAG_12);
        // The card, Finished now, Save - 20 s later the scanner is back with the same tag in front of the lens
        timers.advance(20_000);
        await scan({ ignore: TAG_12 });

        for (let i = 0; i < 30; i++) {
            timers.advance(100);
            loop.frame(TAG_12);
        }

        assert.deepEqual(codes, [TAG_12], '3 s of the saved tag in view: nothing');

        // Out of view for less than 1.5 s, back again: still the same tag held there
        timers.advance(OUT_OF_VIEW_MS - 100);
        loop.frame(TAG_12);
        assert.deepEqual(codes, [TAG_12]);

        // Another tag acts at once
        timers.advance(100);
        loop.frame(TAG_13);
        assert.deepEqual(codes, [TAG_12, TAG_13]);

        // The saved tag after a real absence acts again (the judge pointed back at it on purpose)
        await scan({ ignore: TAG_13 });
        timers.advance(100);
        loop.frame(TAG_13);
        timers.advance(OUT_OF_VIEW_MS);
        loop.frame(TAG_13);
        assert.deepEqual(codes, [TAG_12, TAG_13, TAG_13]);

        // A tap on Scan (no ignore) acts on any tag at once
        await scan();
        loop.frame(TAG_13);
        assert.deepEqual(codes, [TAG_12, TAG_13, TAG_13, TAG_13]);
    },

    async 'scanDecision is pure: same raw in view ignored, out of view or another acts'() {
        assert.equal(scanDecision(null, TAG_12, 1000).decision, 'act');
        assert.deepEqual(scanDecision(null, TAG_12, 1000).memory, { raw: TAG_12, seenAt: 1000 });
        assert.equal(scanDecision({ raw: TAG_12, seenAt: 1000 }, TAG_12, 2499).decision, 'ignore');
        assert.deepEqual(scanDecision({ raw: TAG_12, seenAt: 1000 }, TAG_12, 2499).memory, { raw: TAG_12, seenAt: 2499 }, 'every sighting moves it');
        assert.equal(scanDecision({ raw: TAG_12, seenAt: 1000 }, TAG_12, 2500).decision, 'act');
        assert.equal(scanDecision({ raw: TAG_12, seenAt: 1000 }, TAG_13, 1001).decision, 'act');
        assert.equal(scanDecision({ raw: TAG_12, seenAt: 1000 }, `${TAG_12} `, 1001).decision, 'act', 'raw texts compared exactly');
    },

    async 'resumes only an entry a scan opened, the saved one, with the preference not off'() {
        const scanned = { ref: 'participant_round:1', raw: TAG_12 };

        assert.equal(resumesAfterSave(scanned, 'participant_round:1', { scanAfterSave: true }), true);
        assert.equal(resumesAfterSave(scanned, 'participant_round:1', {}), true, 'on by default');
        assert.equal(resumesAfterSave(scanned, 'participant_round:1', { scanAfterSave: false }), false);
        assert.equal(resumesAfterSave(scanned, 'participant_round:2', { scanAfterSave: true }), false, 'another entry saved');
        assert.equal(resumesAfterSave(null, 'participant_round:1', { scanAfterSave: true }), false, 'typed, not scanned');
    },

    async 'parked 90 s without a scan: the camera is released'() {
        const { session, devices, timers, loop, scan, states } = setup();

        await scan();
        loop.frame(TAG_12);
        timers.advance(IDLE_MS - 1);
        assert.equal(devices.tracks[0].readyState, 'live');
        timers.advance(1);
        assert.equal(session.state, 'cold');
        assert.equal(devices.tracks[0].stops, 1, 'the track stopped');
        assert.equal(states.at(-1), 'cold:idle');

        assert.equal(await scan(), 'scanning', 'the next scan asks again');
        assert.equal(devices.calls.length, 2);
    },

    async 'scanning 90 s with nothing acted on releases too - a phone put down with the scanner open'() {
        const { session, devices, timers, loop, scan, states } = setup();

        await scan();
        timers.advance(60_000);
        loop.frame(null);
        timers.advance(30_000);
        assert.equal(session.state, 'cold');
        assert.equal(devices.tracks[0].readyState, 'ended');
        assert.equal(loop.active(), 0);
        assert.equal(states.at(-1), 'cold:idle');
    },

    async 'the idle time runs from the last use - park() of a parked camera does not extend it'() {
        const { session, devices, timers, loop, scan } = setup();

        await scan();
        loop.frame(TAG_12);
        timers.advance(60_000);

        // Other overlays closing (a typed table opened, the sheet closed) park again - no new 90 s
        session.park();
        session.park();
        timers.advance(30_000);
        assert.equal(session.state, 'cold');
        assert.equal(devices.tracks[0].stops, 1);
    },

    async 'hidden / pagehide / freeze release; the next scan reopens'() {
        for (const reason of ['hidden', 'pagehide', 'freeze', 'disconnect']) {
            const { session, devices, loop, scan, states } = setup();

            await scan();
            session.release(reason);
            assert.equal(session.state, 'cold', reason);
            assert.equal(devices.tracks[0].stops, 1, reason);
            assert.equal(loop.active(), 0, reason);
            assert.equal(states.at(-1), `cold:${reason}`);
            loop.frame(TAG_12);
            assert.equal(await scan(), 'scanning');
            assert.equal(devices.calls.length, 2, reason);
        }
    },

    async 'the browser ends the track: cold, the next scan asks again'() {
        const { session, devices, loop, scan, states, codes } = setup();

        await scan();
        devices.tracks[0].end();
        assert.equal(session.state, 'cold');
        assert.equal(states.at(-1), 'cold:ended');
        assert.equal(loop.active(), 0);
        loop.frame(TAG_12);
        assert.deepEqual(codes, []);
        assert.equal(await scan(), 'scanning');
        assert.equal(devices.calls.length, 2);

        // Ended without the event reaching the page (a background tab): alive() says so, a fresh camera
        devices.tracks[1].readyState = 'ended';
        session.park();
        assert.equal(await scan(), 'scanning');
        assert.equal(devices.calls.length, 3);
        assert.equal(devices.tracks[1].stops, 1, 'the dead one stopped for good');
    },

    async 'scan() twice while the camera starts = one getUserMedia, the later request served'() {
        const devices = fakeMediaDevices({ delay: 'manual' });
        const { session, loop, scan, video } = setup({ devices });

        const first = scan();
        const second = scan();
        assert.equal(devices.calls.length, 1);
        assert.equal(session.state, 'starting');
        devices.pending.shift()();
        assert.equal(await first, 'cancelled');
        assert.equal(await second, 'scanning');
        assert.equal(loop.active(), 1, 'one read loop');
        assert.notEqual(video.srcObject, null);
    },

    async 'released while starting: the camera that arrives is stopped at once, never shown'() {
        const devices = fakeMediaDevices({ delay: 'manual' });
        const { session, loop, scan, video } = setup({ devices });

        const pending = scan();
        session.release('hidden');
        devices.pending.shift()();
        assert.equal(await pending, 'cancelled');
        assert.equal(devices.tracks[0].stops, 1);
        assert.equal(session.state, 'cold');
        assert.equal(loop.active(), 0);
        assert.equal(video.srcObject, null);

        // Visible again: a scan asks anew (and is served, not joined to the dropped one)
        const again = scan();
        assert.equal(devices.calls.length, 2);
        devices.pending.shift()();
        assert.equal(await again, 'scanning');
    },

    async 'closed while starting: the camera arrives parked (warm), no reading, the idle timer runs'() {
        const devices = fakeMediaDevices({ delay: 'manual' });
        const { session, loop, scan, timers } = setup({ devices });

        const pending = scan();
        session.park();
        devices.pending.shift()();
        assert.equal(await pending, 'cancelled');
        assert.equal(session.state, 'parked');
        assert.equal(loop.active(), 0);
        timers.advance(IDLE_MS);
        assert.equal(session.state, 'cold');
        assert.equal(devices.tracks[0].stops, 1);
    },

    async 'camera refused or missing: "camera" once, cold, nothing left running'() {
        const { session, errors, scan, states } = setup({ devices: fakeMediaDevices({ refuse: true }) });

        assert.equal(await scan(), 'camera');
        assert.deepEqual(errors, ['camera']);
        assert.equal(session.state, 'cold');
        assert.deepEqual(states, ['starting', 'cold:camera']);

        assert.deepEqual(await openQrCamera({ mediaDevices: undefined }), { ok: false, reason: 'camera' });
        assert.deepEqual(await openQrCamera({ mediaDevices: {} }), { ok: false, reason: 'camera' });
    },

    async 'no QR decoder: "decoder" once and the camera released'() {
        const { session, devices, errors, scan } = setup({ reader: null });

        assert.equal(await scan(), 'decoder');
        assert.deepEqual(errors, ['decoder']);
        assert.equal(session.state, 'cold');
        assert.equal(devices.tracks[0].stops, 1);
    },

    async 'the camera ends while the decoder loads: "camera" (Try again), never shown, no automatic retry signal'() {
        const devices = fakeMediaDevices();
        const timers = fakeTimers();
        let loaded = null;
        const session = new ScanSession({
            openCamera: () => openQrCamera({ mediaDevices: devices }),
            reader: () => new Promise((resolve) => {
                loaded = () => resolve({ detect: async () => [] });
            }),
            loop: () => () => {},
            now: () => timers.now,
            setTimer: timers.set,
            clearTimer: timers.clear,
        });
        const states = [];
        session.subscribe((state, reason) => states.push(`${state}:${reason}`));
        const errors = [];
        const video = fakeVideo();
        const pending = session.scan({ video, onCode: () => {}, onError: (reason) => errors.push(reason) });
        await tick();
        devices.tracks[0].readyState = 'ended';
        loaded();
        assert.equal(await pending, 'camera');
        assert.deepEqual(errors, ['camera']);
        assert.equal(states.at(-1), 'cold:camera');
        assert.equal(video.srcObject, null);
        assert.equal(devices.tracks[0].stops, 1);
    },

    async 'cameraOf: attach / detach / alive / release / onEnded'() {
        const track = fakeTrack();
        const camera = cameraOf({ getTracks: () => [track], getVideoTracks: () => [track] });
        const video = fakeVideo();
        let ended = 0;
        camera.onEnded(() => ended++);

        camera.attach(video);
        assert.equal(video.srcObject, camera.stream);
        assert.equal(video.plays, 1);
        camera.detach(video);
        assert.equal(video.srcObject, null);
        assert.equal(video.pauses, 1);
        assert.equal(camera.alive(), true);
        camera.release();
        assert.equal(camera.alive(), false);
        assert.equal(track.stops, 1);
        assert.equal(ended, 0, 'our own stop fires no ended');

        const other = fakeTrack();
        const second = cameraOf({ getTracks: () => [other], getVideoTracks: () => [other] });
        second.onEnded(() => ended++);
        other.end();
        assert.equal(ended, 1);
        assert.equal(second.alive(), false);
        assert.equal(cameraOf({ getTracks: () => [], getVideoTracks: () => [] }).alive(), false, 'no track = not alive');
    },

    async 'detectLoop: every code handed on, a read still running at stop() dropped, frames before data skipped'() {
        const queue = [];
        const schedule = (read) => queue.push(read);
        const answers = [[], [{ rawValue: '' }, { rawValue: TAG_12 }], [{ rawValue: TAG_13 }]];
        let release = null;
        const reader = {
            detects: 0,
            detect() {
                reader.detects += 1;

                if (answers.length === 0) {
                    return new Promise((resolve) => {
                        release = () => resolve([{ rawValue: 'late' }]);
                    });
                }

                return Promise.resolve(answers.shift());
            },
        };
        const video = fakeVideo();
        const codes = [];
        const stop = detectLoop({ video, reader, onCode: (raw) => codes.push(raw), schedule });

        video.readyState = 1;
        await queue.shift()();
        assert.equal(reader.detects, 0, 'no frame yet - not read');
        video.readyState = 4;

        for (let i = 0; i < 3; i++) {
            await queue.shift()();
        }

        assert.deepEqual(codes, [TAG_12, TAG_13], 'empty and missing codes skipped, the loop goes on');
        const last = queue.shift()();
        stop();
        release();
        await last;
        assert.deepEqual(codes, [TAG_12, TAG_13], 'the read answered after stop() is dropped');
        assert.equal(queue.length, 0, 'nothing scheduled after stop()');

        // A detect that throws: the next frame is read
        const failing = [];
        const thrower = { n: 0, detect: async () => { thrower.n += 1; if (thrower.n === 1) { throw new Error('frame'); } return [{ rawValue: TAG_12 }]; } };
        const seen = [];
        const stopSecond = detectLoop({ video, reader: thrower, onCode: (raw) => seen.push(raw), schedule: (read) => failing.push(read) });
        await failing.shift()();
        await failing.shift()();
        stopSecond();
        assert.deepEqual(seen, [TAG_12]);
    },

    async 'qrReader: one detector per page (the browser\'s own BarcodeDetector), concurrent calls share it'() {
        let constructed = 0;
        globalThis.window = {
            BarcodeDetector: class {
                static async getSupportedFormats() {
                    return ['qr_code', 'ean_13'];
                }

                constructor(options) {
                    constructed += 1;
                    assert.deepEqual(options, { formats: ['qr_code'] });
                }

                async detect() {
                    return [];
                }
            },
        };

        try {
            const [a, b] = await Promise.all([qrReader(), qrReader()]);
            const c = await qrReader();
            assert.equal(constructed, 1);
            assert.equal(a, b);
            assert.equal(a, c);
        } finally {
            delete globalThis.window;
        }
    },

    async 'the reader is asked for on every warm scan (memoised in qrReader) - never a second camera for it'() {
        const { devices, loop, scan, readerCalls } = setup();

        await scan();
        loop.frame(TAG_12);
        await scan();
        assert.equal(readerCalls.count, 2);
        assert.equal(devices.calls.length, 1);
        await tick();
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
