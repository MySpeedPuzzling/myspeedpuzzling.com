// Analytics Studio (docs/features/competitions-management/analytics-studio.md §5 "How the client learns of the reveal",
// §6 "Freshness", §0a R2/R3) - the refetch planner. DOM-free: the page adapter injects the four fetches, the clock,
// timers and the Mercure stream; tests/analytics-studio-harness.mjs drives it with fakes.
//
// - `state` (§3.1): fetched at the start, then polled - while the round is live (counting down, running, time up, or
//   stopped but not published yet) every 30 s ±20 % when the stream is live (it brings the studio signal) or every 10 s
//   ±20 % without it (down, refused, silent); every 60 s ±20 % otherwise; a failed fetch doubles the pause up to 5
//   minutes (before the first answer the 10 s cadence is the base). A 404 (the event went private, the round lost its
//   slug): a look every 5 minutes.
// - The anonymous public stream (official_results_events.js public subscription, as the competitor page) of the state's
//   `mercure.topics`: `/round-stopwatch/{roundId}` - a push is applied at once (the newest `asOfMs` wins) and asks for
//   the state within 0-1 s (random - many commentator tabs must not hit the server in the same millisecond); and the
//   data-free studio signal `/analytics-studio-signal/{roundId}` (R21-R25: `{type: 'analytics_studio.signal',
//   roundId}` after a results write, a reveal, a round change) - the state again after 2.3 s + 0-1 s (the server's 2 s
//   state cache must have expired: what it kept was computed before the commit the signal follows), signal-driven
//   fetches at least 5 s apart (a finish rush: one fetch per 5 s per tab, the signals in between served by the next).
// - `nextRevealAtMs`: the state again at that moment + 300 ms + 0-1 s (the server must already see it as passed).
// - The server's time: createServerClock() - a hint from the shell's `serverNowMs`, then every state answer is a sample
//   (R2: `serverNowMs` per response).
// - A state whose `visibleHash` differs from the analytics shown → `analytics?v=` again, and the open entrant and the
//   compare set's predictions again - re-rendered in place, no reload. Visibility is never computed here.
// - R3: predictions come only for the entrants on screen - the selected one with its `entrant` answer, the compare set
//   from `predictions?entries=…` (≤ 4). Every answer for a selection or hash that is no longer current is dropped.

import { OfficialResultsEvents } from '../official_results_events.js';
import { createServerClock, isNewerState, stopwatchView } from '../round_stopwatch_clock.js';

export const LIVE_POLL_MS = 10 * 1000;
export const IDLE_POLL_MS = 60 * 1000;
export const MAX_POLL_MS = 5 * 60 * 1000;
export const GONE_POLL_MS = 5 * 60 * 1000;
export const POLL_SPREAD = 0.2;
export const PUSH_JITTER_MS = 1000;
export const REVEAL_GRACE_MS = 300;
export const REVEAL_JITTER_MS = 1000;
export const RECONNECT_JITTER_MS = 3000;
export const VISIBLE_JITTER_MS = 500;
// The studio signal (R25): AnalyticsStudioStateBuilder::TTL_SECONDS * 1000 + 300 (pinned by AnalyticsStudioSignalTimingTest)
export const SIGNAL_TYPE = 'analytics_studio.signal';
export const SIGNAL_SETTLE_MS = 2300;
export const SIGNAL_JITTER_MS = 1000;
export const SIGNAL_MIN_GAP_MS = 5000;
// R26: the live cadence while the stream is live (the signal tells of changes) - the poll stays the safety net
export const STREAMED_LIVE_POLL_MS = 30 * 1000;
// setTimeout's limit is ~24.8 days; nothing here waits that long
const MAX_DELAY_MS = 24 * 3600 * 1000;

function isObject(value) {
    return value !== null && typeof value === 'object' && !Array.isArray(value);
}

/**
 * Whether the round is live at `nowMs` (server time): counting down, running, its time up - or stopped while the
 * results are not published yet (they keep coming in).
 */
export function isLiveRound(state, nowMs) {
    const view = stopwatchView(state?.round?.stopwatch ?? null, nowMs);

    if (view.phase === 'scheduled' || view.phase === 'running' || view.phase === 'times_up') {
        return true;
    }

    return view.phase === 'stopped' && state?.round?.published !== true;
}

/**
 * The next state poll: 10 s live (30 s live with the stream live) / 60 s otherwise, doubled per failed fetch up to 5
 * minutes, spread ±20 %.
 */
export function pollDelay({ live, streamed = false, errors = 0, random = Math.random }) {
    const base = live ? (streamed ? STREAMED_LIVE_POLL_MS : LIVE_POLL_MS) : IDLE_POLL_MS;
    const backedOff = Math.min(MAX_POLL_MS, base * (2 ** Math.min(errors, 10)));

    return Math.round(backedOff * (1 - POLL_SPREAD + random() * 2 * POLL_SPREAD));
}

/**
 * The pause until the state is asked for after a reveal moment: the moment + 300 ms + 0-1 s; null without one.
 */
export function revealDelay(state, nowMs, random = Math.random) {
    const at = state?.nextRevealAtMs;

    if (typeof at !== 'number' || !Number.isFinite(at)) {
        return null;
    }

    return Math.max(0, at - nowMs) + REVEAL_GRACE_MS + random() * REVEAL_JITTER_MS;
}

function keysKey(keys) {
    return [...keys].sort().join(',');
}

export class AnalyticsStudioRefresh {
    /**
     * @param {object} options
     * @param {number|null} [options.serverNowMs]  the shell's server time (a first clock guess)
     * @param {function(): Promise<{status: 'ok'|'gone'|'error', state?: object, sentAtMs?: number, receivedAtMs?: number}>} options.fetchState
     * @param {function(string): Promise<{status: string, analytics?: object}>} options.fetchAnalytics        (visibleHash)
     * @param {function(string, string): Promise<{status: string, entrant?: object}>} options.fetchEntrant    (entry key, visibleHash)
     * @param {function(string[], string): Promise<{status: string, predictions?: object}>} options.fetchPredictions (keys, visibleHash)
     * @param {function(object): void} [options.onState]
     * @param {function(object): void} [options.onAnalytics]
     * @param {function(object): void} [options.onEntrant]
     * @param {function(object): void} [options.onPredictions]
     * @param {function(): void} [options.onGone]
     * @param {function(): number} [options.now]
     * @param {function(function(), number): *} [options.schedule]
     * @param {function(*): void} [options.cancel]
     * @param {function(): number} [options.random]
     * @param {function(object): object} [options.createEvents]   OfficialResultsEvents-like: start/update/suspend/close
     */
    constructor({
        serverNowMs = null,
        fetchState,
        fetchAnalytics,
        fetchEntrant,
        fetchPredictions,
        onState = () => {},
        onAnalytics = () => {},
        onEntrant = () => {},
        onPredictions = () => {},
        onGone = () => {},
        now = () => Date.now(),
        schedule = (task, ms) => setTimeout(task, ms),
        cancel = (timer) => clearTimeout(timer),
        random = () => Math.random(),
        createEvents = (options) => new OfficialResultsEvents(options),
    }) {
        this.fetchStateFn = fetchState;
        this.fetchAnalyticsFn = fetchAnalytics;
        this.fetchEntrantFn = fetchEntrant;
        this.fetchPredictionsFn = fetchPredictions;
        this.onStateFn = onState;
        this.onAnalyticsFn = onAnalytics;
        this.onEntrantFn = onEntrant;
        this.onPredictionsFn = onPredictions;
        this.onGoneFn = onGone;
        this.now = now;
        this.scheduleFn = schedule;
        this.cancelFn = cancel;
        this.random = random;
        this.createEvents = createEvents;

        this.clock = createServerClock({ now });

        if (typeof serverNowMs === 'number' && Number.isFinite(serverNowMs)) {
            this.clock.hint(serverNowMs - now());
        }

        this.state = null;
        this.timers = {};
        this.refreshAt = null;
        this.waiters = [];
        this.inFlight = null;
        this.fetchAgain = false;
        this.errors = 0;
        this.gone = false;
        this.events = null;
        this.closed = false;
        this.started = false;
        // The studio signal's planned fetch (server-free moment) and the last signal-driven fetch
        this.signalAt = null;
        this.signalAgain = false;
        this.lastSignalFetchAt = null;

        // What is shown (loaded) and what is on its way, per dependent answer
        this.selected = null;
        this.compare = [];
        this.analyticsHash = null;
        this.analyticsLoading = null;
        this.entrantLoaded = null;
        this.entrantLoading = null;
        this.predictionsLoaded = null;
        this.predictionsLoading = null;
    }

    start() {
        if (this.started) {
            return;
        }

        this.started = true;
        this.refresh();
    }

    close() {
        this.closed = true;

        for (const name of Object.keys(this.timers)) {
            this.clear(name);
        }

        this.events?.close();
        this.resolveWaiters('error');
    }

    serverNow() {
        return this.clock.serverNow();
    }

    activeTimers() {
        return Object.keys(this.timers).length;
    }

    /**
     * The entrant shown in the detail changed (null = none): its `entrant` answer is fetched for the current hash.
     */
    select(key) {
        this.selected = typeof key === 'string' && key !== '' ? key : null;
        this.syncEntrant();
    }

    /**
     * The compare set changed: its predictions are fetched (nothing for an empty set).
     */
    setCompare(keys) {
        this.compare = Array.isArray(keys) ? keys.filter((key) => typeof key === 'string' && key !== '').slice(0, 4) : [];
        this.syncPredictions();
    }

    /**
     * The tab was shown again: the state within 0-0.5 s.
     */
    onVisible() {
        this.requestRefresh(VISIBLE_JITTER_MS);
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

    // ---- the stream

    subscription() {
        const mercure = this.state?.mercure;
        const topics = Array.isArray(mercure?.topics) ? mercure.topics.filter((topic) => typeof topic === 'string' && topic !== '') : [];

        if (typeof mercure?.hubUrl !== 'string' || mercure.hubUrl === '' || topics.length === 0) {
            return null;
        }

        return { url: mercure.hubUrl, topics, public: true };
    }

    ensureEvents() {
        if (this.events === null) {
            this.events = this.createEvents({
                subscription: this.subscription(),
                // The stream opened again: the state, spread over a few seconds
                refresh: () => this.requestRefresh(RECONNECT_JITTER_MS),
                onMessage: (message) => this.onMessage(message),
                now: this.now,
                schedule: this.scheduleFn,
                cancel: this.cancelFn,
            });
            this.events.start();

            return;
        }

        this.events.update(this.subscription());
    }

    onMessage(message) {
        if (this.closed || this.gone || !isObject(message)) {
            return;
        }

        // The studio signal: data-free - the state says what changed
        if (message.type === SIGNAL_TYPE) {
            const current = this.state?.round;

            if (isObject(current) && typeof current.id === 'string' && typeof message.roundId === 'string'
                && current.id.toLowerCase() === message.roundId.toLowerCase()) {
                this.requestSignalRefresh();
            }

            return;
        }

        // The public stopwatch payload (RoundStopwatchState): no type; any other typed message is not ours
        if (typeof message.type === 'string' || typeof message.roundId !== 'string' || !('status' in message)) {
            return;
        }

        const round = this.state?.round;

        if (isObject(round) && typeof round.id === 'string' && round.id.toLowerCase() === message.roundId.toLowerCase()
            && isNewerState(round.stopwatch, message)) {
            this.state = { ...this.state, round: { ...round, stopwatch: message } };
            this.onStateFn(this.state);
        }

        // A start may reveal a puzzle, a stop comes with results soon - the server says what changed
        this.requestRefresh(PUSH_JITTER_MS);
    }

    // ---- state

    /**
     * The state within 0-maxJitterMs (random); an earlier request already waiting serves this one too. Resolves to the
     * fetch's kind (ok / forbidden / error) - what official_results_events.js understands.
     */
    requestRefresh(maxJitterMs) {
        if (this.closed) {
            return Promise.resolve('error');
        }

        const delay = this.random() * maxJitterMs;
        const at = this.now() + delay;

        if (this.refreshAt === null || at < this.refreshAt) {
            this.refreshAt = at;
            this.set('refresh', delay, () => {
                this.refreshAt = null;
                this.refresh();
            });
        }

        return new Promise((resolve) => this.waiters.push(resolve));
    }

    /**
     * A studio signal: the state once the server's state cache cannot hold what was before the signalled commit - after
     * SIGNAL_SETTLE_MS + 0-SIGNAL_JITTER_MS, and at least SIGNAL_MIN_GAP_MS after the last signal-driven fetch. A
     * refresh already planned late enough serves it; one planned too early for it is followed by another (5 s later).
     */
    requestSignalRefresh() {
        if (this.closed) {
            return;
        }

        const earliest = this.now() + SIGNAL_SETTLE_MS;

        if (this.refreshAt !== null && this.refreshAt >= earliest) {
            return;
        }

        if (this.signalAt !== null) {
            if (this.signalAt < earliest) {
                this.signalAgain = true;
            }

            return;
        }

        this.planSignalRefresh();
    }

    planSignalRefresh() {
        const now = this.now();
        const settle = SIGNAL_SETTLE_MS + this.random() * SIGNAL_JITTER_MS;
        const gap = this.lastSignalFetchAt === null ? 0 : this.lastSignalFetchAt + SIGNAL_MIN_GAP_MS - now;
        const delay = Math.max(settle, gap);

        this.signalAt = now + delay;
        this.set('signal', delay, () => {
            this.signalAt = null;
            this.lastSignalFetchAt = this.now();
            this.refresh();

            if (this.signalAgain) {
                this.signalAgain = false;
                this.planSignalRefresh();
            }
        });
    }

    resolveWaiters(kind) {
        this.waiters.splice(0).forEach((resolve) => resolve(kind));
    }

    /**
     * Fetches the state now - one at a time; a call while one runs fetches once more after it.
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

                if (this.fetchAgain && !this.closed && !this.gone) {
                    this.fetchAgain = false;
                    this.refresh();
                } else {
                    this.fetchAgain = false;
                    this.resolveWaiters(kind);
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
            this.gone = true;
            this.events?.suspend();
            this.clear('reveal');
            this.set('poll', GONE_POLL_MS, () => this.refresh());
            this.onGoneFn();

            return 'forbidden';
        }

        if (answer?.status !== 'ok' || !isObject(answer.state)) {
            this.errors += 1;
            this.schedulePoll();

            return 'error';
        }

        this.errors = 0;
        this.gone = false;
        this.clock.observe({ sentAtMs: answer.sentAtMs, receivedAtMs: answer.receivedAtMs, serverNowMs: answer.state.serverNowMs });

        // A push that arrived while this fetch was on the way is newer - keep it
        const incoming = answer.state;
        const current = this.state?.round?.stopwatch;

        if (isObject(incoming.round) && isObject(current) && isObject(incoming.round.stopwatch) && !isNewerState(current, incoming.round.stopwatch)) {
            this.state = { ...incoming, round: { ...incoming.round, stopwatch: current } };
        } else {
            this.state = incoming;
        }

        this.onStateFn(this.state);
        this.ensureEvents();
        this.syncAll();
        this.set('reveal', revealDelay(this.state, this.serverNow(), this.random), () => this.refresh());
        this.schedulePoll();

        return 'ok';
    }

    schedulePoll() {
        // No state yet (the first fetch failed): the short cadence - the page has nothing to show until it answers
        const live = this.state === null || isLiveRound(this.state, this.serverNow());
        // The stream live (R26): the studio signal tells of changes - the poll is only the safety net
        const streamed = this.events !== null && typeof this.events.isLive === 'function' && this.events.isLive() === true;
        this.set('poll', pollDelay({ live, streamed, errors: this.errors, random: this.random }), () => this.refresh());
    }

    // ---- the answers that follow the state

    currentHash() {
        return typeof this.state?.visibleHash === 'string' ? this.state.visibleHash : null;
    }

    syncAll() {
        this.syncAnalytics();
        this.syncEntrant();
        this.syncPredictions();
    }

    syncAnalytics() {
        const hash = this.currentHash();

        if (this.closed || hash === null || hash === this.analyticsHash || this.analyticsLoading === hash) {
            return;
        }

        this.analyticsLoading = hash;
        Promise.resolve()
            .then(() => this.fetchAnalyticsFn(hash))
            .catch(() => ({ status: 'error' }))
            .then((answer) => {
                if (this.analyticsLoading === hash) {
                    this.analyticsLoading = null;
                }

                // Failed: the next state answer asks again
                if (this.closed || answer?.status !== 'ok' || !isObject(answer.analytics)) {
                    return;
                }

                if (hash === this.currentHash()) {
                    this.analyticsHash = hash;
                    this.onAnalyticsFn(answer.analytics);
                } else {
                    this.syncAnalytics();
                }
            });
    }

    syncEntrant() {
        const hash = this.currentHash();
        const key = this.selected;

        if (this.closed || hash === null || key === null) {
            return;
        }

        const id = `${key}|${hash}`;

        if (this.entrantLoaded === id || this.entrantLoading === id) {
            return;
        }

        this.entrantLoading = id;
        Promise.resolve()
            .then(() => this.fetchEntrantFn(key, hash))
            .catch(() => ({ status: 'error' }))
            .then((answer) => {
                if (this.entrantLoading === id) {
                    this.entrantLoading = null;
                }

                if (this.closed || answer?.status !== 'ok' || !isObject(answer.entrant)) {
                    return;
                }

                // Another entrant was selected meanwhile, or a reveal changed the hash: dropped
                if (key === this.selected && hash === this.currentHash()) {
                    this.entrantLoaded = id;
                    this.onEntrantFn(answer.entrant);
                } else {
                    this.syncEntrant();
                }
            });
    }

    syncPredictions() {
        const hash = this.currentHash();
        const keys = this.compare.slice();

        if (this.closed || hash === null || keys.length === 0) {
            return;
        }

        const id = `${keysKey(keys)}|${hash}`;

        if (this.predictionsLoaded === id || this.predictionsLoading === id) {
            return;
        }

        this.predictionsLoading = id;
        Promise.resolve()
            .then(() => this.fetchPredictionsFn(keys, hash))
            .catch(() => ({ status: 'error' }))
            .then((answer) => {
                if (this.predictionsLoading === id) {
                    this.predictionsLoading = null;
                }

                if (this.closed || answer?.status !== 'ok' || !isObject(answer.predictions)) {
                    return;
                }

                if (id === `${keysKey(this.compare)}|${this.currentHash()}`) {
                    this.predictionsLoaded = id;
                    this.onPredictionsFn(answer.predictions);
                } else {
                    this.syncPredictions();
                }
            });
    }
}
