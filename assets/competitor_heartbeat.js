/**
 * The heartbeat of a competitor's own page while their round runs (reliability hardening T2,
 * docs/features/competitions-management/competitor-pages.md "Timing you can trust"): the server learns when it last
 * heard from this phone and whether the phone held an unsent tap then - so a tap that reached it late is trusted unless
 * the phone's own heartbeats contradict it (TimeProposalTiming on the server).
 *
 * - Only for rounds the page may propose in (`canPropose` + the signed `heartbeat` token of the state JSON) that run now
 *   - or whose tap still waits on the phone (`active()` decides, the page knows both).
 * - Every 15 s ±20 % while the page is visible; at once (at most one beat per 2 s) when the browser is back online or
 *   the page is shown again (`nudge()`); nothing while hidden.
 * - `POST /-/competitor-heartbeat {token, atMs, offsetMs, rttMs, sampleAgeMs, unsent, oldestUnsentTapMs}` - no cookie
 *   (`credentials: 'omit'`), the token is the right. `summary(roundId)` reads the outbox (and the confirm sheet) fresh
 *   for every beat, so a tap of another tab counts too.
 * - The answer `{serverNowMs}` is a clock sample (`onSample`); a 403 (an expired token) asks the page for the state,
 *   which carries a fresh token (`onExpired`). Failures are silent - the next beat comes as planned.
 *
 * DOM-free: fetch, the clock, the timers and the page's knowledge are injected - tests/competitor-proposals-harness.mjs.
 */

export const HEARTBEAT_MS = 15 * 1000;
export const HEARTBEAT_SPREAD = 0.2;
export const MIN_GAP_MS = 2000;
const FETCH_TIMEOUT_MS = 10 * 1000;

/**
 * The next beat: 15 s ±20 %.
 */
export function heartbeatDelay(random = Math.random) {
    return Math.round(HEARTBEAT_MS * (1 - HEARTBEAT_SPREAD + random() * 2 * HEARTBEAT_SPREAD));
}

/**
 * The beat's JSON (pure - pinned by the harness).
 */
export function heartbeatBody(token, clock, summary) {
    const number = (value) => (typeof value === 'number' && Number.isFinite(value) ? value : null);
    const integer = (value) => (number(value) === null ? null : Math.round(value));

    return {
        token,
        atMs: integer(clock?.atMs),
        offsetMs: number(clock?.offsetMs),
        rttMs: number(clock?.rttMs),
        sampleAgeMs: integer(clock?.sampleAgeMs),
        unsent: Number.isInteger(summary?.unsent) && summary.unsent >= 0 ? summary.unsent : 0,
        oldestUnsentTapMs: integer(summary?.oldestUnsentTapMs),
    };
}

/**
 * One beat over the network - never rejects: {status: 'ok', serverNowMs, sentAtMs, receivedAtMs} | {status: 'expired'}
 * | {status: 'error'}.
 */
export async function sendHeartbeat(url, body, fetchFn = globalThis.fetch, now = () => Date.now()) {
    const controller = typeof AbortController === 'function' ? new AbortController() : null;
    const timer = controller ? setTimeout(() => controller.abort(), FETCH_TIMEOUT_MS) : null;
    const sentAtMs = now();

    try {
        const response = await fetchFn(url, {
            method: 'POST',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
            credentials: 'omit',
            cache: 'no-store',
            body: JSON.stringify(body),
            signal: controller?.signal,
        });
        const receivedAtMs = now();
        let data = null;

        try {
            data = await response.json();
        } catch {
            data = null;
        }

        if (response.status === 403) {
            return { status: 'expired' };
        }

        if (!response.ok || typeof data?.serverNowMs !== 'number') {
            return { status: 'error' };
        }

        return { status: 'ok', serverNowMs: data.serverNowMs, sentAtMs, receivedAtMs };
    } catch {
        return { status: 'error' };
    } finally {
        if (timer !== null) {
            clearTimeout(timer);
        }
    }
}

export class CompetitorHeartbeat {
    /**
     * @param {object} options
     * @param {function(object): Promise<object>} options.send           (body) → what sendHeartbeat() answers
     * @param {function(): {roundId: string, token: string}[]} options.active  the rounds to beat for now
     * @param {function(string): Promise<{unsent: number, oldestUnsentTapMs: null|number}>} options.summary
     * @param {function(): {atMs: number, offsetMs: number, rttMs: null|number, sampleAgeMs: null|number}} options.clock
     * @param {function(object): void} [options.onSample]  {sentAtMs, receivedAtMs, serverNowMs}
     * @param {function(): void} [options.onExpired]
     * @param {function(): boolean} [options.isVisible]
     * @param {function(): number} [options.now]
     * @param {function(function(), number): *} [options.schedule]
     * @param {function(*): void} [options.cancel]
     * @param {function(): number} [options.random]
     */
    constructor({
        send,
        active,
        summary,
        clock,
        onSample = () => {},
        onExpired = () => {},
        isVisible = () => true,
        now = () => Date.now(),
        schedule = (task, ms) => setTimeout(task, ms),
        cancel = (timer) => clearTimeout(timer),
        random = () => Math.random(),
    }) {
        this.sendFn = send;
        this.activeFn = active;
        this.summaryFn = summary;
        this.clockFn = clock;
        this.onSample = onSample;
        this.onExpired = onExpired;
        this.isVisible = isVisible;
        this.now = now;
        this.scheduleFn = schedule;
        this.cancelFn = cancel;
        this.random = random;
        this.timer = null;
        this.running = null;
        this.again = false;
        this.lastBeatAt = null;
        this.started = false;
        this.closed = false;
    }

    /**
     * Starts beating (at once when a round is active).
     */
    start() {
        this.started = true;
        this.nudge();
    }

    close() {
        this.closed = true;
        this.clearTimer();
    }

    /**
     * Back online, shown again, a state that made a round active: a beat now - at most one per MIN_GAP_MS.
     */
    nudge() {
        if (!this.started || this.closed) {
            return;
        }

        const since = this.lastBeatAt === null ? Infinity : this.now() - this.lastBeatAt;
        this.plan(since >= MIN_GAP_MS ? 0 : MIN_GAP_MS - since);
    }

    /**
     * The page's state changed: beat soon if a round became active, else keep the plan (or stop when none is).
     */
    update() {
        if (!this.started || this.closed) {
            return;
        }

        if (this.activeFn().length === 0) {
            this.clearTimer();
        } else if (this.timer === null && this.running === null && this.isVisible()) {
            this.nudge();
        }
    }

    clearTimer() {
        if (this.timer !== null) {
            this.cancelFn(this.timer);
            this.timer = null;
        }
    }

    plan(delay) {
        this.clearTimer();

        if (this.closed) {
            return;
        }

        this.timer = this.scheduleFn(() => {
            this.timer = null;
            this.run();
        }, Math.max(0, delay));
    }

    /**
     * One run: a beat for every active round (a hidden page beats nothing), then the next in 15 s ±20 %.
     */
    run() {
        if (this.closed) {
            return Promise.resolve();
        }

        if (this.running !== null) {
            this.again = true;

            return this.running;
        }

        this.running = this.beatAll().catch(() => {}).finally(() => {
            this.running = null;

            if (this.closed) {
                return;
            }

            if (this.again) {
                this.again = false;
                this.nudge();
            } else if (this.activeFn().length > 0 && this.isVisible()) {
                this.plan(heartbeatDelay(this.random));
            }
        });

        return this.running;
    }

    async beatAll() {
        if (!this.isVisible()) {
            return;
        }

        for (const round of this.activeFn()) {
            if (this.closed) {
                return;
            }

            const summary = await this.summaryFn(round.roundId);
            const body = heartbeatBody(round.token, this.clockFn(), summary);
            this.lastBeatAt = this.now();
            const answer = await this.sendFn(body);

            if (answer?.status === 'ok') {
                this.onSample({ sentAtMs: answer.sentAtMs, receivedAtMs: answer.receivedAtMs, serverNowMs: answer.serverNowMs });
            } else if (answer?.status === 'expired') {
                this.onExpired();
            }
        }
    }
}
