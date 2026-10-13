/**
 * How often the page hands a camera frame to the detector worker (docs/features/online-competitions/README.md §5.7:
 * "inference at 4-5 fps on a downscaled frame"; implementation-plan.md WP 2.2 "adaptive rate - target 4-5 fps, backs
 * off when inference is slow").
 *
 * The camera delivers ~30 frames a second (`requestVideoFrameCallback`); the page asks wants(now) on each one and
 * sends the frame only when it says yes:
 *
 * - **One frame in flight.** The detector's calls block its thread, so a second frame would only wait in a queue -
 *   older by the time it is seen, and the queue grows on a slow phone. A frame is sent when the previous one was
 *   answered.
 * - **Target `targetFps`** (5): one frame every 200 ms, counted from when the previous one was SENT - the cadence stays
 *   the interval, not the interval plus the inference.
 * - **Backs off by the inference time.** The detector may keep its core busy for at most `maxDuty` (half) of the
 *   time: interval = max(1000 / targetFps, inference / maxDuty), the inference smoothed (EWMA, `smoothing` = weight of
 *   the newest). A phone that needs 150 ms per frame runs at 3.3 fps; heat or a background app that slows it, slows
 *   the rate - never the page.
 * - **Never below `minFps`** (1): a slower detector still gets a frame a second - the finish machine's `maxGapMs`
 *   (1.5 s) accepts that cadence - and stats().slow tells the page to report the phone (the control room flags it;
 *   the Finished button covers it).
 * - **A lost answer** (the worker crashed or hung) is given up after `stallMs`: the next frame goes out, the stall is
 *   counted. A late answer to a frame given up is ignored (its frameId is not the one in flight).
 *
 * Time comes in as arguments (the frame's performance.now() moment) - no timers, no Date. Pure - pinned by
 * tests/online-hands-up-harness.mjs.
 */

export const TARGET_FPS = 5;
export const MIN_FPS = 1;
export const MAX_DUTY = 0.5;
export const SMOOTHING = 0.3;
export const STALL_MS = 5000;

/**
 * @param {object} [options]
 * @param {number} [options.targetFps]
 * @param {number} [options.minFps]
 * @param {number} [options.maxDuty]   share of the time the detector may compute (0-1]
 * @param {number} [options.smoothing] EWMA weight of the newest inference time (0-1]
 * @param {number} [options.stallMs]
 */
export function createDetectorPacing(options = {}) {
    return new DetectorPacing(options);
}

export class DetectorPacing {
    constructor({
        targetFps = TARGET_FPS,
        minFps = MIN_FPS,
        maxDuty = MAX_DUTY,
        smoothing = SMOOTHING,
        stallMs = STALL_MS,
    } = {}) {
        this.targetFps = positive(targetFps, TARGET_FPS);
        this.minFps = Math.min(positive(minFps, MIN_FPS), this.targetFps);
        this.maxDuty = Math.min(1, positive(maxDuty, MAX_DUTY));
        this.smoothing = Math.min(1, positive(smoothing, SMOOTHING));
        this.stallMs = positive(stallMs, STALL_MS);
        this.reset();
    }

    reset() {
        this.inferenceMs = null;
        this.inFlight = null;
        this.lastSentMs = null;
        this.nextAtMs = null;
        this.counts = { sent: 0, answered: 0, failed: 0, stalls: 0, late: 0 };
    }

    /**
     * The interval between two frames now (ms).
     */
    intervalMs() {
        const fastest = 1000 / this.targetFps;
        const slowest = 1000 / this.minFps;
        const needed = this.inferenceMs === null ? fastest : this.inferenceMs / this.maxDuty;

        return Math.min(slowest, Math.max(fastest, needed));
    }

    /**
     * Send the frame of this moment? - nothing in flight (or it stalled) and the interval has passed.
     *
     * @param {number} nowMs  the frame's moment (performance.now() clock)
     */
    wants(nowMs) {
        if (!Number.isFinite(nowMs)) {
            return false;
        }

        if (this.inFlight !== null) {
            if (nowMs - this.inFlight.sentMs < this.stallMs) {
                return false;
            }

            // The answer is not coming: give the frame up
            this.counts.stalls += 1;
            this.inFlight = null;

            return true;
        }

        return this.nextAtMs === null || nowMs >= this.nextAtMs;
    }

    /**
     * The frame went to the worker.
     *
     * @param {number} nowMs
     * @param {*} [frameId]  what the answer will carry - a late answer to a frame given up is told apart by it
     */
    sent(nowMs, frameId = null) {
        this.inFlight = { sentMs: nowMs, frameId };
        this.lastSentMs = nowMs;
        this.nextAtMs = nowMs + this.intervalMs();
        this.counts.sent += 1;
    }

    /**
     * The worker answered. The cadence follows the measured inference time from the next frame on.
     *
     * @param {number} inferenceMs
     * @param {*} [frameId]
     */
    answered(inferenceMs, frameId = null) {
        if (!this.isInFlight(frameId)) {
            this.counts.late += 1;

            return;
        }

        if (Number.isFinite(inferenceMs) && inferenceMs >= 0) {
            this.inferenceMs = this.inferenceMs === null
                ? inferenceMs
                : this.inferenceMs + this.smoothing * (inferenceMs - this.inferenceMs);
        }

        this.counts.answered += 1;
        // Counted from the send: the cadence is the interval (an answer slower than it - at the minFps floor - lets
        // the next frame go at once)
        this.nextAtMs = this.inFlight.sentMs + this.intervalMs();
        this.inFlight = null;
    }

    /**
     * The worker answered with an error (or skipped the frame): the next frame waits one interval.
     */
    failed(nowMs, frameId = null) {
        if (!this.isInFlight(frameId)) {
            this.counts.late += 1;

            return;
        }

        this.counts.failed += 1;
        this.nextAtMs = (Number.isFinite(nowMs) ? nowMs : this.inFlight.sentMs) + this.intervalMs();
        this.inFlight = null;
    }

    /**
     * What the heartbeat reports (`detector.fps`) and what the page shows.
     */
    stats() {
        const interval = this.intervalMs();

        return {
            fps: Math.round((1000 / interval) * 10) / 10,
            intervalMs: Math.round(interval),
            inferenceMs: this.inferenceMs === null ? null : Math.round(this.inferenceMs * 10) / 10,
            inFlight: this.inFlight !== null,
            // Even the slowest cadence keeps the detector busier than allowed
            slow: this.inferenceMs !== null && this.inferenceMs / this.maxDuty > 1000 / this.minFps,
            ...this.counts,
        };
    }

    // ---- internals

    isInFlight(frameId) {
        return this.inFlight !== null && (frameId === null || this.inFlight.frameId === null || this.inFlight.frameId === frameId);
    }
}

function positive(value, fallback) {
    return Number.isFinite(value) && value > 0 ? value : fallback;
}
