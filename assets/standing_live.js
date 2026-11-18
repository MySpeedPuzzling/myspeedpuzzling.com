// The live part of the public standing page (competition toolkit v2 S4, displays-standings.md §4.2,
// docs/features/competitions-management/standings.md "Live push"). DOM-free: the Stimulus controller
// (assets/controllers/standing_live_controller.js) injects the fragment fetch, the swap, visibility, the clock and timers;
// tests/standing-live-harness.mjs drives it with fakes.
//
// - One anonymous stream through official_results_events.js (a public subscription `{url, topics, public: true}`) on the
//   event's data-free topic `/competition-standings/{competitionId}`: every `standing.signal` means "what a published
//   standing shows may have changed".
// - A signal on a visible page: the table part (`public_standing_table`) is fetched after a random 0-4 s (hundreds of open
//   pages must not ask in the same millisecond); signals while that wait runs are served by it, one fetch at a time - a
//   signal during a fetch asks once more after it.
// - A signal on a hidden page: nothing fetched - marked dirty, fetched (0-1 s) once the page is shown again.
// - No live stream (the hub refused anonymous subscribers, it is down, the stream is still opening): the table every
//   60 s (±20 %) while the page is visible; a hidden page marks itself dirty instead. With a live stream a slow safety
//   poll every 5 minutes (±20 %, visible only) - a lost signal (a hub hiccup on publish) heals by then. The stream
//   opening again after a drop asks once (0-4 s) - whatever was published meanwhile.
// - 404 (the standing was unpublished, the event is no longer public): everything stops; the page keeps what it shows.

import { OfficialResultsEvents } from './official_results_events.js';

export const SIGNAL_TYPE = 'standing.signal';
export const JITTER_MS = 4000;
export const VISIBLE_JITTER_MS = 1000;
export const POLL_MS = 60 * 1000;
export const LIVE_POLL_MS = 5 * 60 * 1000;
export const POLL_SPREAD = 0.2;

/**
 * The next poll: 60 s without a live stream, 5 minutes with one (a lost signal), spread ±20 %.
 */
export function pollDelay(random = Math.random, live = false) {
    return Math.round((live ? LIVE_POLL_MS : POLL_MS) * (1 - POLL_SPREAD + random() * 2 * POLL_SPREAD));
}

export function isSignal(message) {
    return message !== null && typeof message === 'object' && message.type === SIGNAL_TYPE;
}

export class StandingLive {
    /**
     * @param {object} options
     * @param {string} options.hubUrl                       the public Mercure hub ('' = no stream, polling only)
     * @param {string} options.topic                        `/competition-standings/{competitionId}`
     * @param {function(): Promise<{status: 'ok'|'gone'|'error', html?: string}>} options.fetchTable
     * @param {function(string): boolean} options.apply     swaps the table part in; true when it changed
     * @param {function(): void} [options.updated]          the table changed (the "Updated" line)
     * @param {function(): boolean} [options.isVisible]
     * @param {function(): number} [options.now]
     * @param {function(function(), number): *} [options.schedule]
     * @param {function(*): void} [options.cancel]
     * @param {function(): number} [options.random]
     * @param {function(object): object} [options.createEvents]   OfficialResultsEvents-like: start/close/isLive
     */
    constructor({
        hubUrl = '',
        topic = '',
        fetchTable,
        apply,
        updated = () => {},
        isVisible = () => true,
        now = () => Date.now(),
        schedule = (task, ms) => setTimeout(task, ms),
        cancel = (timer) => clearTimeout(timer),
        random = () => Math.random(),
        createEvents = (options) => new OfficialResultsEvents(options),
    }) {
        this.hubUrl = typeof hubUrl === 'string' ? hubUrl : '';
        this.topic = typeof topic === 'string' ? topic : '';
        this.fetchTableFn = fetchTable;
        this.applyFn = apply;
        this.updatedFn = updated;
        this.isVisible = isVisible;
        this.now = now;
        this.scheduleFn = schedule;
        this.cancelFn = cancel;
        this.random = random;
        this.createEvents = createEvents;

        this.events = null;
        this.waitTimer = null;
        this.pollTimer = null;
        this.inFlight = null;
        this.again = false;
        this.dirty = false;
        this.gone = false;
        this.closed = false;
        this.fetches = 0;
        // The page came with its table: as good as a fetch at the start
        this.lastFetchAt = now();
        this.liveGapMs = pollDelay(random, true);
    }

    subscription() {
        if (this.hubUrl === '' || this.topic === '') {
            return null;
        }

        return { url: this.hubUrl, topics: [this.topic], public: true };
    }

    start() {
        const subscription = this.subscription();

        if (subscription !== null) {
            this.events = this.createEvents({
                subscription,
                // The stream opened again after a drop: whatever was published meanwhile, spread over a few seconds
                refresh: () => {
                    this.request(JITTER_MS);

                    return Promise.resolve(this.gone ? 'forbidden' : 'ok');
                },
                onMessage: (message) => this.onMessage(message),
                now: this.now,
                schedule: this.scheduleFn,
                cancel: this.cancelFn,
            });
            this.events.start();
        }

        this.schedulePoll();
    }

    close() {
        this.closed = true;
        this.stop();
    }

    stop() {
        this.cancelFn(this.waitTimer);
        this.cancelFn(this.pollTimer);
        this.waitTimer = null;
        this.pollTimer = null;
        this.events?.close();
        this.events = null;
    }

    isLive() {
        return this.events?.isLive() ?? false;
    }

    isWaiting() {
        return this.waitTimer !== null;
    }

    onMessage(message) {
        if (this.closed || this.gone || !isSignal(message)) {
            return;
        }

        this.request(JITTER_MS);
    }

    onVisible() {
        if (this.closed || this.gone || !this.dirty) {
            return;
        }

        this.request(VISIBLE_JITTER_MS);
    }

    /**
     * The table within 0-maxJitterMs; a wait already running serves this request too. A hidden page only marks itself
     * dirty.
     */
    request(maxJitterMs) {
        if (this.closed || this.gone) {
            return;
        }

        if (!this.isVisible()) {
            this.dirty = true;

            return;
        }

        if (this.waitTimer !== null) {
            return;
        }

        this.waitTimer = this.scheduleFn(() => {
            this.waitTimer = null;
            this.load();
        }, Math.round(this.random() * maxJitterMs));
    }

    /**
     * Fetches the table now - one at a time; a call while one runs fetches once more after it.
     */
    load() {
        if (this.closed || this.gone) {
            return Promise.resolve('error');
        }

        if (this.inFlight !== null) {
            this.again = true;

            return this.inFlight;
        }

        this.dirty = false;
        this.fetches += 1;
        this.lastFetchAt = this.now();
        this.liveGapMs = pollDelay(this.random, true);
        this.inFlight = Promise.resolve()
            .then(() => this.fetchTableFn())
            .catch(() => ({ status: 'error' }))
            .then((answer) => {
                this.inFlight = null;
                const kind = this.received(answer);

                if (this.again && !this.closed && !this.gone) {
                    this.again = false;
                    this.load();
                } else {
                    this.again = false;
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
            this.stop();

            return 'gone';
        }

        if (answer?.status !== 'ok' || typeof answer.html !== 'string') {
            // Asked again on the next signal or poll
            return 'error';
        }

        if (this.applyFn(answer.html) === true) {
            this.updatedFn();
        }

        return 'ok';
    }

    schedulePoll() {
        if (this.closed || this.gone) {
            return;
        }

        // Ticks every 60 s (±20 %) whatever the stream does - a stream that drops is polled within a minute
        this.cancelFn(this.pollTimer);
        this.pollTimer = this.scheduleFn(() => {
            this.pollTimer = null;

            // Live: a safety fetch only once the last one is 5 minutes (±20 %) old - a lost signal heals by then
            if (!this.isLive() || this.now() - this.lastFetchAt >= this.liveGapMs) {
                this.request(0);
            }

            this.schedulePoll();
        }, pollDelay(this.random));
    }
}
