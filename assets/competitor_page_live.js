// The live part of the public competitor page (docs/features/competitions-management/competitor-pages.md "Live clock",
// toolkit B-qr-competitor-page.md §4.3, competition toolkit B5). DOM-free: the Stimulus controller
// (assets/controllers/competitor_page_controller.js) injects fetch, the clock, timers, the Mercure stream, the card
// renderer and the wake lock; tests/competitor-page-live-harness.mjs drives it with fakes.
//
// - The state (`competitor_state` JSON, CompetitorPageView) comes inline with the page, then from GET competitor_state.
//   Each round carries its stopwatch (RoundStopwatchState) - the clock shown is the projector's arithmetic
//   (round_stopwatch_clock.js stopwatchView) at the server's time, so the card follows the round stopwatch exactly.
// - Server time: createServerClock() - a hint from the page's `serverNowMs`, then every state fetch is a sample (two at
//   the start, one every 5 minutes and when the page is shown again; the shortest round trip wins).
// - Mercure: the public `/round-stopwatch/{roundId}` topics of the shown rounds within ±12 h of now (at most 12), one
//   anonymous stream through official_results_events.js (a public subscription - backoff, silence watch, catch-up).
//   A push is applied at once (the newest `asOfMs` wins) and asks for the state within 0-1 s (random - 300 phones at a
//   start must not hit the server in the same millisecond): a reveal on the stopwatch start, a result.
// - The state is also asked for once at each round's `nextRevealAtMs` (a moment, never the puzzle) and 1 s after a
//   scheduled start (both + 0-1 s spread), after the stream opens again (0-3 s spread) and when the page is shown
//   again. Visibility of a puzzle is never computed here - the server's answer decides.
// - Without a live stream: the state every 15 s while a shown round runs or counts down, else every 60 s, ±20 %,
//   doubled after each failed fetch up to 5 minutes. With a live stream only the 5-minute clock sample.
// - A round whose content changed (puzzles, results, who plays) is re-drawn by the page from its HTML
//   (`reloadContent`) - the cards' markup lives in Twig only. The clock is drawn in place on every tick.
// - Screen wake lock while a round of the page's entrant runs (or starts within 5 minutes); released otherwise.
// - tapNowMs(): the server's time now, synchronously - the moment a "Stop" tap happened (B8 proposals);
//   clockSample() what that time rests on (offset, best round trip, the sample's age) - sent along with the tap;
//   observeClock() takes a heartbeat's answer as one more sample (T2, assets/competitor_heartbeat.js).
// - fastPoll(): true while the competitor's own time proposal waits for the judge (or is still on the phone) - the
//   state is then asked for every 5 s (±20 %, visible tab only, doubled per failed fetch): there is no private
//   per-entry topic in v1 (R-review-and-plan.md §1.2). replan() plans the next poll again after that changed.

import { OfficialResultsEvents } from './official_results_events.js';
import { createServerClock, isNewerState, monotonicNow, startedAtMs, stopwatchView } from './round_stopwatch_clock.js';

export const FOLLOW_WINDOW_MS = 12 * 3600 * 1000;
export const MAX_TOPICS = 12;
export const PUSH_JITTER_MS = 1000;
export const REVEAL_JITTER_MS = 1000;
export const RECONNECT_JITTER_MS = 3000;
export const VISIBLE_JITTER_MS = 500;
// A reveal moment is asked for a little after it - the server must already see it as passed
export const REVEAL_GRACE_MS = 300;
export const AFTER_START_MS = 1000;
export const SECOND_SAMPLE_MS = 1500;
export const ACTIVE_POLL_MS = 15 * 1000;
export const IDLE_POLL_MS = 60 * 1000;
export const LIVE_POLL_MS = 5 * 60 * 1000;
export const MAX_POLL_MS = 5 * 60 * 1000;
export const POLL_SPREAD = 0.2;
export const GONE_POLL_MS = 5 * 60 * 1000;
export const WAKE_BEFORE_START_MS = 5 * 60 * 1000;
export const PROPOSAL_POLL_MS = 5 * 1000;
// setTimeout's limit is ~24.8 days; nothing here waits that long
const MAX_DELAY_MS = 24 * 3600 * 1000;
const ACTIVE_PHASES = ['scheduled', 'running'];

function isRound(round) {
    return round !== null && typeof round === 'object' && typeof round.roundId === 'string';
}

export function roundsOf(state) {
    return Array.isArray(state?.rounds) ? state.rounds.filter(isRound) : [];
}

function sameId(a, b) {
    return typeof a === 'string' && typeof b === 'string' && a.toLowerCase() === b.toLowerCase();
}

/**
 * What a round's card shows at `nowMs` (server time): stopwatchView() of its stopwatch, plus the time left of the limit
 * (null without one).
 *
 * @returns {{phase: 'idle'|'scheduled'|'running'|'times_up'|'stopped', elapsedMs: number, countdownMs: number, limitMs: number, remainingMs: null|number}}
 */
export function roundView(round, nowMs) {
    const view = stopwatchView(round?.stopwatch ?? null, nowMs);
    const remainingMs = view.limitMs > 0 && view.phase !== 'idle' && view.phase !== 'scheduled'
        ? Math.max(0, view.limitMs - view.elapsedMs)
        : null;

    return { ...view, remainingMs };
}

/**
 * The rounds followed live: started or counting down, or starting within ±12 h of now - the nearest first, at most 12.
 */
export function followedRounds(state, nowMs) {
    return roundsOf(state)
        .map((round) => {
            const start = startedAtMs(round.stopwatch) ?? (typeof round.startsAtMs === 'number' ? round.startsAtMs : null);
            const status = round.stopwatch?.status ?? null;
            const active = status === 'scheduled' || status === 'running';

            return { round, active, distance: start === null ? Infinity : Math.abs(start - nowMs) };
        })
        .filter(({ active, distance }) => active || distance <= FOLLOW_WINDOW_MS)
        .sort((a, b) => (Number(b.active) - Number(a.active)) || (a.distance - b.distance))
        .slice(0, MAX_TOPICS)
        .map(({ round }) => round);
}

export function followedTopics(state, nowMs) {
    return followedRounds(state, nowMs)
        .map((round) => round.topic)
        .filter((topic) => typeof topic === 'string' && topic !== '');
}

/**
 * Everything of a round its card shows apart from the clock - a change means the card is drawn again from the page.
 */
export function contentKey(round) {
    return JSON.stringify([
        round.name ?? null,
        round.category ?? null,
        round.startsAtMs ?? null,
        round.minutesLimit ?? null,
        round.tableNumber ?? null,
        round.current ?? null,
        round.published ?? null,
        round.masked ?? null,
        round.puzzles ?? null,
        round.puzzleFormat ?? null,
        round.entry ?? null,
    ]);
}

/**
 * The rounds whose cards must be drawn again: content changed, or rounds came or went (`all`).
 *
 * @returns {{all: boolean, roundIds: string[]}}
 */
export function changedRounds(before, after) {
    const old = roundsOf(before);
    const now = roundsOf(after);

    if (old.length !== now.length || old.some((round, index) => !sameId(round.roundId, now[index].roundId))) {
        return { all: true, roundIds: now.map((round) => round.roundId) };
    }

    return { all: false, roundIds: now.filter((round, index) => contentKey(round) !== contentKey(old[index])).map((round) => round.roundId) };
}

/**
 * The next moment a shown clock changes (a whole second of elapsed time or of a countdown, the limit), in ms from
 * `nowMs` - null when every clock stands still.
 */
export function nextChangeMs(state, nowMs) {
    let next = null;

    for (const round of roundsOf(state)) {
        const view = roundView(round, nowMs);
        let delay = null;

        if (view.phase === 'running') {
            delay = 1000 - (view.elapsedMs % 1000);

            if (view.limitMs > 0) {
                delay = Math.min(delay, view.limitMs - view.elapsedMs);
            }
        } else if (view.phase === 'scheduled') {
            delay = view.countdownMs % 1000 || 1000;
            delay = Math.min(delay, view.countdownMs || 1000);
        }

        if (delay !== null) {
            next = next === null ? delay : Math.min(next, delay);
        }
    }

    return next === null ? null : Math.max(1, next);
}

/**
 * The page's entrant plays a round that runs, or starts within WAKE_BEFORE_START_MS: the screen stays on.
 */
export function wantsWakeLock(state, nowMs) {
    return roundsOf(state).some((round) => {
        if (round.masked === true || round.entry === null || round.entry === undefined || round.entry.unpaired === true) {
            return false;
        }

        const view = roundView(round, nowMs);

        return view.phase === 'running' || (view.phase === 'scheduled' && view.countdownMs <= WAKE_BEFORE_START_MS);
    });
}

/**
 * Whether a shown round runs or counts down - the state is polled more often then while the stream is down.
 */
export function isActive(state, nowMs) {
    return roundsOf(state).some((round) => ACTIVE_PHASES.includes(roundView(round, nowMs).phase));
}

/**
 * The next state poll: 5 minutes with a live stream (a clock sample), else 15 s / 60 s, doubled per failed fetch up
 * to 5 minutes, spread ±20 %.
 */
export function pollDelay({ live, active, errors = 0, random = Math.random }) {
    const base = live ? LIVE_POLL_MS : (active ? ACTIVE_POLL_MS : IDLE_POLL_MS);
    const backedOff = Math.min(MAX_POLL_MS, base * (2 ** Math.min(errors, 10)));

    return Math.round(backedOff * (1 - POLL_SPREAD + random() * 2 * POLL_SPREAD));
}

/**
 * The poll while a proposal waits for the judge: 5 s, doubled per failed fetch up to 5 minutes, spread ±20 %.
 */
export function proposalPollDelay({ errors = 0, random = Math.random }) {
    const backedOff = Math.min(MAX_POLL_MS, PROPOSAL_POLL_MS * (2 ** Math.min(errors, 10)));

    return Math.round(backedOff * (1 - POLL_SPREAD + random() * 2 * POLL_SPREAD));
}

/**
 * The earliest reveal moment still ahead (server time) - null without one.
 */
export function nextRevealAtMs(state, nowMs) {
    const moments = roundsOf(state)
        .map((round) => round.nextRevealAtMs)
        .filter((at) => typeof at === 'number' && Number.isFinite(at) && at + REVEAL_GRACE_MS > nowMs);

    return moments.length === 0 ? null : Math.min(...moments);
}

/**
 * The earliest scheduled start still ahead (server time) - null without one.
 */
export function nextScheduledStartMs(state, nowMs) {
    const moments = roundsOf(state)
        .filter((round) => round.stopwatch?.status === 'scheduled')
        .map((round) => startedAtMs(round.stopwatch))
        .filter((at) => at !== null && at + AFTER_START_MS > nowMs);

    return moments.length === 0 ? null : Math.min(...moments);
}

export class CompetitorPageLive {
    /**
     * @param {object} options
     * @param {object} options.state                     the first state (inline in the page)
     * @param {string} [options.hubUrl]                  the public Mercure hub
     * @param {function(): Promise<{status: 'ok'|'gone'|'error', state?: object, sentAtMs?: number, receivedAtMs?: number}>} options.fetchState
     * @param {function(object, number): void} options.render                 draws the clocks (state, server now)
     * @param {function({all: boolean, roundIds: string[]}): void} [options.reloadContent] draws changed cards again
     * @param {function(boolean): void} [options.wakeLock]                     true: keep the screen on, false: release
     * @param {function(): boolean} [options.isVisible]
     * @param {function(): boolean} [options.fastPoll]   a proposal waits for the judge: poll every 5 s (B8)
     * @param {function(): number} [options.now]
     * @param {function(function(), number): *} [options.schedule]
     * @param {function(*): void} [options.cancel]
     * @param {function(): number} [options.random]
     * @param {function(object): object} [options.createEvents]   OfficialResultsEvents-like: start/update/suspend/close/isLive
     */
    constructor({
        state,
        hubUrl = '',
        fetchState,
        render,
        reloadContent = () => {},
        wakeLock = () => {},
        isVisible = () => true,
        fastPoll = () => false,
        now = monotonicNow,
        schedule = (task, ms) => setTimeout(task, ms),
        cancel = (timer) => clearTimeout(timer),
        random = () => Math.random(),
        createEvents = (options) => new OfficialResultsEvents(options),
    }) {
        this.state = state && typeof state === 'object' ? state : { rounds: [] };
        this.hubUrl = hubUrl;
        this.fetchStateFn = fetchState;
        this.renderFn = render;
        this.reloadContentFn = reloadContent;
        this.wakeLockFn = wakeLock;
        this.isVisible = isVisible;
        this.fastPollFn = fastPoll;
        this.now = now;
        this.scheduleFn = schedule;
        this.cancelFn = cancel;
        this.random = random;
        this.createEvents = createEvents;

        // A sleep or a change of the phone's clock seen: a fresh sample (D2)
        this.clock = createServerClock({ now, onJump: () => this.requestRefresh(VISIBLE_JITTER_MS) });
        this.timers = {};
        this.refreshAt = null;
        this.waiters = [];
        this.inFlight = null;
        this.fetchAgain = false;
        this.errors = 0;
        this.gone = false;
        this.events = null;
        this.topicsKey = null;
        this.wakeLocked = false;
        this.closed = false;
    }

    start() {
        if (typeof this.state.serverNowMs === 'number') {
            this.clock.hint(this.state.serverNowMs - this.now());
        }

        this.events = this.createEvents({
            subscription: this.subscription(),
            // The stream opened again (or has nothing to open with): the state, spread over a few seconds
            refresh: () => this.requestRefresh(RECONNECT_JITTER_MS),
            onMessage: (message) => this.onMessage(message),
            now: this.now,
            schedule: this.scheduleFn,
            cancel: this.cancelFn,
        });
        this.topicsKey = this.currentTopicsKey();
        this.events.start();
        this.afterState();

        // Two clock samples - the shortest round trip wins
        this.refresh();
        this.set('secondSample', SECOND_SAMPLE_MS, () => this.refresh());
        this.schedulePoll();
    }

    close() {
        this.closed = true;

        for (const name of Object.keys(this.timers)) {
            this.clear(name);
        }

        this.events?.close();
        this.resolveWaiters('error');

        if (this.wakeLocked) {
            this.wakeLocked = false;
            this.wakeLockFn(false);
        }
    }

    serverNow() {
        return this.clock.serverNow();
    }

    /**
     * The server's time now, read synchronously at a tap - B8's "Stop" stores this as the moment the competitor
     * finished, before anything asynchronous happens.
     */
    tapNowMs() {
        return this.clock.serverNow();
    }

    /**
     * What the server's time rests on - sent with a tap so the judge can see how fresh it was.
     */
    clockSample() {
        return { offsetMs: this.clock.offsetMs(), rttMs: this.clock.bestRttMs(), sampleAgeMs: this.clock.sampleAgeMs() };
    }

    /**
     * Another clock sample - a heartbeat's answer (T2): `{sentAtMs, receivedAtMs}` on this device, `serverNowMs` the
     * server's. The shortest round trip still wins.
     */
    observeClock(sample) {
        return this.clock.observe(sample);
    }

    /**
     * Plans the next poll again - after a proposal started or stopped waiting for the judge.
     */
    replan() {
        if (!this.closed && !this.gone) {
            this.schedulePoll();
        }
    }

    /**
     * A round's card now (null for a round the page does not show).
     */
    roundView(roundId, nowMs = this.serverNow()) {
        const round = roundsOf(this.state).find((candidate) => sameId(candidate.roundId, roundId));

        return round === undefined ? null : roundView(round, nowMs);
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

    // ---- live updates

    subscription() {
        const hubUrl = typeof this.state.mercure?.hubUrl === 'string' && this.state.mercure.hubUrl !== '' ? this.state.mercure.hubUrl : this.hubUrl;
        const topics = followedTopics(this.state, this.serverNow());

        if (typeof hubUrl !== 'string' || hubUrl === '' || topics.length === 0) {
            return null;
        }

        return { url: hubUrl, topics, public: true };
    }

    currentTopicsKey() {
        return followedTopics(this.state, this.serverNow()).slice().sort().join(' ');
    }

    onMessage(message) {
        if (this.closed || this.gone || message === null || typeof message !== 'object') {
            return;
        }

        // The public stopwatch payload (RoundStopwatchState): no type
        if (typeof message.roundId !== 'string' || !('status' in message)) {
            return;
        }

        const rounds = roundsOf(this.state);
        const index = rounds.findIndex((round) => sameId(round.roundId, message.roundId));

        if (index >= 0 && isNewerState(rounds[index].stopwatch, message)) {
            const next = rounds.slice();
            next[index] = { ...rounds[index], stopwatch: message };
            this.state = { ...this.state, rounds: next };
            this.afterState();
        }

        // A start may reveal a puzzle, a stop comes with results soon - the server says what changed
        this.requestRefresh(PUSH_JITTER_MS);
    }

    onVisible() {
        if (this.closed) {
            return;
        }

        // A sleep in the background is counted before the clock is drawn again (D2)
        this.clock.shown();
        this.requestRefresh(VISIBLE_JITTER_MS);
        this.updateWakeLock(true);
    }

    /**
     * The page went to the background - the phone may sleep (performance.now() then stands still on most phones).
     */
    onHidden() {
        this.clock.hidden();
    }

    // ---- state

    /**
     * The state within 0-maxJitterMs (random); an earlier request already waiting serves this one too. Resolves to
     * the fetch's kind (ok / forbidden / error) - what official_results_events.js understands.
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
            // The pages were switched off (or the tag is gone): no stream, a look every 5 minutes
            this.gone = true;
            this.events?.suspend();
            this.clear('revealAt');
            this.clear('afterStart');
            this.clear('secondSample');
            this.set('poll', GONE_POLL_MS, () => this.refresh());
            this.updateWakeLock();

            return 'forbidden';
        }

        if (answer?.status !== 'ok' || answer.state === null || typeof answer.state !== 'object') {
            this.errors += 1;
            this.schedulePoll();

            return 'error';
        }

        this.errors = 0;
        this.gone = false;
        this.clock.observe({ sentAtMs: answer.sentAtMs, receivedAtMs: answer.receivedAtMs, serverNowMs: answer.state.serverNowMs });
        this.applyState(answer.state);
        this.events?.update(this.subscription());
        this.topicsKey = this.currentTopicsKey();
        this.schedulePoll();

        return 'ok';
    }

    applyState(incoming) {
        const before = this.state;
        const pushed = new Map(roundsOf(before).map((round) => [round.roundId.toLowerCase(), round.stopwatch]));

        // A push that arrived while this fetch was on the way is newer - keep it
        const rounds = roundsOf(incoming).map((round) => {
            const current = pushed.get(round.roundId.toLowerCase());

            return current && round.stopwatch && !isNewerState(current, round.stopwatch) ? { ...round, stopwatch: current } : round;
        });

        this.state = { ...incoming, rounds };

        const changed = changedRounds(before, this.state);

        if (changed.all || changed.roundIds.length > 0) {
            this.reloadContentFn(changed);
        }

        this.afterState();
    }

    afterState() {
        if (this.closed) {
            return;
        }

        this.tick();
        this.scheduleRevealRefresh();
        this.scheduleStartRefresh();

        // A round came within reach (or left it): the stream follows other topics
        const key = this.currentTopicsKey();

        if (this.events !== null && key !== this.topicsKey) {
            this.topicsKey = key;
            this.events.update(this.subscription());
        }
    }

    /**
     * Draws the clocks and plans the next draw at the next whole second (or the limit).
     */
    tick() {
        if (this.closed) {
            return;
        }

        const now = this.serverNow();
        this.renderFn(this.state, now);
        this.updateWakeLock();
        const delay = nextChangeMs(this.state, now);

        if (delay === null) {
            this.clear('tick');
        } else {
            // A few ms after the change: the timer may fire a little early
            this.set('tick', delay + 5, () => this.tick());
        }
    }

    updateWakeLock(force = false) {
        const wanted = !this.closed && !this.gone && wantsWakeLock(this.state, this.serverNow());

        if (wanted !== this.wakeLocked || (force && wanted)) {
            this.wakeLocked = wanted;
            this.wakeLockFn(wanted);
        }
    }

    scheduleRevealRefresh() {
        const at = nextRevealAtMs(this.state, this.serverNow());

        if (at === null) {
            this.clear('revealAt');

            return;
        }

        this.set('revealAt', at + REVEAL_GRACE_MS - this.serverNow(), () => this.requestRefresh(REVEAL_JITTER_MS));
    }

    scheduleStartRefresh() {
        const at = nextScheduledStartMs(this.state, this.serverNow());

        if (at === null) {
            this.clear('afterStart');

            return;
        }

        this.set('afterStart', at + AFTER_START_MS - this.serverNow(), () => this.requestRefresh(REVEAL_JITTER_MS));
    }

    schedulePoll() {
        if (this.closed || this.gone) {
            return;
        }

        let delay = pollDelay({
            live: this.events?.isLive() ?? false,
            active: isActive(this.state, this.serverNow()) && this.isVisible(),
            errors: this.errors,
            random: this.random,
        });

        // The competitor's own proposal waits for the judge - no private topic tells the phone, so it asks (B8)
        if (this.fastPollFn() && this.isVisible()) {
            delay = Math.min(delay, proposalPollDelay({ errors: this.errors, random: this.random }));
        }

        this.set('poll', delay, () => {
            // A hidden page polls nothing - it asks once it is shown again (onVisible)
            if (this.isVisible()) {
                this.refresh();
            } else {
                this.schedulePoll();
            }
        });
    }
}
