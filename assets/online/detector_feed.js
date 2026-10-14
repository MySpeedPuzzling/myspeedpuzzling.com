/**
 * The finish detector's frames and the candidate photo's sharpness (docs/features/online-competitions/README.md §5.7,
 * implementation-plan.md WP 2.2) - in the detection's chunk, next to compete_detection_page.js, so the page of a round
 * without detection carries none of it.
 *
 * - DetectorFeed: rides on the page's frame grabber (frame_grabber.js) - the same <video>, the same camera track: on each
 *   new camera frame (its `requestVideoFrameCallback`, the capture time on performance.now()'s clock) it asks
 *   `wants(perf)` (detector_pacing.js: one in flight, 4-5 fps) and makes a DETECTOR_SIDE px (longer side) ImageBitmap
 *   for the worker, transferable - one at a time. The ring buffer's loop, the photos and the live video read the same
 *   <video> undisturbed; nothing here opens, stops or reconfigures the camera.
 *   **Only frames the camera really presented anew** (stream P2F): a frame callback's `presentedFrames` changed - or,
 *   on the grabber's 250 ms fallback tick (no frame callback came), the video's `currentTime` changed. A frozen camera
 *   presents nothing: no frames reach the detector (a gap - the finish machine unarms; the thaw is safe), never the
 *   same picture again and again as if it were fresh. `onFresh(perf)` hears of every fresh frame (the page's watch -
 *   a detector that hangs while the camera gives frames), before the one-bitmap-at-a-time rule.
 * - laplacianVariance() / canvasSharpness(): how sharp a still is - the frame grabber's `still({judge})` asks it of the
 *   full-resolution canvas before it is encoded (compete_detection.js sharpestStill() keeps the sharpest).
 *
 * No DOM at import; createImageBitmap is injected - pinned by tests/online-devices-harness.mjs.
 */

import { fitWithin } from './frame_grabber.js';

// The detector's frames: the longer side (the pose model's input is 256 × 256 anyway - README §5.7)
export const DETECTOR_SIDE = 320;
// How sharp a still is, judged on a copy this wide (grayscale variance of the Laplacian)
export const SHARPNESS_WIDTH = 160;

/**
 * How sharp an image is: the variance of its Laplacian (4-neighbour) over the grayscale pixels of RGBA `data` - a blurred
 * frame (motion, focus hunting) has weak edges, a low variance. Only comparable between frames of the same scene and
 * size (a candidate's stills).
 *
 * @param {Uint8ClampedArray|number[]} data   RGBA, row by row
 * @param {number} width
 * @param {number} height
 */
export function laplacianVariance(data, width, height) {
    if (!(width >= 3) || !(height >= 3) || !data || data.length < width * height * 4) {
        return 0;
    }

    const gray = new Float32Array(width * height);

    for (let i = 0, p = 0; i < gray.length; i++, p += 4) {
        gray[i] = 0.299 * data[p] + 0.587 * data[p + 1] + 0.114 * data[p + 2];
    }

    let sum = 0;
    let sumSquares = 0;
    let count = 0;

    for (let y = 1; y < height - 1; y++) {
        for (let x = 1; x < width - 1; x++) {
            const i = y * width + x;
            const value = gray[i - width] + gray[i + width] + gray[i - 1] + gray[i + 1] - 4 * gray[i];
            sum += value;
            sumSquares += value * value;
            count += 1;
        }
    }

    const mean = sum / count;

    return Math.round((sumSquares / count - mean * mean) * 100) / 100;
}

/**
 * A judge for the frame grabber's still({judge}): the canvas drawn to a SHARPNESS_WIDTH copy (~15 k pixels), its
 * Laplacian variance - null where the pixels can not be read.
 *
 * @param {function(): HTMLCanvasElement} createCanvas
 */
export function canvasSharpness(createCanvas) {
    return (canvas, { width, height }) => {
        const small = createCanvas();

        try {
            const size = fitWithin(width, height, SHARPNESS_WIDTH, SHARPNESS_WIDTH);
            small.width = size.width;
            small.height = size.height;
            const context = small.getContext('2d', { alpha: false, willReadFrequently: true });

            if (!context || typeof context.getImageData !== 'function') {
                return null;
            }

            context.drawImage(canvas, 0, 0, size.width, size.height);

            return laplacianVariance(context.getImageData(0, 0, size.width, size.height).data, size.width, size.height);
        } catch (e) {
            return null;
        } finally {
            small.width = 0;
            small.height = 0;
        }
    };
}

function closeBitmap(bitmap) {
    try {
        bitmap?.close?.();
    } catch (e) {
        // Closed already
    }
}

export class DetectorFeed {
    /**
     * @param {object} options
     * @param {object} options.grabber     frame_grabber.js FrameGrabber (its <video>, nextFrame(), draw(), createCanvas)
     * @param {function(*, object=): Promise<ImageBitmap>} [options.createImageBitmap]
     */
    constructor({ grabber, createImageBitmap = (source, options) => globalThis.createImageBitmap(source, options) }) {
        this.grabber = grabber;
        this.createImageBitmap = createImageBitmap;
        this.feed = null;
        this.canvas = null;
        // The browser resizes in createImageBitmap() - until it refuses the options once (then a small canvas does)
        this.resizeInBitmap = true;
        this.fed = 0;
        // The last frame seen: its presentedFrames (frame callbacks) and the video's currentTime then - kept across feeds
        // (a camera frozen stays frozen)
        this.lastPresented = null;
        this.lastTime = null;
        this.repeated = 0;
    }

    /**
     * Frames to `onBitmap(bitmap, perf)` (it owns the bitmap: transfers it or closes it) whenever `wants(perf)` says so;
     * `onFresh(perf)` hears of every fresh frame first. A second start() replaces the first.
     *
     * @param {{wants: function(number): boolean, onBitmap: function(ImageBitmap, number): void, onFresh?: function(number): void, side?: number}} handlers
     */
    startFeed({ wants = () => true, onBitmap, onFresh = () => {}, side = DETECTOR_SIDE }) {
        this.stopFeed();
        this.feed = { wants, onBitmap, onFresh, side, busy: false, waiting: null };
        this.wait(this.feed);
    }

    stopFeed() {
        const feed = this.feed;
        this.feed = null;

        if (feed?.waiting) {
            feed.waiting.cancel();
        }
    }

    isFeeding() {
        return this.feed !== null;
    }

    // ---- internals

    wait(feed) {
        if (this.feed !== feed) {
            return;
        }

        feed.waiting = this.grabber.nextFrame((perf, how) => {
            feed.waiting = null;
            this.arrived(feed, perf, how);
            this.wait(feed);
        });
    }

    arrived(feed, perf, how = null) {
        if (this.feed !== feed || !this.grabber.hasFrames()) {
            return;
        }

        if (!this.isFresh(how)) {
            this.repeated += 1;

            return;
        }

        try {
            feed.onFresh(perf);
        } catch (e) {
            // The page's handler must never stop the feed
        }

        if (this.feed !== feed || feed.busy) {
            return;
        }

        let wanted = false;

        try {
            wanted = feed.wants(perf);
        } catch (e) {
            wanted = false;
        }

        if (!wanted) {
            return;
        }

        const video = this.grabber.video;
        const size = fitWithin(video.videoWidth, video.videoHeight, feed.side, feed.side);
        feed.busy = true;

        this.bitmap(size).then((bitmap) => {
            feed.busy = false;

            if (bitmap === null) {
                return;
            }

            if (this.feed !== feed) {
                closeBitmap(bitmap);

                return;
            }

            this.fed += 1;

            try {
                feed.onBitmap(bitmap, perf);
            } catch (e) {
                // The page's handler must never stop the feed
                closeBitmap(bitmap);
            }
        });
    }

    /**
     * Did the camera present a new frame? A frame callback: its presentedFrames changed (a new stream may count from
     * anywhere - changed, not grown); the fallback tick or an animation frame: the video's currentTime changed. Neither
     * known (an old browser): fresh. Remembered either way it is fresh.
     *
     * @param {null|{source?: string, presentedFrames?: number|null}} how   frame_grabber.js nextFrame()'s second argument
     */
    isFresh(how) {
        const presented = how?.presentedFrames;
        const time = this.grabber.video?.currentTime;
        let fresh = true;

        if (how?.source === 'frame' && Number.isFinite(presented)) {
            fresh = presented !== this.lastPresented;
        } else if (Number.isFinite(time)) {
            fresh = time !== this.lastTime;
        }

        if (fresh) {
            this.lastPresented = Number.isFinite(presented) ? presented : this.lastPresented;
            this.lastTime = Number.isFinite(time) ? time : this.lastTime;
        }

        return fresh;
    }

    /**
     * The frame on the <video> now as a small ImageBitmap: resized by createImageBitmap() itself, or - a browser that
     * refuses its resize options (older Safari) - drawn onto a small canvas first. Null when neither works.
     */
    async bitmap({ width, height }) {
        if (!(width > 0) || !(height > 0)) {
            return null;
        }

        if (this.resizeInBitmap) {
            try {
                return await this.createImageBitmap(this.grabber.video, { resizeWidth: width, resizeHeight: height, resizeQuality: 'low' });
            } catch (e) {
                this.resizeInBitmap = false;
            }
        }

        try {
            if (this.canvas === null) {
                this.canvas = this.grabber.createCanvas();
            }

            if (!this.grabber.draw(this.canvas, { width, height })) {
                return null;
            }

            return await this.createImageBitmap(this.canvas);
        } catch (e) {
            return null;
        }
    }
}
