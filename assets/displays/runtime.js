// The display runtime (docs/features/competitions-management/toolkit/D-displays-overlays.md §7.3, §7.4; competition
// toolkit D4): keeps a display live for hours on a hall TV or in OBS. DOM-free - the page (assets/display.js) injects
// fetch, the clock, timers, the Mercure stream and the renderer; tests/display-runtime-harness.mjs drives it with fakes.
//
// - First state inline (CompetitionDisplayPayload), then GET /display/{token}/state: on every display signal
//   (throttled - one fetch per SIGNAL_DEBOUNCE_MS at most, one in flight), every 60 s as a safety net (every 5 s while
//   the stream is down), at the state's `refreshAtMs` (a reveal, a round switch; + up to 250 ms jitter), 2 s before and
//   1 s after a scheduled start, and when the page becomes visible again.
// - Stopwatch pushes of the shown round are applied at once (newest `asOfMs` wins); one of another followed round
//   means the server may pick another round now - a state fetch.
// - Every fetch is a clock sample (createServerClock: the shortest round trip wins), so the timer follows the server.
// - A newer `designVersion` fetches the design and rebuilds the picture.
// - 404: the link is no longer valid - the invalid view, no stream, a check every 5 minutes (a regenerated token by
//   mistake: the organiser opens the new URL; a restored one comes back by itself).
// - Reload after a deploy (`rendererBuild` changed) or after 20 h - only while no round counts down or runs, and only
//   right after a successful fetch (never into an offline page).
// - No leaks: every timer lives in one named slot (a new one cancels the old), no state history is kept.

import { OfficialResultsEvents } from '../official_results_events.js';
import { createServerClock, isNewerState, stopwatchView } from '../round_stopwatch_clock.js';

export const SIGNAL_DEBOUNCE_MS = 1500;
export const SAFETY_POLL_MS = 60 * 1000;
export const DOWN_POLL_MS = 5 * 1000;
export const INVALID_POLL_MS = 5 * 60 * 1000;
export const REFRESH_JITTER_MS = 250;
export const MAX_UPTIME_MS = 20 * 3600 * 1000;
export const BEFORE_START_MS = 2000;
export const AFTER_START_MS = 1000;
// setTimeout's limit is ~24.8 days; nothing a display waits for is that far
const MAX_DELAY_MS = 24 * 3600 * 1000;
const CALM_PHASES = ['idle', 'stopped', 'times_up'];

export class DisplayRuntime {
    /**
     * @param {object} options
     * @param {object} options.state                   the first state (inline in the page)
     * @param {object} options.design                  the stored design
     * @param {object} options.renderer                render.js createRenderer()
     * @param {function(): Promise<{status: 'ok'|'gone'|'error', state?: object, sentAtMs?: number, receivedAtMs?: number}>} options.fetchState
     * @param {function(): Promise<{status: 'ok'|'gone'|'error', designVersion?: number, design?: object}>} options.fetchDesign
     * @param {function(): void} options.reload
     * @param {function(): number} [options.now]
     * @param {function(function(), number): *} [options.schedule]
     * @param {function(*): void} [options.cancel]
     * @param {function(object): object} [options.createEvents]   OfficialResultsEvents-like: start/update/suspend/close/isLive
     * @param {function(): number} [options.random]
     * @param {null|function(): *} [options.requestWakeLock]
     */
    constructor({
        state,
        design,
        renderer,
        fetchState,
        fetchDesign,
        reload,
        now = () => Date.now(),
        schedule = (task, ms) => setTimeout(task, ms),
        cancel = (timer) => clearTimeout(timer),
        createEvents = (options) => new OfficialResultsEvents(options),
        random = () => Math.random(),
        requestWakeLock = null,
    }) {
        this.state = state;
        this.initialDesign = design;
        this.renderer = renderer;
        this.fetchStateFn = fetchState;
        this.fetchDesignFn = fetchDesign;
        this.reload = reload;
        this.now = now;
        this.scheduleFn = schedule;
        this.cancelFn = cancel;
        this.createEvents = createEvents;
        this.random = random;
        this.requestWakeLock = requestWakeLock;

        this.clock = createServerClock({ now });
        this.timers = {};
        this.inFlight = null;
        this.fetchAgain = false;
        this.designVersion = typeof state?.designVersion === 'number' ? state.designVersion : 0;
        this.designLoading = null;
        this.build = typeof state?.rendererBuild === 'string' ? state.rendererBuild : null;
        this.startedAtMs = now();
        this.invalid = false;
        this.lastFetchOk = true;
        this.events = null;
        this.closed = false;
        this.reloading = false;
    }

    start() {
        if (typeof this.state?.serverNowMs === 'number') {
            this.clock.hint(this.state.serverNowMs - this.now());
        }

        this.renderer.setDesign(this.initialDesign);
        this.events = this.createEvents({
            subscription: this.state?.mercure ?? null,
            refresh: () => this.refresh(),
            onMessage: (message) => this.onMessage(message),
            now: this.now,
            schedule: this.scheduleFn,
            cancel: this.cancelFn,
        });
        this.events.start();
        this.afterState();
        this.schedulePoll();
        this.acquireWakeLock();
    }

    close() {
        this.closed = true;

        for (const name of Object.keys(this.timers)) {
            this.clear(name);
        }

        this.events?.close();
    }

    serverNow() {
        return this.clock.serverNow();
    }

    // ---- timers: one per slot

    set(name, delay, task) {
        this.clear(name);

        if (this.closed || delay === null || !Number.isFinite(delay)) {
            return;
        }

        this.timers[name] = this.scheduleFn(() => {
            delete this.timers[name];
            task();
        }, Math.max(0, Math.min(MAX_DELAY_MS, delay)));
    }

    clear(name) {
        if (name in this.timers) {
            this.cancelFn(this.timers[name]);
            delete this.timers[name];
        }
    }

    activeTimers() {
        return Object.keys(this.timers).length;
    }

    // ---- messages

    onMessage(message) {
        if (this.closed || message === null || typeof message !== 'object') {
            return;
        }

        if (message.type === 'display.signal') {
            this.signal();

            return;
        }

        // The public stopwatch payload carries no type (RoundStopwatchState)
        if (message.type === undefined && typeof message.roundId === 'string' && 'status' in message) {
            const roundId = this.state?.round?.id ?? null;

            if (roundId !== null && message.roundId.toLowerCase() === roundId.toLowerCase()) {
                if (isNewerState(this.state.stopwatch, message)) {
                    this.state = { ...this.state, stopwatch: message };
                    this.afterState();
                }
            } else {
                // Another followed round started or stopped - the server may pick it now
                this.signal();
            }
        }
    }

    /**
     * Something changed: one state fetch within SIGNAL_DEBOUNCE_MS - more signals meanwhile ride on it.
     */
    signal() {
        if (this.closed || 'signal' in this.timers) {
            return;
        }

        this.set('signal', SIGNAL_DEBOUNCE_MS, () => this.refresh());
    }

    onVisible() {
        this.refresh();
        this.acquireWakeLock();
    }

    acquireWakeLock() {
        if (this.requestWakeLock === null || this.state?.kind !== 'screen') {
            return;
        }

        try {
            Promise.resolve(this.requestWakeLock()).catch(() => {});
        } catch {
            // Unsupported (old TV browsers): the organiser turns the TV's sleep off
        }
    }

    // ---- state

    /**
     * Fetches the state - one at a time; a call while one runs fetches once more after it. Resolves to the kind
     * official_results_events.js understands (ok / forbidden / error).
     */
    refresh() {
        if (this.closed) {
            return Promise.resolve('error');
        }

        if (this.inFlight !== null) {
            this.fetchAgain = true;

            return this.inFlight;
        }

        this.inFlight = Promise.resolve()
            .then(() => this.fetchStateFn())
            .catch(() => ({ status: 'error' }))
            .then((answer) => {
                this.inFlight = null;
                const kind = this.received(answer);

                if (this.fetchAgain && !this.closed) {
                    this.fetchAgain = false;
                    this.signal();
                }

                return kind;
            });

        return this.inFlight;
    }

    received(answer) {
        if (this.closed) {
            return 'error';
        }

        if (answer?.status === 'gone') {
            this.lastFetchOk = true;
            this.invalid = true;
            this.renderer.showInvalid(this.state?.texts?.invalid ?? '');
            this.events?.suspend();
            this.clear('tick');
            this.clear('refreshAt');
            this.clear('beforeStart');
            this.clear('afterStart');
            this.schedulePoll();

            return 'forbidden';
        }

        if (answer?.status !== 'ok' || answer.state === null || typeof answer.state !== 'object') {
            this.lastFetchOk = false;
            this.schedulePoll();

            return 'error';
        }

        this.lastFetchOk = true;
        this.clock.observe({ sentAtMs: answer.sentAtMs, receivedAtMs: answer.receivedAtMs, serverNowMs: answer.state.serverNowMs });

        if (this.invalid) {
            this.invalid = false;
            this.renderer.showInvalid(null);
        }

        this.applyState(answer.state);
        this.events?.update(answer.state.mercure ?? null);
        this.schedulePoll();
        this.maybeReload();

        return 'ok';
    }

    applyState(state) {
        const current = this.state;
        let next = state;

        // A push that arrived while this fetch was on the way is newer - keep it
        if (current?.round?.id && current.round.id === state.round?.id && current.stopwatch && !isNewerState(current.stopwatch, state.stopwatch)) {
            next = { ...state, stopwatch: current.stopwatch };
        }

        this.state = next;

        if (typeof next.designVersion === 'number' && next.designVersion > this.designVersion) {
            this.loadDesign(next.designVersion);
        }

        this.afterState();
    }

    loadDesign(version) {
        if (this.designLoading !== null && this.designLoading >= version) {
            return;
        }

        this.designLoading = version;

        Promise.resolve()
            .then(() => this.fetchDesignFn())
            .catch(() => ({ status: 'error' }))
            .then((answer) => {
                this.designLoading = null;

                if (this.closed || answer?.status !== 'ok' || typeof answer.designVersion !== 'number') {
                    return;
                }

                if (answer.designVersion > this.designVersion) {
                    this.designVersion = answer.designVersion;
                    this.renderer.setDesign(answer.design);
                    this.afterState();
                }
            });
    }

    /**
     * A new picture: draw it, then plan what changes it by itself.
     */
    afterState() {
        if (this.closed || this.invalid) {
            return;
        }

        this.tick();
        this.scheduleRefreshAt();
        this.scheduleStartFetches();
    }

    tick() {
        if (this.closed || this.invalid) {
            return;
        }

        const now = this.serverNow();
        this.renderer.render(this.state, now);
        const delay = this.renderer.nextChangeMs(this.state, now);

        if (delay === null) {
            this.clear('tick');
        } else {
            this.set('tick', delay, () => this.tick());
        }
    }

    scheduleRefreshAt() {
        const at = this.state?.refreshAtMs;

        if (typeof at !== 'number') {
            this.clear('refreshAt');

            return;
        }

        this.set('refreshAt', at - this.serverNow() + this.random() * REFRESH_JITTER_MS, () => this.refresh());
    }

    scheduleStartFetches() {
        const stopwatch = this.state?.stopwatch ?? null;
        const start = stopwatch?.status === 'scheduled' && typeof stopwatch.startedAtMs === 'number' ? stopwatch.startedAtMs : null;

        if (start === null) {
            this.clear('beforeStart');
            this.clear('afterStart');

            return;
        }

        const untilStart = start - this.serverNow();

        if (untilStart > BEFORE_START_MS) {
            this.set('beforeStart', untilStart - BEFORE_START_MS, () => this.refresh());
        } else {
            this.clear('beforeStart');
        }

        if (untilStart + AFTER_START_MS > 0) {
            this.set('afterStart', untilStart + AFTER_START_MS, () => this.refresh());
        } else {
            this.clear('afterStart');
        }
    }

    schedulePoll() {
        let delay = SAFETY_POLL_MS;

        if (this.invalid) {
            delay = INVALID_POLL_MS;
        } else if (!(this.events?.isLive() ?? false)) {
            delay = DOWN_POLL_MS;
        }

        this.set('poll', delay, () => this.refresh());
    }

    /**
     * After a deploy (another renderer build) or 20 h: reload - only at a calm moment of the shown round and right
     * after a successful fetch, so the page that loads is a working one.
     */
    shouldReload() {
        if (this.invalid || !this.lastFetchOk) {
            return false;
        }

        const build = typeof this.state?.rendererBuild === 'string' ? this.state.rendererBuild : null;
        const deployed = build !== null && this.build !== null && build !== this.build;
        const old = this.now() - this.startedAtMs >= MAX_UPTIME_MS;

        if (!deployed && !old) {
            return false;
        }

        return CALM_PHASES.includes(stopwatchView(this.state?.stopwatch ?? null, this.serverNow()).phase);
    }

    maybeReload() {
        if (!this.reloading && this.shouldReload()) {
            this.reloading = true;
            this.close();
            this.reload();
        }
    }
}
