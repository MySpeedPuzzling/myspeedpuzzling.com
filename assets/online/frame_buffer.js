/**
 * The finish burst's memory (docs/features/online-competitions/README.md §5.6, §5.9): the last few seconds of frames
 * the page already encoded (~640×360 JPEG at ~10 fps, ~1 MB), so a Finished tap or a finish candidate can upload ±5 s
 * of frames around its moment - the reviewer scrubs it to the exact frame.
 *
 * - push(blob, captureMs): an encoded frame and when it was captured, on the clock the moments use (the synced server
 *   time of the frame's captureTime - clock_sync.js toServerMs()). A frame closer than 1000/fps to a kept one is
 *   dropped (less 10 % for the camera's timing jitter) - wants() tells the page before it spends the encoding. Frames
 *   more than `seconds` older than the newest leave the ring; frames encoded out of order are put in their place.
 * - burst(momentMs, {before, after}): every frame from momentMs − before to momentMs + after - those in the ring at
 *   once, the later ones as they come. It resolves once a frame at or past the end arrived, or - the frames stopped
 *   (camera lost, page hidden) - after `after` + BURST_GRACE_MS with what it has. Its frames are its own: the ring
 *   letting them go changes nothing.
 * - The result: one blob - the frames one after another - and its manifest [{offset, length, captureMs}], the format
 *   the upload and the review's scrubber share. No canvas, no copying: a Blob of Blobs is put together lazily.
 *
 * Pure logic; the timers are injected - pinned by tests/online-frame-buffer-harness.mjs.
 */

export const DEFAULT_FPS = 10;
export const DEFAULT_SECONDS = 5;
export const BURST_BEFORE_MS = 5000;
export const BURST_AFTER_MS = 5000;
// How long past `after` a burst waits for its last frames before it takes what it has
export const BURST_GRACE_MS = 2000;
// Frames this much closer than 1000/fps still count as the next frame (camera timestamps jitter)
export const GAP_JITTER = 0.1;
export const BURST_CONTENT_TYPE = 'application/octet-stream';

/**
 * @typedef {object} Burst
 * @property {Blob} blob                  the frames one after another
 * @property {{offset: number, length: number, captureMs: number}[]} manifest
 * @property {number} momentMs
 * @property {number} fromMs
 * @property {number} toMs
 * @property {boolean} complete           false: it timed out or was cancelled before its last frame came
 */

/**
 * @param {object} [options]
 * @param {number} [options.fps]
 * @param {number} [options.seconds]          how much the ring keeps
 * @param {function(): number|null} [options.now]  the moment burst() takes without one - on the captureMs clock
 * @param {number} [options.minGapMs]         frames closer than this are dropped (default 1000/fps less GAP_JITTER)
 * @param {number} [options.graceMs]
 * @param {function(function(), number): *} [options.schedule]
 * @param {function(*): void} [options.cancel]
 * @param {function(Blob[], object): Blob} [options.createBlob]
 */
export function createFrameBuffer(options = {}) {
    return new FrameBuffer(options);
}

export class FrameBuffer {
    constructor({
        fps = DEFAULT_FPS,
        seconds = DEFAULT_SECONDS,
        now = null,
        minGapMs = null,
        graceMs = BURST_GRACE_MS,
        schedule = (task, ms) => setTimeout(task, ms),
        cancel = (timer) => clearTimeout(timer),
        createBlob = (parts, options) => new Blob(parts, options),
    } = {}) {
        this.windowMs = seconds * 1000;
        this.minGapMs = Number.isFinite(minGapMs) ? minGapMs : (1000 / fps) * (1 - GAP_JITTER);
        this.now = now;
        this.graceMs = graceMs;
        this.schedule = schedule;
        this.cancel = cancel;
        this.createBlob = createBlob;

        /** @type {{blob: Blob, captureMs: number, size: number}[]} sorted by captureMs */
        this.ring = [];
        /** bursts waiting for their later frames */
        this.pending = [];
    }

    /**
     * Would push() keep a frame captured at `captureMs`? - so the page skips encoding the ones it would not.
     */
    wants(captureMs) {
        if (!Number.isFinite(captureMs)) {
            return false;
        }

        if (this.fitsRing(captureMs)) {
            return true;
        }

        return this.pending.some((burst) => this.fitsBurst(burst, captureMs));
    }

    /**
     * @param {Blob} blob
     * @param {number} captureMs
     * @returns {boolean} kept (in the ring or a burst)
     */
    push(blob, captureMs) {
        if (blob === null || typeof blob !== 'object' || !Number.isFinite(captureMs)) {
            return false;
        }

        const frame = { blob, captureMs, size: Number.isFinite(blob.size) ? blob.size : 0 };
        let kept = false;

        if (this.fitsRing(captureMs)) {
            insertSorted(this.ring, frame);
            const oldest = this.ring[this.ring.length - 1].captureMs - this.windowMs;

            while (this.ring.length > 0 && this.ring[0].captureMs < oldest) {
                this.ring.shift();
            }

            kept = true;
        }

        for (const burst of [...this.pending]) {
            if (captureMs > burst.toMs) {
                // Past its end: everything it could get is in
                this.finish(burst, true);
                continue;
            }

            if (this.fitsBurst(burst, captureMs)) {
                insertSorted(burst.frames, frame);
                kept = true;
            }

            if (captureMs >= burst.toMs) {
                this.finish(burst, true);
            }
        }

        return kept;
    }

    /**
     * The frames around a moment, as one blob + manifest.
     *
     * @param {number|null} [momentMs]  default: now()
     * @param {{before?: number, after?: number, timeoutMs?: number}} [options]
     * @returns {Promise<Burst>}
     */
    burst(momentMs = null, { before = BURST_BEFORE_MS, after = BURST_AFTER_MS, timeoutMs = null } = {}) {
        const moment = Number.isFinite(momentMs) ? momentMs : (typeof this.now === 'function' ? this.now() : NaN);

        if (!Number.isFinite(moment)) {
            return Promise.reject(new TypeError('A burst needs its moment'));
        }

        const beforeMs = Number.isFinite(before) && before >= 0 ? before : BURST_BEFORE_MS;
        const afterMs = Number.isFinite(after) && after >= 0 ? after : BURST_AFTER_MS;

        return new Promise((resolve) => {
            const burst = {
                momentMs: moment,
                fromMs: moment - beforeMs,
                toMs: moment + afterMs,
                frames: this.ring.filter((frame) => frame.captureMs >= moment - beforeMs && frame.captureMs <= moment + afterMs),
                resolve,
                timer: null,
            };
            const newest = this.ring.length > 0 ? this.ring[this.ring.length - 1].captureMs : null;

            if (newest !== null && newest >= burst.toMs) {
                // The moment lies far enough back: every frame is here already
                resolve(this.build(burst, true));

                return;
            }

            this.pending.push(burst);
            burst.timer = this.schedule(
                () => this.finish(burst, false),
                Number.isFinite(timeoutMs) && timeoutMs >= 0 ? timeoutMs : afterMs + this.graceMs,
            );
        });
    }

    /**
     * Every waiting burst resolves at once with what it has (`complete: false`); the ring empties - the camera was
     * switched or the page is going.
     */
    cancelAll() {
        for (const burst of [...this.pending]) {
            this.finish(burst, false);
        }

        this.ring = [];
    }

    /**
     * Frames in the ring now.
     */
    size() {
        return this.ring.length;
    }

    // ---- internals

    fitsRing(captureMs) {
        const newest = this.ring.length > 0 ? this.ring[this.ring.length - 1].captureMs : null;

        if (newest !== null && captureMs < newest - this.windowMs) {
            return false;
        }

        return !tooClose(this.ring, captureMs, this.minGapMs);
    }

    fitsBurst(burst, captureMs) {
        return captureMs >= burst.fromMs && captureMs <= burst.toMs && !tooClose(burst.frames, captureMs, this.minGapMs);
    }

    finish(burst, complete) {
        const index = this.pending.indexOf(burst);

        if (index === -1) {
            return;
        }

        this.pending.splice(index, 1);

        if (burst.timer !== null) {
            this.cancel(burst.timer);
            burst.timer = null;
        }

        burst.resolve(this.build(burst, complete));
    }

    build(burst, complete) {
        let offset = 0;
        const manifest = burst.frames.map((frame) => {
            const entry = { offset, length: frame.size, captureMs: frame.captureMs };
            offset += frame.size;

            return entry;
        });

        return {
            blob: this.createBlob(burst.frames.map((frame) => frame.blob), { type: BURST_CONTENT_TYPE }),
            manifest,
            momentMs: burst.momentMs,
            fromMs: burst.fromMs,
            toMs: burst.toMs,
            complete,
        };
    }
}

/**
 * A frame closer than `gapMs` to a neighbour in `frames` (sorted by captureMs) - or the very same moment.
 */
function tooClose(frames, captureMs, gapMs) {
    const index = firstAtOrAfter(frames, captureMs);
    const next = frames[index];
    const previous = frames[index - 1];

    return (next !== undefined && next.captureMs - captureMs < gapMs)
        || (previous !== undefined && captureMs - previous.captureMs < gapMs);
}

function firstAtOrAfter(frames, captureMs) {
    let low = 0;
    let high = frames.length;

    while (low < high) {
        const middle = (low + high) >> 1;

        if (frames[middle].captureMs < captureMs) {
            low = middle + 1;
        } else {
            high = middle;
        }
    }

    return low;
}

function insertSorted(frames, frame) {
    frames.splice(firstAtOrAfter(frames, frame.captureMs), 0, frame);
}
