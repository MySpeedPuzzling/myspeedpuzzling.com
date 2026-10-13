/**
 * The server's time on a participant's phone (docs/features/online-competitions/README.md §5.2) - so every phone
 * counts down, starts and stamps its finish on the same clock, with no tick from the server.
 *
 * - A sample: the server's `serverNowMs` against the phone's monotonic clock (`performance.now()`) taken before and
 *   after the request (t0, t1 - Resource Timing's requestStart / responseStart where the caller has them, which leaves
 *   out the time the request waited inside the browser). offset = serverNowMs − (timeOrigin + (t0 + t1) / 2): server
 *   minus local. Its error is at most rtt / 2 - an asymmetric route can not be seen, only bounded.
 * - The estimate is the sample of a sliding window with the smallest error bound: rtt / 2 plus its age × the drift two
 *   clocks may have (DRIFT_PPM), so a fresh, slightly slower sample replaces an old fast one in time.
 * - sync() takes 8 samples ~150 ms apart (page load, back from the background); every API answer is a free sample
 *   through observe() (the heartbeat every 15 s, events, the state).
 * - A sample the estimate can not explain (they differ by more than both error bounds) is either wrong (a cached
 *   answer) or the clocks moved: it counts only once another sample confirms it - one is asked for at once.
 * - `performance.now()` stops while the phone sleeps (everywhere but Windows), `Date.now()` does not. When the two
 *   disagree about how much time passed (by more than JUMP_THRESHOLD_MS), the phone slept or its wall clock was
 *   changed: the estimate moves forward by the difference at once (right after a sleep - the page was hidden anyway),
 *   the window is emptied and a sync starts. A wall clock set back moves nothing (`performance.now()` did not move);
 *   the sync confirms it.
 * - Small corrections are slewed: the shown clock runs up to SLEW_RATE faster or slower until it agrees, so a running
 *   stopwatch never jumps and never goes back. Corrections over SLEW_MAX_MS, the first sync and a sync after a jump
 *   apply at once. serverNow() is the shown time; toServerMs() / stamp() use the estimate itself (event and frame
 *   times - what the review computes the result from).
 * - Before any sample the phone's own clock (`Date.now()`) stands in; quality().synced says whether a sample under
 *   READY_RTT_MS exists - the checklist's "clock synced" row and the readiness gate.
 *
 * No DOM: the request, the clocks and the waits are injected - pinned by tests/online-clock-harness.mjs.
 */

// The first sync: this many samples, this far apart
export const SYNC_SAMPLES = 8;
export const SYNC_GAP_MS = 150;
// Samples kept for the estimate - the newest WINDOW_SIZE, none older than MAX_SAMPLE_AGE_MS (but always the newest one)
export const WINDOW_SIZE = 16;
export const MAX_SAMPLE_AGE_MS = 15 * 60 * 1000;
// How fast the phone's monotonic clock and the server's may drift apart - a sample's error bound grows with its age
export const DRIFT_PPM = 50;
// Corrections up to this size are slewed at SLEW_RATE (0.1 = the shown clock runs 10 % fast or slow while it catches up)
export const SLEW_MAX_MS = 500;
export const SLEW_RATE = 0.1;
// Date.now() and performance.now() disagreeing by more than this about the time that passed: a sleep or a clock change
export const JUMP_THRESHOLD_MS = 1000;
// A sample with a round trip under this makes the phone ready to compete (README §5.2 "Readiness gate")
export const READY_RTT_MS = 1000;
// A sample slower than this bounds nothing useful - not taken
export const MAX_RTT_MS = 10000;
// Two samples agree when their offsets differ by no more than both error bounds plus this (rounding, resolution)
export const CONSISTENCY_SLACK_MS = 25;
// A sample the estimate can not explain counts once this many agreeing samples say the same - that many are asked for
// at once (never while a sync runs: its own samples decide, so confirmations can not chain)
export const CONFIRMATIONS = 2;
// After a jump or before the first sync corrections apply at once until this many samples agree (or a sync ended)
export const SETTLED_AFTER_SAMPLES = 3;
// Resource Timing values this far outside the measured window are still taken (timer granularity)
const TIMING_TOLERANCE_MS = 1;

/**
 * @typedef {object} ClockQuality
 * @property {number|null} offsetMs      server − local (timeOrigin + performance.now()), null before the first sample
 * @property {number|null} rttMs         round trip of the sample the estimate stands on
 * @property {number|null} ageMs         how long ago that sample was taken
 * @property {number|null} errorMs       its error bound now: rtt / 2 + age × drift
 * @property {number} samples            samples in the window
 * @property {boolean} synced            a sample under READY_RTT_MS since the last jump
 * @property {boolean} settled           corrections are slewed (false: the next one applies at once)
 * @property {boolean} slewing           the shown clock is still catching up with the estimate
 * @property {number|null} wallOffsetMs  server − Date.now(): how far the phone's own clock is off
 * @property {number} jumps              sleeps / clock changes seen
 */

/**
 * @param {object} [options]
 * @param {function(): Promise<number|{serverNowMs: number, t0?: number, t1?: number}>|null} [options.fetchTime]
 *        one request for the server's time - resolves to `serverNowMs`, or `{serverNowMs, t0, t1}` with Resource
 *        Timing's requestStart / responseStart (performance.now() clock); fetchServerTime() is one
 * @param {function(): number} [options.now]         the monotonic clock (performance.now)
 * @param {number} [options.timeOrigin]              performance.timeOrigin - offsets are reported against it
 * @param {function(): number} [options.wallNow]     the wall clock (Date.now) - sleep and clock-change detection only
 * @param {function(number): Promise} [options.wait]
 * @param {function({deltaMs: number, atPerf: number}): void} [options.onJump]  a sleep or a clock change was seen
 * @param {function(): void} [options.onChange]      the estimate changed
 * @returns {ServerClock}
 */
export function createClock(options = {}) {
    return new ServerClock(options);
}

export class ServerClock {
    constructor({
        fetchTime = null,
        now = () => globalThis.performance.now(),
        timeOrigin = null,
        wallNow = () => Date.now(),
        wait = (ms) => new Promise((resolve) => {
            setTimeout(resolve, ms);
        }),
        windowSize = WINDOW_SIZE,
        maxSampleAgeMs = MAX_SAMPLE_AGE_MS,
        driftPpm = DRIFT_PPM,
        slewMaxMs = SLEW_MAX_MS,
        slewRate = SLEW_RATE,
        jumpThresholdMs = JUMP_THRESHOLD_MS,
        readyRttMs = READY_RTT_MS,
        autoResync = true,
        onJump = () => {},
        onChange = () => {},
    } = {}) {
        this.fetchTime = fetchTime;
        this.readPerf = now;
        this.wallNow = wallNow;
        this.wait = wait;
        // Any origin works (the offset absorbs it); the wall clock's at creation makes offsets read as "server − phone"
        this.timeOrigin = Number.isFinite(timeOrigin) ? timeOrigin : wallNow() - now();
        this.windowSize = windowSize;
        this.maxSampleAgeMs = maxSampleAgeMs;
        this.driftPpm = driftPpm;
        this.slewMaxMs = slewMaxMs;
        this.slewRate = slewRate;
        this.jumpThresholdMs = jumpThresholdMs;
        this.readyRttMs = readyRttMs;
        this.autoResync = autoResync;
        this.onJump = onJump;
        this.onChange = onChange;

        /** @type {{offset: number, rtt: number, at: number}[]} samples that agree, oldest first */
        this.samples = [];
        // Samples the estimate could not explain, waiting for a confirmation
        this.suspects = [];
        // The estimate (server − local), and the shown clock catching up with it: {from, since}
        this.target = null;
        this.slew = null;
        this.settled = false;
        // Date.now() − (timeOrigin + performance.now()) when last looked at - its change is a sleep or a clock change
        this.lastSkew = null;
        this.lastJumpAt = -Infinity;
        this.jumps = 0;
        this.syncing = null;
        this.resyncWanted = false;
        this.closed = false;

        this.check();
    }

    /**
     * The server's time now, as the page shows it (slewed - never jumps for a small correction).
     */
    serverNow() {
        const perf = this.readPerf();
        this.check(perf);
        const offset = this.displayOffset(perf);

        return offset === null ? this.wallNow() : this.timeOrigin + perf + offset;
    }

    /**
     * The server's time at a moment of the monotonic clock (a tap's `performance.now()`, a frame's captureTime) - by
     * the estimate itself, not the shown clock.
     *
     * @param {number} perfMs
     */
    toServerMs(perfMs) {
        if (this.target === null) {
            // No sample yet: the phone's own clock
            return perfMs + (this.wallNow() - this.readPerf());
        }

        return this.timeOrigin + perfMs + this.target;
    }

    /**
     * The times an event carries (implementation-plan.md §3.1): `clientMs` (server time, whole ms - stored as bigint),
     * the raw `perfMs`, and the offset and round trip they were computed with.
     *
     * @param {number|null} [perfMs] the moment (default: now)
     * @returns {{clientMs: number, perfMs: number, offsetMs: number|null, rttMs: number|null}}
     */
    stamp(perfMs = null) {
        const perf = this.readPerf();
        this.check(perf);
        const at = Number.isFinite(perfMs) ? perfMs : perf;
        const best = this.best(perf);

        return {
            clientMs: Math.round(this.toServerMs(at)),
            perfMs: at,
            offsetMs: this.target,
            rttMs: best === null ? null : best.rtt,
        };
    }

    /**
     * @returns {ClockQuality}
     */
    quality() {
        const perf = this.readPerf();
        this.check(perf);
        const best = this.best(perf);
        // Brings the slew up to date
        this.displayOffset(perf);

        return {
            offsetMs: this.target,
            rttMs: best === null ? null : best.rtt,
            ageMs: best === null ? null : perf - best.at,
            errorMs: best === null ? null : this.errorOf(best, perf),
            samples: this.samples.length,
            synced: this.samples.some((sample) => sample.rtt <= this.readyRttMs),
            settled: this.settled,
            slewing: this.slew !== null,
            wallOffsetMs: this.target === null ? null : this.timeOrigin + perf + this.target - this.wallNow(),
            jumps: this.jumps,
        };
    }

    /**
     * A free sample from any answer that carries `serverNowMs` (heartbeat, events, state): t0 / t1 on the
     * performance.now() clock around the request - or Resource Timing's requestStart / responseStart. `w0` / `w1`
     * (Date.now() at the same moments), when given, refuse a sample the phone slept through.
     *
     * @returns {boolean} taken into the estimate (false: refused, or waiting for a confirmation)
     */
    observe({ t0, t1, serverNowMs, w0 = null, w1 = null } = {}) {
        const perf = this.readPerf();
        this.check(perf);

        if (!Number.isFinite(t0) || !Number.isFinite(t1) || !Number.isFinite(serverNowMs) || t1 < t0) {
            return false;
        }

        const rtt = t1 - t0;

        // Too slow to say anything; begun before a sleep or a clock change; from the future (a caller's mistake)
        if (rtt > MAX_RTT_MS || t0 < this.lastJumpAt || t1 > perf + TIMING_TOLERANCE_MS) {
            return false;
        }

        if (Number.isFinite(w0) && Number.isFinite(w1) && Math.abs((w1 - w0) - rtt) > this.jumpThresholdMs) {
            return false;
        }

        const at = (t0 + t1) / 2;

        return this.accept({ offset: serverNowMs - (this.timeOrigin + at), rtt, at }, perf);
    }

    /**
     * Takes `samples` samples `gapMs` apart. A call while one runs gets the running one.
     *
     * @returns {Promise<ClockQuality>}
     */
    sync({ samples = SYNC_SAMPLES, gapMs = SYNC_GAP_MS } = {}) {
        if (this.fetchTime === null || this.closed) {
            return Promise.resolve(this.quality());
        }

        if (this.syncing !== null) {
            return this.syncing;
        }

        const run = async () => {
            for (let index = 0; index < samples && !this.closed; index++) {
                if (index > 0) {
                    await this.wait(gapMs);
                }

                await this.takeSample();
            }

            if (this.samples.length > 0) {
                this.settled = true;
            }
        };

        this.syncing = run().catch(() => {}).then(() => {
            this.syncing = null;

            // A jump while it ran: its samples are gone - again
            if (this.resyncWanted && !this.closed) {
                this.resyncWanted = false;

                return this.sync({ samples, gapMs });
            }

            return this.quality();
        });

        return this.syncing;
    }

    /**
     * Looks for a sleep or a wall clock change since the last look (serverNow(), stamp(), quality() and observe() look
     * by themselves; the page also calls it on visibilitychange / pageshow).
     *
     * @returns {boolean} a jump was seen
     */
    check(perf = this.readPerf()) {
        const skew = this.wallNow() - (this.timeOrigin + perf);
        const previous = this.lastSkew;
        this.lastSkew = skew;

        if (previous === null || Math.abs(skew - previous) <= this.jumpThresholdMs) {
            return false;
        }

        // Elapsed wall time minus elapsed monotonic time
        const deltaMs = skew - previous;
        this.jumps += 1;
        this.lastJumpAt = perf;

        if (deltaMs > 0) {
            // performance.now() stood still (a sleep) - or the wall clock was set forward, which the sync then corrects
            if (this.target !== null) {
                this.target += deltaMs;
            }

            this.samples = [];
            this.suspects = [];
            this.slew = null;
            this.settled = false;
        }

        this.notify(this.onJump, { deltaMs, atPerf: perf });
        this.notify(this.onChange);

        if (this.autoResync) {
            this.requestSync(true);
        }

        return true;
    }

    /**
     * No more syncs (the page is going).
     */
    close() {
        this.closed = true;
    }

    // ---- internals

    async takeSample() {
        const t0 = this.readPerf();
        const w0 = this.wallNow();
        let answer;

        try {
            answer = await this.fetchTime();
        } catch (e) {
            return false;
        }

        const t1 = this.readPerf();
        const w1 = this.wallNow();
        const reading = readTimeAnswer(answer);

        if (reading === null) {
            return false;
        }

        // Slept (or the wall clock changed) while the request was out: the sample says nothing - and the jump is seen
        if (Math.abs((w1 - w0) - (t1 - t0)) > this.jumpThresholdMs) {
            this.check(t1);

            return false;
        }

        let start = t0;
        let end = t1;

        if (
            Number.isFinite(reading.t0)
            && Number.isFinite(reading.t1)
            && reading.t0 <= reading.t1
            && reading.t0 >= t0 - TIMING_TOLERANCE_MS
            && reading.t1 <= t1 + TIMING_TOLERANCE_MS
        ) {
            start = reading.t0;
            end = reading.t1;
        }

        return this.observe({ t0: start, t1: end, serverNowMs: reading.serverNowMs });
    }

    accept(sample, perf) {
        const best = this.best(perf);

        if (best === null || this.consistent(sample, best, perf)) {
            this.samples.push(sample);
            this.suspects = [];
            this.retarget(perf);

            return true;
        }

        // The estimate can not explain it: wrong, or the clocks moved - wait for a second one saying the same
        if (this.suspects.length > 0 && this.suspects.every((suspect) => this.consistent(sample, suspect, perf))) {
            this.suspects.push(sample);
        } else {
            this.suspects = [sample];
        }

        if (this.suspects.length >= CONFIRMATIONS) {
            this.samples = this.suspects;
            this.suspects = [];
            this.retarget(perf);

            return true;
        }

        this.requestConfirmation();

        return false;
    }

    consistent(a, b, perf) {
        return Math.abs(a.offset - b.offset) <= this.errorOf(a, perf) + this.errorOf(b, perf) + CONSISTENCY_SLACK_MS;
    }

    errorOf(sample, perf) {
        return sample.rtt / 2 + Math.max(0, perf - sample.at) * this.driftPpm / 1e6;
    }

    best(perf) {
        this.prune(perf);
        let best = null;
        let bestError = Infinity;

        for (const sample of this.samples) {
            const error = this.errorOf(sample, perf);

            if (error < bestError) {
                best = sample;
                bestError = error;
            }
        }

        return best;
    }

    prune(perf) {
        if (this.samples.length > 1) {
            const fresh = this.samples.filter((sample) => perf - sample.at <= this.maxSampleAgeMs);
            this.samples = fresh.length > 0 ? fresh : [this.samples[this.samples.length - 1]];
        }

        if (this.samples.length > this.windowSize) {
            this.samples = this.samples.slice(-this.windowSize);
        }
    }

    retarget(perf) {
        const best = this.best(perf);

        if (best === null) {
            return;
        }

        const shown = this.displayOffset(perf);
        this.target = best.offset;

        if (shown === null || !this.settled || Math.abs(this.target - shown) > this.slewMaxMs) {
            this.slew = null;
        } else if (this.target !== shown) {
            this.slew = { from: shown, since: perf };
        }

        if (!this.settled && this.samples.length >= SETTLED_AFTER_SAMPLES) {
            this.settled = true;
        }

        this.notify(this.onChange);
    }

    notify(listener, argument) {
        try {
            listener(argument);
        } catch (e) {
            // A page's listener must never break the clock (serverNow() runs in every animation frame)
        }
    }

    displayOffset(perf) {
        if (this.target === null) {
            return null;
        }

        if (this.slew === null) {
            return this.target;
        }

        const distance = this.target - this.slew.from;
        const moved = Math.max(0, perf - this.slew.since) * this.slewRate;

        if (moved >= Math.abs(distance)) {
            this.slew = null;

            return this.target;
        }

        return this.slew.from + Math.sign(distance) * moved;
    }

    requestSync(afterJump = false) {
        if (this.fetchTime === null || this.closed) {
            return;
        }

        if (this.syncing !== null) {
            if (afterJump) {
                this.resyncWanted = true;
            }

            return;
        }

        this.sync().catch(() => {});
    }

    requestConfirmation() {
        if (!this.autoResync || this.fetchTime === null || this.closed || this.syncing !== null) {
            return;
        }

        this.sync({ samples: CONFIRMATIONS }).catch(() => {});
    }
}

/**
 * What a time request answered: a number, `{serverNowMs, t0?, t1?}`, or an official_results_api.js answer
 * (`{kind: 'ok', data: {serverNowMs}}`). Null: no usable time.
 */
function readTimeAnswer(answer) {
    if (typeof answer === 'number') {
        return Number.isFinite(answer) ? { serverNowMs: answer } : null;
    }

    if (answer === null || typeof answer !== 'object' || (answer.kind !== undefined && answer.kind !== 'ok')) {
        return null;
    }

    const serverNowMs = Number.isFinite(answer.serverNowMs) ? answer.serverNowMs : answer.data?.serverNowMs;

    if (!Number.isFinite(serverNowMs)) {
        return null;
    }

    return { serverNowMs, t0: answer.t0, t1: answer.t1 };
}

let timeRequests = 0;

/**
 * One request for the server's time (`GET /-/time` → `{"serverNowMs": …}`) - the default `fetchTime` of a page:
 * no cache, no cookies, and Resource Timing's requestStart / responseStart as t0 / t1 when the browser has them (each
 * request gets its own URL, so its entry is found; a full timing buffer simply leaves them out).
 *
 * @param {string} url
 * @param {object} [options]
 * @param {function(string, object): Promise<Response>} [options.fetch]
 * @param {Performance} [options.performance]
 * @param {string} [options.baseUrl]  resolves a relative URL
 * @returns {Promise<{serverNowMs: number, t0?: number, t1?: number}>} rejects when no time came
 */
export async function fetchServerTime(url, {
    fetch: request = (...args) => globalThis.fetch(...args),
    performance = globalThis.performance,
    baseUrl = globalThis.location?.href,
} = {}) {
    timeRequests += 1;
    let target = url;

    try {
        const parsed = new URL(url, baseUrl);
        parsed.searchParams.set('n', String(timeRequests));
        target = parsed.toString();
    } catch (e) {
        // A relative URL without a base: requested as it is, without timing entries
    }

    const started = performance?.now?.() ?? 0;
    const response = await request(target, {
        method: 'GET',
        cache: 'no-store',
        credentials: 'omit',
        headers: { Accept: 'application/json' },
    });

    if (!response.ok) {
        throw new Error(`The time request answered ${response.status}`);
    }

    const data = await response.json();

    if (!Number.isFinite(data?.serverNowMs)) {
        throw new Error('The time answer has no serverNowMs');
    }

    const answer = { serverNowMs: data.serverNowMs };
    const entries = target !== url && typeof performance?.getEntriesByName === 'function'
        ? performance.getEntriesByName(target, 'resource')
        : [];
    const entry = entries.length > 0 ? entries[entries.length - 1] : null;

    if (
        entry !== null
        && entry.requestStart > 0
        && entry.responseStart >= entry.requestStart
        && entry.requestStart >= started - TIMING_TOLERANCE_MS
    ) {
        answer.t0 = entry.requestStart;
        answer.t1 = entry.responseStart;
    }

    return answer;
}
