// Runs the online review's pure modules - assets/online/review_logic.js (the review page) and
// assets/online/review_control.js (the control room) - through their scenarios and prints {scenario: "ok" | error} as
// JSON. tests/OnlineReviewScriptTest.php asserts every one is "ok".

import assert from 'node:assert/strict';
import {
    formatAdjustment,
    formatElapsed,
    frameSlices,
    momentConfirmation,
    nearestFrame,
    nextToReview,
    piecesConfirmation,
    piecesPlaced,
    resultSeconds,
    reviewKey,
    stepIndex,
    summarize,
} from '../assets/online/review_logic.js';
import {
    agoParts,
    countsOf,
    matchesFilter,
    ONLINE_FRESH_MS,
    participantView,
    photoChanged,
} from '../assets/online/review_control.js';

// The start on a whole second
const START = 1_792_000_000_000;
const TAP = START + (47 * 60 + 12) * 1000 + 345;

/**
 * A burst as frame_buffer.js builds it: frames 100 ms apart around `momentMs`, `size` bytes each, one after another.
 */
function burstManifest(momentMs, { before = 5, after = 5, size = 1000, jitter = 0 } = {}) {
    const manifest = [];
    let offset = 0;

    for (let step = -before; step <= after; step++) {
        manifest.push({ offset, length: size, captureMs: momentMs + step * 100 + jitter * (step % 2) });
        offset += size;
    }

    return manifest;
}

function participant(overrides = {}) {
    return {
        participantId: 'p-1',
        entryId: 'e-1',
        name: 'Olivia Online',
        country: 'cz',
        seen: true,
        readyAtMs: START - 600_000,
        activeDeviceId: 'phone-a',
        readyClockRttMs: 40,
        presence: {
            lastSeenMs: START - 5_000,
            deviceId: 'phone-a',
            visible: true,
            camera: { ok: true, w: 1920, h: 1080, fps: 30 },
            clock: { offsetMs: -12.5, rttMs: 38 },
            outbox: { events: 0, media: 2 },
        },
        finish: null,
        reported: null,
        review: 'pending',
        flags: [],
        photo: null,
        ...overrides,
    };
}

const scenarios = {
    async 'the official time of a moment: whole seconds since the start, rounded down'() {
        assert.equal(resultSeconds(TAP, START), 47 * 60 + 12);
        assert.equal(resultSeconds(START + 999, START), 0);
        assert.equal(resultSeconds(START + 1000, START), 1);
        assert.equal(resultSeconds(START - 1, START), null, 'before the start gives no time');
    },

    async 'a burst manifest becomes its frames in capture order, each a slice of the one object'() {
        const manifest = burstManifest(TAP, { size: 900 });
        const shuffled = [manifest[3], manifest[0], ...manifest.slice(4), manifest[1], manifest[2]];
        const frames = frameSlices(shuffled, 900 * manifest.length);

        assert.equal(frames.length, 11);
        assert.deepEqual(frames.map((frame) => frame.index), [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10]);
        assert.deepEqual(frames.map((frame) => frame.offset), [0, 900, 1800, 2700, 3600, 4500, 5400, 6300, 7200, 8100, 9000]);
        assert.ok(frames.every((frame, index) => index === 0 || frame.captureMs > frames[index - 1].captureMs));
        assert.equal(frames[5].captureMs, TAP);
    },

    async 'a frame outside the object, or without a capture time, is left out'() {
        const frames = frameSlices([
            { offset: 0, length: 500, captureMs: START + 1 },
            { offset: 500, length: 600, captureMs: START + 2 },
            // past the end of the 1100-byte object
            { offset: 1000, length: 200, captureMs: START + 3 },
            { offset: 100, length: 0, captureMs: START + 4 },
            { offset: -1, length: 10, captureMs: START + 5 },
            { offset: 10, length: 10, captureMs: 'soon' },
            null,
        ], 1100);

        assert.deepEqual(frames.map((frame) => frame.captureMs), [START + 1, START + 2]);
        // Without a known size every well-formed frame is taken
        assert.equal(frameSlices([{ offset: 900, length: 200, captureMs: START }], null).length, 1);
        assert.deepEqual(frameSlices(null), []);
    },

    async 'the moment of a frame is its capture time to the millisecond'() {
        const frames = frameSlices([{ offset: 0, length: 10, captureMs: START + 1234.6 }, { offset: 10, length: 10, captureMs: START + 1334.4 }]);

        assert.deepEqual(frames.map((frame) => frame.momentMs), [START + 1235, START + 1334]);
    },

    async 'the burst opens at the frame closest to the tap - the earlier one on a tie'() {
        const frames = frameSlices(burstManifest(TAP));

        assert.equal(nearestFrame(frames, TAP), 5);
        assert.equal(nearestFrame(frames, TAP + 49), 5);
        assert.equal(nearestFrame(frames, TAP + 51), 6);
        assert.equal(nearestFrame(frames, TAP + 50), 5, 'a tie takes the earlier frame');
        assert.equal(nearestFrame(frames, TAP - 10_000), 0);
        assert.equal(nearestFrame([], TAP), -1);
    },

    async 'stepping through frames and participants stops at the ends'() {
        assert.equal(stepIndex(5, 1, 11), 6);
        assert.equal(stepIndex(10, 1, 11), 10);
        assert.equal(stepIndex(0, -1, 11), 0);
        assert.equal(stepIndex(-1, 1, 3), 0, 'nothing selected: down picks the first');
        assert.equal(stepIndex(-1, -1, 3), 2, 'nothing selected: up picks the last');
        assert.equal(stepIndex(0, 1, 0), -1);
    },

    async 'a confirmed moment is the button when it is a tap, the reviewer when moved'() {
        const taps = [{ momentMs: TAP }, { momentMs: TAP + 60_000 }];

        assert.deepEqual(momentConfirmation(TAP, taps), { momentMs: TAP, source: 'button', pieces: null });
        assert.deepEqual(momentConfirmation(TAP + 400.4, taps), { momentMs: TAP + 400, source: 'reviewer', pieces: null });
        assert.deepEqual(momentConfirmation(TAP + 60_000, taps), { momentMs: TAP + 60_000, source: 'button', pieces: null });
        assert.equal(momentConfirmation(null, taps), null);
    },

    async 'pieces placed: a whole number from 1, below the puzzle\'s pieces'() {
        assert.deepEqual(piecesConfirmation(' 2688 ', 3000), { momentMs: null, source: 'reviewer', pieces: 2688 });
        assert.equal(piecesConfirmation('3000', 3000), null, 'a complete puzzle is a time, not pieces');
        assert.equal(piecesConfirmation('0', 3000), null);
        assert.equal(piecesConfirmation('12.5', 3000), null);
        assert.equal(piecesConfirmation('', 3000), null);
        assert.deepEqual(piecesConfirmation('99999', null), { momentMs: null, source: 'reviewer', pieces: 99999 });
    },

    async 'reported pieces left convert through the puzzle\'s piece count'() {
        assert.equal(piecesPlaced('left', 312, 3000), 2688);
        assert.equal(piecesPlaced('placed', 312, 3000), 312);
        assert.equal(piecesPlaced('left', 312, null), null, 'unknown piece count');
        assert.equal(piecesPlaced('left', 3500, 3000), 0);
        assert.equal(piecesPlaced(null, 312, 3000), null);
        assert.equal(piecesPlaced('left', null, 3000), null);
    },

    async 'moments on the round clock with tenths, never rounded up'() {
        assert.equal(formatElapsed((47 * 60 + 12) * 1000 + 345), '00:47:12.3');
        assert.equal(formatElapsed(999), '00:00:00.9');
        assert.equal(formatElapsed(3_600_000 + 99), '01:00:00.0');
        assert.equal(formatElapsed(-1500), '-00:00:01.5');
        assert.equal(formatElapsed(null), '');
    },

    async 'the adjustment reads with a sign and a tenth of a second'() {
        assert.equal(formatAdjustment(400), '+0.4 s');
        assert.equal(formatAdjustment(-1250), '−1.3 s');
        assert.equal(formatAdjustment(40), '0.0 s');
        assert.equal(formatAdjustment(0), '0.0 s');
        assert.equal(formatAdjustment(undefined), '');
    },

    // The same cases as AccuracySummaryTest (PHP) - the review page and the accuracy report say the same
    async 'statistics: count, median, quartiles and IQR by linear interpolation'() {
        assert.deepEqual(summarize([]), { count: 0, median: null, q1: null, q3: null, iqr: null });
        assert.deepEqual(summarize([400]), { count: 1, median: 400, q1: 400, q3: 400, iqr: 0 });
        assert.deepEqual(summarize([300, -100, 500, 100]), { count: 4, median: 200, q1: 50, q3: 350, iqr: 300 });
        assert.deepEqual(summarize([1, 2, 3, 4, 5]), { count: 5, median: 3, q1: 2, q3: 4, iqr: 2 });
        assert.deepEqual(summarize([-1200, 0, 0, 100, 250, 400, 3000]), { count: 7, median: 100, q1: 0, q3: 325, iqr: 325 });
        assert.deepEqual(summarize([5, null, 'x', NaN, 7]), { count: 2, median: 6, q1: 5.5, q3: 6.5, iqr: 1 });
    },

    async 'the keys: arrows, C and U - never while typing or with a modifier'() {
        const button = { tagName: 'BUTTON' };

        assert.equal(reviewKey({ key: 'ArrowDown', target: button }), 'down');
        assert.equal(reviewKey({ key: 'ArrowUp', target: document() }), 'up');
        assert.equal(reviewKey({ key: 'ArrowLeft', target: button }), 'left');
        assert.equal(reviewKey({ key: 'ArrowRight', target: button }), 'right');
        assert.equal(reviewKey({ key: 'c', target: button }), 'confirm');
        assert.equal(reviewKey({ key: 'C', target: button }), 'confirm');
        assert.equal(reviewKey({ key: 'u', target: button }), 'unfinished');
        assert.equal(reviewKey({ key: 'x', target: button }), null);
        assert.equal(reviewKey({ key: 'c', target: button, ctrlKey: true }), null, 'copy stays copy');
        assert.equal(reviewKey({ key: 'ArrowDown', target: { tagName: 'INPUT', type: 'number' } }), null, 'the pieces field');
        assert.equal(reviewKey({ key: 'u', target: { tagName: 'INPUT', type: 'text' } }), null);
        assert.equal(reviewKey({ key: 'c', target: { tagName: 'TEXTAREA' } }), null);
        assert.equal(reviewKey({ key: 'ArrowDown', target: { tagName: 'SELECT' } }), null);
        assert.equal(reviewKey({ key: 'c', target: { tagName: 'DIV', isContentEditable: true } }), null);
        // The frame slider: its own ←/→, the review's ↑/↓ and letters
        assert.equal(reviewKey({ key: 'ArrowLeft', target: { tagName: 'INPUT', type: 'range' } }), null);
        assert.equal(reviewKey({ key: 'ArrowDown', target: { tagName: 'INPUT', type: 'range' } }), 'down');
        assert.equal(reviewKey({ key: 'c', target: { tagName: 'INPUT', type: 'range' } }), 'confirm');
    },

    async 'after a confirmation the next one to look at comes, wrapping round'() {
        const rows = [{ status: 'confirmed' }, { status: 'needs_look' }, { status: 'no_finish' }, { status: 'unfinished' }];

        assert.equal(nextToReview(rows, 1), 3);
        assert.equal(nextToReview(rows, 3), 1, 'wraps round');
        assert.equal(nextToReview(rows, -1), 1);
        assert.equal(nextToReview([{ status: 'confirmed' }, { status: 'no_finish' }], 0), -1, 'nothing left');
        assert.equal(nextToReview([], 0), -1);
    },

    async 'control room: ready, waiting, never seen'() {
        const now = START - 60_000;

        assert.equal(participantView(participant(), { serverNowMs: now }).readiness, 'ready');
        assert.equal(participantView(participant({ readyAtMs: null }), { serverNowMs: now }).readiness, 'waiting');
        assert.equal(participantView(participant({ readyAtMs: null, seen: false }), { serverNowMs: now }).readiness, 'waiting', 'a heartbeat is contact');
        assert.equal(participantView(participant({ readyAtMs: null, seen: false, presence: null }), { serverNowMs: now }).readiness, 'not_seen');
    },

    async 'control room: online for three heartbeats, then offline - the camera only counts while online'() {
        const lastSeen = START - 5_000;
        const fresh = participantView(participant(), { serverNowMs: lastSeen + ONLINE_FRESH_MS });
        const stale = participantView(participant(), { serverNowMs: lastSeen + ONLINE_FRESH_MS + 1 });

        assert.equal(fresh.online, true);
        assert.equal(fresh.camera, 'ok');
        assert.equal(fresh.lastSeenAgoMs, ONLINE_FRESH_MS);
        assert.equal(stale.online, false);
        assert.equal(stale.camera, 'unknown');
        assert.equal(participantView(participant({ presence: null }), { serverNowMs: START }).online, null);

        const cameraOff = participant();
        cameraOff.presence = { ...cameraOff.presence, camera: { ok: false }, visible: false };
        const view = participantView(cameraOff, { serverNowMs: START });
        assert.equal(view.camera, 'off');
        assert.equal(view.hidden, true);
    },

    async 'control room: the clock quality from the round trip, its error half of it'() {
        const now = START;

        assert.deepEqual(
            [participantView(participant(), { serverNowMs: now }).clockQuality, participantView(participant(), { serverNowMs: now }).clockErrorMs],
            ['good', 19],
        );

        const slow = participant();
        slow.presence = { ...slow.presence, clock: { offsetMs: 1, rttMs: 600 } };
        assert.equal(participantView(slow, { serverNowMs: now }).clockQuality, 'fair');

        slow.presence = { ...slow.presence, clock: { offsetMs: 1, rttMs: 1500 } };
        assert.equal(participantView(slow, { serverNowMs: now }).clockQuality, 'poor');

        // No heartbeat: the sample the phone was ready with
        assert.equal(participantView(participant({ presence: null, readyClockRttMs: 300 }), { serverNowMs: now }).clockQuality, 'fair');
        assert.equal(participantView(participant({ presence: null, readyClockRttMs: null }), { serverNowMs: now }).clockQuality, 'unknown');
    },

    async 'control room: a finish as an elapsed time, marked after the end'() {
        const finished = participant({ finish: { eventId: 't', momentMs: TAP, receivedAtMs: TAP + 300 } });
        const view = participantView(finished, { serverNowMs: TAP + 1000, startedAtMs: START, endedAtMs: null });

        assert.equal(view.finished, true);
        assert.equal(view.finishElapsedMs, TAP - START);
        assert.equal(view.finishAfterEnd, false);
        assert.equal(participantView(finished, { serverNowMs: TAP + 1000, startedAtMs: START, endedAtMs: TAP - 1 }).finishAfterEnd, true);
    },

    async 'control room: counts and filters'() {
        const now = START;
        const views = [
            participant(),
            participant({ readyAtMs: null }),
            participant({ readyAtMs: null, seen: false, presence: null }),
            participant({ finish: { eventId: 't', momentMs: TAP, receivedAtMs: TAP } }),
            participant({ reported: { measure: 'left', pieces: 12 }, presence: { ...participant().presence, lastSeenMs: now - 120_000 } }),
        ].map((item) => participantView(item, { serverNowMs: now, startedAtMs: START - 3_600_000 }));

        assert.deepEqual(countsOf(views), { entered: 5, seen: 4, ready: 3, online: 3, cameraOff: 0, finished: 1, reported: 1 });
        assert.deepEqual(views.map((view) => matchesFilter(view, 'not_ready')), [false, true, true, false, false]);
        assert.deepEqual(views.map((view) => matchesFilter(view, 'offline')), [false, false, true, false, true]);
        assert.deepEqual(views.map((view) => matchesFilter(view, 'finished')), [false, false, false, true, false]);
        assert.ok(views.every((view) => matchesFilter(view, 'all')));
    },

    async 'control room: last seen in the largest whole unit'() {
        assert.deepEqual(agoParts(12_400), { value: 12, unit: 's' });
        assert.deepEqual(agoParts(59_999), { value: 59, unit: 's' });
        assert.deepEqual(agoParts(60_000), { value: 1, unit: 'min' });
        assert.deepEqual(agoParts(3_599_999), { value: 59, unit: 'min' });
        assert.deepEqual(agoParts(7_200_000), { value: 2, unit: 'h' });
        assert.equal(agoParts(null), null);
    },

    async 'control room: a tile\'s photo changes for another photo, never for a new signature'() {
        const shown = { mediaId: 'm-1', url: 'https://store/m-1?sig=1' };

        assert.equal(photoChanged(null, { mediaId: 'm-1', url: 'https://store/m-1?sig=1' }), true, 'the first photo');
        assert.equal(photoChanged(shown, { mediaId: 'm-1', url: 'https://store/m-1?sig=2' }), false, 'the same photo signed again');
        assert.equal(photoChanged(shown, { mediaId: 'm-2', url: 'https://store/m-2?sig=1' }), true, 'a newer photo');
        assert.equal(photoChanged({ ...shown, failed: true }, { mediaId: 'm-1', url: 'https://store/m-1?sig=2' }), true, 'it failed to load - a fresh URL');
        assert.equal(photoChanged(shown, null), false, 'no photo this time - the shown one stays');
    },
};

function document() {
    return { tagName: 'BODY' };
}

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
