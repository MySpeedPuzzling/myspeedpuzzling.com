// Runs assets/online/frame_buffer.js - the finish burst's memory - through its scenarios with fake timers and prints
// {scenario: "ok" | error} as JSON. tests/OnlineFrameBufferScriptTest.php asserts every one is "ok".

import assert from 'node:assert/strict';
import {
    BURST_AFTER_MS,
    BURST_CONTENT_TYPE,
    BURST_GRACE_MS,
    createFrameBuffer,
} from '../assets/online/frame_buffer.js';

const T = 1_792_000_000_000;

function fakeTimers() {
    const timers = [];

    return {
        timers,
        schedule: (task, ms) => {
            const timer = { task, ms };
            timers.push(timer);

            return timer;
        },
        cancel: (timer) => {
            const index = timers.indexOf(timer);

            if (index >= 0) {
                timers.splice(index, 1);
            }
        },
        fireAll() {
            timers.splice(0).forEach((timer) => timer.task());
        },
    };
}

function setup(options = {}) {
    const time = fakeTimers();
    const buffer = createFrameBuffer({ schedule: time.schedule, cancel: time.cancel, ...options });

    return { buffer, time };
}

/**
 * An encoded frame: its bytes say when it was captured.
 */
function frame(captureMs) {
    return new Blob([`frame@${captureMs - T};`]);
}

function pushEvery(buffer, fromMs, toMs, stepMs = 100) {
    for (let at = fromMs; at <= toMs; at += stepMs) {
        buffer.push(frame(T + at), T + at);
    }
}

/**
 * A promise and whether it settled yet.
 */
function track(promise) {
    const tracked = { done: false, value: undefined };
    tracked.promise = promise.then((value) => {
        tracked.done = true;
        tracked.value = value;

        return value;
    });

    return tracked;
}

async function settle() {
    for (let i = 0; i < 5; i++) {
        await new Promise((resolve) => setImmediate(resolve));
    }
}

function moments(result) {
    return result.manifest.map((entry) => entry.captureMs - T);
}

const scenarios = {
    async 'the ring keeps the last 5 s of 10 fps'() {
        const { buffer } = setup({ fps: 10, seconds: 5 });
        pushEvery(buffer, 0, 10_000);

        assert.equal(buffer.size(), 51, 'from 5 s before the newest to the newest');
        assert.equal(buffer.ring[0].captureMs, T + 5000);
        assert.equal(buffer.ring[buffer.ring.length - 1].captureMs, T + 10_000);
    },

    async 'a jittery 30 fps camera becomes 10 fps - wants() says so before the page spends the encoding'() {
        const { buffer } = setup({ fps: 10, seconds: 5 });
        const jitter = [0, 2.5, -2, 1, -3, 3, -1, 0.5];
        let kept = 0;

        for (let index = 0; index < 90; index++) {
            const at = T + index * (1000 / 30) + jitter[index % jitter.length];
            const wanted = buffer.wants(at);
            const pushed = buffer.push(frame(at), at);
            assert.equal(pushed, wanted, `frame ${index}`);
            kept += pushed ? 1 : 0;
        }

        assert.ok(kept >= 28 && kept <= 31, `${kept} frames in 3 s`);

        for (let index = 1; index < buffer.ring.length; index++) {
            assert.ok(buffer.ring[index].captureMs - buffer.ring[index - 1].captureMs >= 90, 'never closer than 1000/fps less the jitter');
        }

        // The very same moment twice is one frame
        const { buffer: other } = setup();
        assert.equal(other.push(frame(T), T), true);
        assert.equal(other.push(frame(T), T), false);
        assert.equal(other.size(), 1);
    },

    async 'a burst: the 5 s before at once, the 5 s after as they come - done with the first frame past its end'() {
        const { buffer, time } = setup({ fps: 10, seconds: 5 });
        pushEvery(buffer, 0, 10_000);

        const burst = track(buffer.burst(T + 10_000));
        assert.equal(time.timers.length, 1);
        assert.equal(time.timers[0].ms, BURST_AFTER_MS + BURST_GRACE_MS, 'it waits for the frames at most this long');

        pushEvery(buffer, 10_100, 14_900);
        await settle();
        assert.equal(burst.done, false, 'its last frames have not come yet');

        buffer.push(frame(T + 15_050), T + 15_050);
        await settle();
        assert.equal(burst.done, true);
        assert.equal(time.timers.length, 0, 'the timeout is cancelled');

        const result = burst.value;
        assert.equal(result.complete, true);
        assert.equal(result.momentMs, T + 10_000);
        assert.equal(result.fromMs, T + 5000);
        assert.equal(result.toMs, T + 15_000);
        assert.equal(result.manifest.length, 100, '5000 … 14900 every 100 ms');
        assert.deepEqual(moments(result).slice(0, 2), [5000, 5100]);
        assert.equal(moments(result).at(-1), 14_900, 'the frame past the end is not in it');

        // The ring meanwhile let the first 5 s go (it keeps 15 050 − 5000 on) - the burst kept them
        assert.equal(buffer.ring[0].captureMs, T + 10_100);
    },

    async 'the manifest: offsets and lengths give back every frame\'s bytes from the one blob'() {
        const { buffer } = setup();
        pushEvery(buffer, 0, 2000);
        const burst = track(buffer.burst(T + 1000, { before: 1000, after: 1000 }));
        buffer.push(frame(T + 2100), T + 2100);
        await settle();

        const { blob, manifest } = burst.value;
        assert.equal(blob.type, BURST_CONTENT_TYPE);
        assert.equal(blob.size, manifest.reduce((sum, entry) => sum + entry.length, 0));
        assert.equal(manifest[0].offset, 0);

        for (let index = 0; index < manifest.length; index++) {
            const entry = manifest[index];

            if (index > 0) {
                assert.equal(entry.offset, manifest[index - 1].offset + manifest[index - 1].length);
            }

            const text = await blob.slice(entry.offset, entry.offset + entry.length).text();
            assert.equal(text, `frame@${entry.captureMs - T};`);
        }
    },

    async 'frames outside the window stay out - its two ends are in'() {
        const { buffer } = setup();
        pushEvery(buffer, 9000, 10_200);
        const burst = track(buffer.burst(T + 10_000, { before: 300, after: 300 }));

        buffer.push(frame(T + 10_300), T + 10_300);
        await settle();

        assert.equal(burst.done, true, 'a frame exactly at the end completes it');
        assert.deepEqual(moments(burst.value), [9700, 9800, 9900, 10_000, 10_100, 10_200, 10_300]);
        assert.equal(burst.value.complete, true);
    },

    async 'the frames stop (camera lost): after `after` + the grace it takes what it has'() {
        const { buffer, time } = setup();
        pushEvery(buffer, 0, 5000);
        const burst = track(buffer.burst(T + 5000));
        pushEvery(buffer, 5100, 6000);

        time.fireAll();
        await settle();

        assert.equal(burst.done, true);
        assert.equal(burst.value.complete, false);
        assert.equal(moments(burst.value)[0], 0);
        assert.equal(moments(burst.value).at(-1), 6000);
        assert.equal(burst.value.manifest.length, 61);

        // Its own timeout
        const custom = setup();
        custom.buffer.burst(T, { timeoutMs: 1234 });
        assert.equal(custom.time.timers[0].ms, 1234);
    },

    async 'a moment far enough back: every frame is there - resolved at once, no timer'() {
        const { buffer, time } = setup();
        pushEvery(buffer, 0, 10_000);

        const result = await buffer.burst(T + 7000, { before: 1000, after: 1000 });

        assert.equal(time.timers.length, 0);
        assert.equal(result.complete, true);
        assert.equal(result.manifest.length, 21);
        assert.deepEqual([moments(result)[0], moments(result).at(-1)], [6000, 8000]);
    },

    async 'frames encoded out of order land in their place - in the ring and in a burst'() {
        const { buffer } = setup();
        for (const at of [0, 200, 100, 300]) {
            buffer.push(frame(T + at), T + at);
        }

        assert.deepEqual(buffer.ring.map((entry) => entry.captureMs - T), [0, 100, 200, 300]);

        const burst = track(buffer.burst(T + 300, { before: 300, after: 300 }));
        for (const at of [500, 400, 700]) {
            buffer.push(frame(T + at), T + at);
        }
        await settle();

        assert.deepEqual(moments(burst.value), [0, 100, 200, 300, 400, 500]);
    },

    async 'two overlapping bursts share their frames, each with its own manifest'() {
        const { buffer } = setup();
        pushEvery(buffer, 0, 10_000);
        const first = track(buffer.burst(T + 10_000));
        pushEvery(buffer, 10_100, 12_000);
        const second = track(buffer.burst(T + 12_000));
        pushEvery(buffer, 12_100, 17_100);
        await settle();

        assert.equal(first.done, true);
        assert.equal(second.done, true);
        assert.deepEqual([moments(first.value)[0], moments(first.value).at(-1)], [5000, 15_000]);
        assert.deepEqual([moments(second.value)[0], moments(second.value).at(-1)], [7000, 17_000]);
        assert.equal(first.value.manifest.length, 101);
        assert.equal(second.value.manifest.length, 101);
    },

    async 'cancelAll(): the waiting bursts resolve with what they have, the ring empties, no timer is left'() {
        const { buffer, time } = setup();
        pushEvery(buffer, 0, 3000);
        const burst = track(buffer.burst(T + 3000));

        buffer.cancelAll();
        await settle();

        assert.equal(burst.done, true);
        assert.equal(burst.value.complete, false);
        assert.equal(burst.value.manifest.length, 31);
        assert.equal(buffer.size(), 0);
        assert.equal(time.timers.length, 0);
    },

    async 'no frames at all: an empty burst once the time is up'() {
        const { buffer, time } = setup();
        const burst = track(buffer.burst(T));
        time.fireAll();
        await settle();

        assert.deepEqual(burst.value.manifest, []);
        assert.equal(burst.value.blob.size, 0);
        assert.equal(burst.value.complete, false);
    },

    async 'burst() without a moment takes now() - without a clock it refuses'() {
        const { buffer } = setup({ now: () => T + 500 });
        pushEvery(buffer, 0, 6000);
        const result = await buffer.burst();

        assert.equal(result.momentMs, T + 500);
        assert.equal(moments(result)[0], 1000, 'the ring keeps 5 s - what is left of the 5 s before');

        await assert.rejects(setup().buffer.burst(), TypeError);
    },

    async 'nothing to keep: no blob, no time'() {
        const { buffer } = setup();

        assert.equal(buffer.push(null, T), false);
        assert.equal(buffer.push(frame(T), Number.NaN), false);
        assert.equal(buffer.wants(Number.NaN), false);
        assert.equal(buffer.size(), 0);

        // A frame older than the ring's window is not taken in - unless a burst wants it
        pushEvery(buffer, 0, 6000);
        assert.equal(buffer.wants(T + 500), false);
        const burst = track(buffer.burst(T + 6000, { before: 6000, after: 0 }));
        await settle();
        assert.equal(burst.done, true, 'its end is in the ring already');
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
