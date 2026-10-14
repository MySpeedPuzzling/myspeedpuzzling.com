/**
 * Frames from the participant's camera as JPEG blobs, each with the moment it was captured on the synced clock
 * (docs/features/online-competitions/README.md §5.6, §5.7):
 *
 * - start({wants, onFrame}): ~10 fps of downscaled frames (~640×360) for the finish burst's ring buffer
 *   (frame_buffer.js). It waits for each new camera frame (`requestVideoFrameCallback`, whose metadata carries the
 *   frame's `captureTime`; a requestAnimationFrame / timer fallback where it is missing or stays silent), asks `wants()`
 *   before it spends the encoding (the buffer drops frames closer than 1000/fps), and encodes one frame at a time.
 * - small(): one downscaled photo now (the periodic photos, ~35 KB).
 * - still(): one photo at the stream's full resolution (start, the Finished tap, the end, the empty mat) - with a
 *   `judge(canvas, size)` it also says how sharp the frame is (a finish candidate keeps the sharpest of a few - the judge
 *   and the finish detector's own frames live in detector_feed.js, in the detection's chunk).
 *
 * Every frame's time is its capture time - performance.now()'s clock, as `captureTime` is - turned into server time by
 * the injected toServerMs() (clock_sync.js), so a frame and a tap compare on one clock; whole milliseconds, as the
 * server stores them (bigint epoch ms) and as an event's clientMs is.
 *
 * No DOM at import: the <video>, the canvas factory and the timers are injected - pinned by
 * tests/online-devices-harness.mjs.
 */

export const SMALL_WIDTH = 640;
export const SMALL_HEIGHT = 360;
// JPEG qualities: burst frames (~25 KB), periodic photos (~35 KB), full-resolution stills
export const FRAME_QUALITY = 0.6;
export const PHOTO_QUALITY = 0.72;
export const STILL_QUALITY = 0.88;
export const JPEG = 'image/jpeg';
// No frame callback this long (a hidden video, a browser that stopped reporting): the frame on screen is taken
export const FRAME_FALLBACK_MS = 250;
// A captureTime this far from now is not believed (another clock base) - now is taken instead
const CAPTURE_PAST_LIMIT_MS = 2000;
const CAPTURE_FUTURE_LIMIT_MS = 50;

/**
 * The size of `width` × `height` fitted into the box, never upscaled, even numbers (JPEG likes them); a portrait
 * frame turns the box with it (a phone held upright gives 1080×1920).
 */
export function fitWithin(width, height, maxWidth = SMALL_WIDTH, maxHeight = SMALL_HEIGHT) {
    if (!(width > 0) || !(height > 0)) {
        return { width: 0, height: 0 };
    }

    let boxWidth = maxWidth;
    let boxHeight = maxHeight;

    if (height > width && maxWidth > maxHeight) {
        boxWidth = maxHeight;
        boxHeight = maxWidth;
    }

    const scale = Math.min(1, boxWidth / width, boxHeight / height);
    const even = (value) => Math.max(2, Math.round(value / 2) * 2);

    return { width: even(width * scale), height: even(height * scale) };
}

/**
 * When a frame was captured, on performance.now()'s clock: the frame callback's `captureTime` (all three engines fill
 * it for a camera), else its `presentationTime`, else now.
 */
export function captureTimeOf(nowPerf, metadata = null) {
    for (const candidate of [metadata?.captureTime, metadata?.presentationTime]) {
        if (
            Number.isFinite(candidate)
            && candidate > 0
            && candidate >= nowPerf - CAPTURE_PAST_LIMIT_MS
            && candidate <= nowPerf + CAPTURE_FUTURE_LIMIT_MS
        ) {
            return candidate;
        }
    }

    return nowPerf;
}

/**
 * A canvas as a JPEG blob - OffscreenCanvas.convertToBlob() or canvas.toBlob(). Null when the browser gives none.
 */
export function encodeCanvas(canvas, quality, type = JPEG) {
    try {
        if (typeof canvas.convertToBlob === 'function') {
            return canvas.convertToBlob({ type, quality }).catch(() => null);
        }

        return new Promise((resolve) => {
            canvas.toBlob((blob) => resolve(blob ?? null), type, quality);
        });
    } catch (e) {
        return Promise.resolve(null);
    }
}

export class FrameGrabber {
    /**
     * @param {object} options
     * @param {HTMLVideoElement} options.video
     * @param {function(number): number} options.toServerMs    performance.now() moment → server time
     * @param {function(): HTMLCanvasElement} [options.createCanvas]
     * @param {function(): number} [options.now]
     * @param {function(function(), number): *} [options.schedule]
     * @param {function(*): void} [options.cancel]
     * @param {function(function(number)): *} [options.requestAnimationFrame]
     */
    constructor({
        video,
        toServerMs,
        createCanvas = () => globalThis.document.createElement('canvas'),
        now = () => globalThis.performance.now(),
        schedule = (task, ms) => setTimeout(task, ms),
        cancel = (timer) => clearTimeout(timer),
        requestAnimationFrame = (callback) => globalThis.requestAnimationFrame(callback),
    }) {
        this.video = video;
        this.toServerMs = toServerMs;
        this.createCanvas = createCanvas;
        this.now = now;
        this.schedule = schedule;
        this.cancel = cancel;
        this.requestAnimationFrame = requestAnimationFrame;

        this.loop = null;
        this.frameCanvas = null;
        this.encodedFrames = 0;
        this.skippedFrames = 0;
    }

    /**
     * The video shows frames (a stream attached and playing).
     */
    hasFrames() {
        const video = this.video;

        return Boolean(video) && video.videoWidth > 0 && video.videoHeight > 0 && (video.readyState ?? 4) >= 2;
    }

    isRunning() {
        return this.loop !== null;
    }

    /**
     * The ~10 fps loop for the ring buffer. A second start() replaces the first.
     *
     * @param {{wants: function(number): boolean, onFrame: function(Blob, number): void}} handlers
     */
    start({ wants = () => true, onFrame }) {
        this.stop();
        this.loop = { wants, onFrame, encoding: false, waiting: null };
        this.waitForFrame(this.loop);
    }

    stop() {
        const loop = this.loop;
        this.loop = null;

        if (loop?.waiting) {
            loop.waiting.cancel();
        }
    }

    /**
     * One downscaled photo of the next frame. Null: no frame to take.
     *
     * @returns {Promise<{blob: Blob, captureMs: number, width: number, height: number}|null>}
     */
    small() {
        return this.photo(SMALL_WIDTH, SMALL_HEIGHT, PHOTO_QUALITY);
    }

    /**
     * One photo of the next frame at the stream's full resolution. Null: no frame to take. With `judge` the result's
     * `sharpness` is what it says of the frame drawn (null when it fails).
     *
     * @param {{judge?: function(HTMLCanvasElement, {width: number, height: number}): (number|null)}} [options]
     */
    still({ judge = null } = {}) {
        return this.photo(Infinity, Infinity, STILL_QUALITY, judge);
    }

    // ---- internals

    async photo(maxWidth, maxHeight, quality, judge = null) {
        if (!this.hasFrames()) {
            return null;
        }

        const perf = await new Promise((resolve) => {
            this.nextFrame(resolve);
        });

        if (!this.hasFrames()) {
            return null;
        }

        const size = Number.isFinite(maxWidth)
            ? fitWithin(this.video.videoWidth, this.video.videoHeight, maxWidth, maxHeight)
            : { width: this.video.videoWidth, height: this.video.videoHeight };
        const canvas = this.createCanvas();

        try {
            if (!this.draw(canvas, size)) {
                return null;
            }

            const sharpness = judge === null ? undefined : judged(judge, canvas, size);
            const blob = await encodeCanvas(canvas, quality);

            if (blob === null) {
                return null;
            }

            const shot = { blob, captureMs: Math.round(this.toServerMs(perf)), width: size.width, height: size.height };

            return judge === null ? shot : { ...shot, sharpness };
        } finally {
            // A 1080p canvas holds 8 MB - given back at once
            canvas.width = 0;
            canvas.height = 0;
        }
    }

    draw(canvas, { width, height }) {
        if (!(width > 0) || !(height > 0)) {
            return false;
        }

        if (canvas.width !== width || canvas.height !== height) {
            canvas.width = width;
            canvas.height = height;
        }

        const context = canvas.getContext('2d', { alpha: false });

        if (!context) {
            return false;
        }

        context.drawImage(this.video, 0, 0, width, height);

        return true;
    }

    /**
     * Calls back with the next frame's capture time (performance.now() clock): the frame callback, or the frame on
     * screen when none came within FRAME_FALLBACK_MS. The second argument says how it came - `{source: 'frame',
     * presentedFrames}` (requestVideoFrameCallback and its count of frames presented), `{source: 'animation'}` (no
     * frame callbacks in this browser) or `{source: 'timer'}` (the fallback: maybe the same frame again - a frozen
     * camera presents none; detector_feed.js tells them apart). Returns a cancel handle.
     */
    nextFrame(callback) {
        const video = this.video;
        let done = false;
        let handle = null;
        let timer = null;

        const finish = (perf, how) => {
            if (done) {
                return;
            }

            done = true;

            if (timer !== null) {
                this.cancel(timer);
            }

            if (handle !== null && typeof video?.cancelVideoFrameCallback === 'function') {
                try {
                    video.cancelVideoFrameCallback(handle);
                } catch (e) {
                    // Called already
                }
            }

            callback(perf, how);
        };

        if (typeof video?.requestVideoFrameCallback === 'function') {
            handle = video.requestVideoFrameCallback((now, metadata) => {
                handle = null;
                finish(captureTimeOf(Number.isFinite(now) ? now : this.now(), metadata), { source: 'frame', presentedFrames: metadata?.presentedFrames ?? null });
            });
        } else {
            this.requestAnimationFrame(() => finish(this.now(), { source: 'animation' }));
        }

        timer = this.schedule(() => {
            timer = null;
            finish(this.now(), { source: 'timer' });
        }, FRAME_FALLBACK_MS);

        return {
            cancel: () => {
                done = true;

                if (timer !== null) {
                    this.cancel(timer);
                }

                if (handle !== null && typeof video?.cancelVideoFrameCallback === 'function') {
                    try {
                        video.cancelVideoFrameCallback(handle);
                    } catch (e) {
                        // Called already
                    }
                }
            },
        };
    }

    waitForFrame(loop) {
        if (this.loop !== loop) {
            return;
        }

        loop.waiting = this.nextFrame((perf) => {
            loop.waiting = null;
            this.frameArrived(loop, perf);
            this.waitForFrame(loop);
        });
    }

    frameArrived(loop, perf) {
        if (this.loop !== loop || loop.encoding || !this.hasFrames()) {
            this.skippedFrames += 1;

            return;
        }

        const captureMs = Math.round(this.toServerMs(perf));
        let wanted = false;

        try {
            wanted = loop.wants(captureMs);
        } catch (e) {
            wanted = false;
        }

        if (!wanted) {
            this.skippedFrames += 1;

            return;
        }

        if (this.frameCanvas === null) {
            this.frameCanvas = this.createCanvas();
        }

        if (!this.draw(this.frameCanvas, fitWithin(this.video.videoWidth, this.video.videoHeight))) {
            return;
        }

        // The canvas is copied when the encoding starts - the next frame may draw over it meanwhile
        loop.encoding = true;
        encodeCanvas(this.frameCanvas, FRAME_QUALITY).then((blob) => {
            loop.encoding = false;

            if (blob !== null && this.loop === loop) {
                this.encodedFrames += 1;

                try {
                    loop.onFrame(blob, captureMs);
                } catch (e) {
                    // The page's handler must never stop the loop
                }
            }
        });
    }
}

function judged(judge, canvas, size) {
    try {
        const value = judge(canvas, size);

        return Number.isFinite(value) ? value : null;
    } catch (e) {
        return null;
    }
}
