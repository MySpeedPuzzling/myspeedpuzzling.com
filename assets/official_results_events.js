/**
 * The live updates of the official results organiser pages - live entry, results desk, seating, results overview
 * (docs/features/competitions-management/official-results.md "Live updates").
 *
 * Every page gets a subscription with its state - `mercure: {url, topics, token, expiresAt, expiresIn}`, a
 * short-lived subscriber JWT for exactly its topics, minted after the voter (OfficialResultsSubscription) - and this
 * module keeps one stream open with it: fetch() with `Authorization: Bearer <token>` (never in the URL: proxies log
 * URLs, and Mercure 1.0 drops the query parameter), the server-sent events read here. The token beats the
 * `mercureAuthorization` cookie, which every signed-in response rewrites with its own topics only - an EventSource
 * reconnecting on the cookie silently lost the round's private topic.
 *
 * - The stream ends (the hub's write timeout, ~10 min), a network error, a 5xx: opened again after 1 s, 2 s, 5 s,
 *   15 s, then every minute (never sooner than the hub's `retry:`), with `Last-Event-ID`; once it is open again the
 *   state is fetched (`refresh`) - whatever was published meanwhile.
 * - Silent for longer than SILENCE_MS (the hub sends a heartbeat every 40 s): dead - a phone woken up, a Wi-Fi
 *   switch - closed and opened again.
 * - 401 (the token ended or was refused), or less than RENEW_BEFORE_MS left on the token: the state is fetched for a
 *   fresh token (the pages fetch it every minute anyway - each answer passes its subscription to update()); an open
 *   stream is replaced by opening the new one first, so nothing is missed (copies dropped by event id).
 * - The state answers signed out / no rights: no stream (suspend()) until the next state the page gets (update()).
 *
 * Messages are handed over parsed (`onMessage`) - the pages filter them by `type` / `roundId` as before. No DOM:
 * fetch, the clock and the timers are injected - pinned by tests/official-results-events-harness.mjs.
 */

// Pauses before opening a stream again after a failure - growing, then a minute
export const RECONNECT_MS = [1000, 2000, 5000, 15000, 60000];
// A token with less than this left is replaced (the hub closes its stream ~7 s before `exp`, then answers 401)
export const RENEW_BEFORE_MS = 15 * 60 * 1000;
// A token closer to its end than this is not used for a new stream - a fresh one is fetched first
export const MIN_OPEN_MS = 60 * 1000;
// No byte for this long (heartbeats come every 40 s): the stream is dead; a stream that never answers neither
export const SILENCE_MS = 100 * 1000;
export const WATCH_EVERY_MS = 15 * 1000;
// A renewal that failed (offline, a server error) tries again after this
export const RENEW_RETRY_MS = 60 * 1000;
// A stream that stayed open this long starts the next reconnect from the shortest pause again
export const STABLE_MS = 30 * 1000;
// Event ids remembered to drop the copies two overlapping streams (or a replay after Last-Event-ID) deliver
const SEEN_IDS = 200;

/**
 * Server-sent events, as the EventSource of the HTML standard reads them: lines end with CRLF, LF or CR, `data:` lines
 * of one event are joined with LF, an empty line dispatches, `:` starts a comment, one space after the colon is
 * dropped, `retry:` takes digits only, an event without data is not dispatched. `id` is the id the event itself
 * carries ('' without one - only those are compared to drop copies); `lastEventId` keeps the last one for reconnecting.
 */
export class EventStreamParser {
    /**
     * @param {object} handlers
     * @param {function({type: string, data: string, id: string}): void} handlers.onEvent
     * @param {function(number): void} [handlers.onRetry]
     */
    constructor({ onEvent, onRetry = () => {} }) {
        this.onEvent = onEvent;
        this.onRetry = onRetry;
        this.buffer = '';
        this.data = [];
        this.type = '';
        this.lastEventId = '';
        this.eventId = '';
        this.afterCarriageReturn = false;
        this.started = false;
    }

    /**
     * @param {string} text decoded text, any piece of the stream
     */
    push(text) {
        let chunk = text;

        if (chunk === '') {
            return;
        }

        if (!this.started && chunk.length > 0) {
            this.started = true;
            chunk = chunk.replace(/^﻿/, '');
        }

        // A CR ended the last piece: an LF opening this one belongs to it
        if (this.afterCarriageReturn && chunk.startsWith('\n')) {
            chunk = chunk.slice(1);
        }

        this.afterCarriageReturn = false;
        this.buffer += chunk;

        let start = 0;

        for (let index = 0; index < this.buffer.length; index++) {
            const character = this.buffer[index];

            if (character !== '\r' && character !== '\n') {
                continue;
            }

            const line = this.buffer.slice(start, index);

            if (character === '\r') {
                if (index + 1 === this.buffer.length) {
                    this.afterCarriageReturn = true;
                } else if (this.buffer[index + 1] === '\n') {
                    index++;
                }
            }

            start = index + 1;
            this.line(line);
        }

        this.buffer = this.buffer.slice(start);
    }

    line(line) {
        if (line === '') {
            this.dispatch();

            return;
        }

        if (line.startsWith(':')) {
            return;
        }

        const colon = line.indexOf(':');
        const field = colon === -1 ? line : line.slice(0, colon);
        let value = colon === -1 ? '' : line.slice(colon + 1);

        if (value.startsWith(' ')) {
            value = value.slice(1);
        }

        if (field === 'data') {
            this.data.push(value);
        } else if (field === 'event') {
            this.type = value;
        } else if (field === 'id') {
            if (!value.includes('\0')) {
                this.lastEventId = value;
                this.eventId = value;
            }
        } else if (field === 'retry') {
            if (/^\d+$/.test(value)) {
                this.onRetry(parseInt(value, 10));
            }
        }
    }

    dispatch() {
        const type = this.type === '' ? 'message' : this.type;
        const id = this.eventId;
        this.type = '';
        this.eventId = '';

        if (this.data.length === 0) {
            return;
        }

        const data = this.data.join('\n');
        this.data = [];
        this.onEvent({ type, data, id });
    }
}

/**
 * A page's subscription as the stream uses it - null when it can not be used. The token's end counts from now with
 * `expiresIn` (a device's clock may be off; the lifetime is not).
 */
export function readSubscription(subscription, now) {
    if (subscription === null || typeof subscription !== 'object') {
        return null;
    }

    const { url, topics, token, expiresIn, expiresAt } = subscription;

    if (typeof url !== 'string' || url === '' || typeof token !== 'string' || token === '' || !Array.isArray(topics)) {
        return null;
    }

    const list = topics.filter((topic) => typeof topic === 'string' && topic !== '');

    if (list.length === 0) {
        return null;
    }

    let ends;

    if (typeof expiresIn === 'number' && Number.isFinite(expiresIn)) {
        ends = now + expiresIn * 1000;
    } else {
        const parsed = Date.parse(typeof expiresAt === 'string' ? expiresAt : '');
        // Unknown: assume little is left - it is renewed soon
        ends = Number.isNaN(parsed) ? now + RENEW_BEFORE_MS : parsed;
    }

    return { url, topics: list, token, expiresAt: ends };
}

export class OfficialResultsEvents {
    /**
     * @param {object} options
     * @param {object|null} options.subscription                 the page's `mercure` (state embedded in the page)
     * @param {function(): Promise<string>} options.refresh      fetch the state again - the page passes its `mercure` to
     *                                                           update() on success; resolves to the answer's kind
     *                                                           (official_results_api.js: ok, auth, forbidden, ...)
     * @param {function(object): void} options.onMessage         every update, parsed
     * @param {function(string, object): Promise<Response>} [options.fetch]
     * @param {function(): number} [options.now]
     * @param {function(function(), number): *} [options.schedule]
     * @param {function(*): void} [options.cancel]
     * @param {string} [options.baseUrl]                        resolves a relative hub URL
     */
    constructor({
        subscription = null,
        refresh,
        onMessage,
        fetch: fetchStream = (url, init) => globalThis.fetch(url, init),
        now = () => Date.now(),
        schedule = (task, ms) => setTimeout(task, ms),
        cancel = (timer) => clearTimeout(timer),
        baseUrl = globalThis.location?.href,
    }) {
        this.refreshState = refresh;
        this.onMessage = onMessage;
        this.fetchStream = fetchStream;
        this.now = now;
        this.schedule = schedule;
        this.cancel = cancel;
        this.baseUrl = baseUrl;

        this.subscription = readSubscription(subscription, now());
        // The stream updates come from, and a stream with a newer token opening to replace it
        this.stream = null;
        this.successor = null;
        this.retryTimer = null;
        this.renewTimer = null;
        this.watchTimer = null;
        this.refreshing = null;
        this.attempts = 0;
        this.retryMs = 0;
        this.lastEventId = null;
        this.refusedToken = null;
        this.hadStream = false;
        this.started = false;
        this.suspended = false;
        this.closed = false;
        this.seen = [];
    }

    start() {
        this.started = true;
        this.ensure();
    }

    /**
     * A state answered: its subscription (a fresher token), and the page may follow the round again.
     */
    update(subscription) {
        if (this.closed) {
            return;
        }

        const next = readSubscription(subscription, this.now());

        if (next !== null) {
            this.subscription = next;

            // No stream: the state just answered with a token - no need to sit out a pause (at most once a minute,
            // the pages' state refresh)
            if (this.stream === null && this.retryTimer !== null) {
                this.cancel(this.retryTimer);
                this.retryTimer = null;
            }
        }

        this.suspended = false;
        this.ensure();
    }

    /**
     * Signed out, or no rights to the event any more: no stream until the next update().
     */
    suspend() {
        this.suspended = true;
        this.stopEverything();
    }

    close() {
        this.closed = true;
        this.stopEverything();
    }

    /**
     * Updates flow right now - an open stream that was not silent for too long.
     */
    isLive() {
        const stream = this.stream;

        return stream !== null && stream.open && !stream.done && this.now() - stream.activeAt < SILENCE_MS;
    }

    // ---- internals

    stopEverything() {
        this.cancel(this.retryTimer);
        this.cancel(this.renewTimer);
        this.cancel(this.watchTimer);
        this.retryTimer = null;
        this.renewTimer = null;
        this.watchTimer = null;
        this.discard(this.stream);
        this.discard(this.successor);
        this.stream = null;
        this.successor = null;
    }

    usable(subscription) {
        return subscription !== null
            && subscription.token !== this.refusedToken
            && subscription.expiresAt - this.now() > MIN_OPEN_MS;
    }

    ensure() {
        if (this.closed || this.suspended || !this.started) {
            return;
        }

        if (this.stream !== null) {
            const current = this.stream.subscription;
            const fresher = this.subscription !== null
                && this.subscription.token !== current.token
                && this.subscription.token !== this.refusedToken
                && this.subscription.expiresAt > current.expiresAt;

            // The open stream's token ends soon and a newer one is here: open its stream first, then drop the old one
            if (this.stream.open && this.successor === null && fresher && current.expiresAt - this.now() <= RENEW_BEFORE_MS + 5000) {
                this.successor = this.openStream(this.subscription);
            }

            return;
        }

        // No subscription at all (an online event's seating page, or the server could not make a token): no stream -
        // the page lives on its state refreshes until one of them brings one
        if (this.retryTimer !== null || this.subscription === null) {
            return;
        }

        if (!this.usable(this.subscription)) {
            // No token to open a stream with (none, refused, ending): a fresh one comes with the state - the
            // state fetch under way brings it too
            if (this.refreshing === null) {
                this.refresh();
            }

            return;
        }

        this.stream = this.openStream(this.subscription);
        this.watch();
    }

    /**
     * The page's state again - one request at a time; signed out / no rights stop the stream.
     */
    refresh() {
        if (this.refreshing !== null) {
            return this.refreshing;
        }

        let resolve;
        this.refreshing = new Promise((done) => {
            resolve = done;
        });

        Promise.resolve()
            .then(() => this.refreshState())
            .catch(() => 'error')
            .then((kind) => {
                this.refreshing = null;

                if (kind === 'auth' || kind === 'forbidden') {
                    this.suspend();
                } else {
                    this.afterRefresh();
                }

                resolve(kind);
            });

        return this.refreshing;
    }

    /**
     * A state fetch ended without a stream open or waiting: open one with the token it brought - or, still none to
     * open it with (offline, a server error), try again after a pause.
     */
    afterRefresh() {
        if (this.closed || this.suspended || !this.started || this.stream !== null || this.retryTimer !== null) {
            return;
        }

        if (this.usable(this.subscription)) {
            this.ensure();
        } else {
            this.retryLater();
        }
    }

    retryLater() {
        if (this.retryTimer !== null || this.closed || this.suspended) {
            return;
        }

        this.attempts += 1;
        const delay = Math.max(RECONNECT_MS[Math.min(this.attempts, RECONNECT_MS.length) - 1], this.retryMs);

        this.retryTimer = this.schedule(() => {
            this.retryTimer = null;
            this.ensure();
        }, delay);
    }

    openStream(subscription) {
        const stream = {
            subscription,
            controller: typeof AbortController === 'undefined' ? null : new AbortController(),
            open: false,
            done: false,
            openedAt: null,
            activeAt: this.now(),
        };
        const url = new URL(subscription.url, this.baseUrl);
        subscription.topics.forEach((topic) => url.searchParams.append('topic', topic));

        const headers = { Accept: 'text/event-stream', Authorization: `Bearer ${subscription.token}` };

        if (this.lastEventId !== null && this.lastEventId !== '') {
            headers['Last-Event-ID'] = this.lastEventId;
        }

        this.read(stream, url.toString(), headers);

        return stream;
    }

    async read(stream, url, headers) {
        let response;

        try {
            response = await this.fetchStream(url, {
                headers,
                cache: 'no-store',
                // The token authorises, never the cookie
                credentials: 'omit',
                signal: stream.controller?.signal,
            });
        } catch (e) {
            this.ended(stream, 'error');

            return;
        }

        if (stream.done) {
            return;
        }

        if (response.status === 401 || response.status === 403) {
            this.ended(stream, 'refused');

            return;
        }

        if (!response.ok || !response.body || typeof response.body.getReader !== 'function') {
            this.ended(stream, 'error');

            return;
        }

        const reader = response.body.getReader();
        stream.reader = reader;
        this.opened(stream);

        const decoder = new TextDecoder();
        const parser = new EventStreamParser({
            onEvent: (event) => this.received(stream, event),
            onRetry: (ms) => {
                this.retryMs = ms;
            },
        });
        stream.parser = parser;

        try {
            for (;;) {
                const { value, done } = await reader.read();

                if (done || stream.done) {
                    break;
                }

                stream.activeAt = this.now();
                parser.push(typeof value === 'string' ? value : decoder.decode(value, { stream: true }));
            }

            this.ended(stream, 'end');
        } catch (e) {
            this.ended(stream, 'error');
        }
    }

    opened(stream) {
        if (stream.done) {
            return;
        }

        stream.open = true;
        stream.openedAt = this.now();
        stream.activeAt = stream.openedAt;

        if (stream === this.successor) {
            // The newer token's stream is open: the old one goes
            this.discard(this.stream);
            this.stream = stream;
            this.successor = null;
        } else if (stream === this.stream) {
            // Open again after a drop: fetch whatever was published meanwhile
            if (this.hadStream) {
                this.refresh();
            }

            this.hadStream = true;
        } else {
            this.discard(stream);

            return;
        }

        this.scheduleRenewal();
    }

    received(stream, event) {
        if (stream.done || event.type !== 'message') {
            return;
        }

        if (event.id !== '') {
            if (this.seen.includes(event.id)) {
                return;
            }

            this.seen.push(event.id);

            if (this.seen.length > SEEN_IDS) {
                this.seen.shift();
            }

            this.lastEventId = event.id;
        }

        let data;

        try {
            data = JSON.parse(event.data);
        } catch (e) {
            return;
        }

        this.onMessage(data);
    }

    ended(stream, reason) {
        if (stream.done) {
            return;
        }

        this.discard(stream);

        if (reason === 'refused') {
            this.refusedToken = stream.subscription.token;
        }

        if (stream === this.successor) {
            // The old stream goes on; the renewal tries again
            this.successor = null;

            return;
        }

        if (stream !== this.stream) {
            return;
        }

        this.stream = null;

        if (stream.open && this.now() - stream.openedAt >= STABLE_MS) {
            this.attempts = 0;
        }

        if (this.successor !== null) {
            // The stream with the newer token, still opening, takes over (and catches up once open)
            this.stream = this.successor;
            this.successor = null;

            return;
        }

        this.cancel(this.renewTimer);
        this.renewTimer = null;
        this.retryLater();
    }

    discard(stream) {
        if (stream === null || stream.done) {
            return;
        }

        stream.done = true;

        try {
            stream.controller?.abort();
            stream.reader?.cancel?.()?.catch?.(() => {});
        } catch (e) {
            // Already gone
        }
    }

    /**
     * Renews the open stream's token RENEW_BEFORE_MS before its end (half way for a short one) - unless a fresher one
     * came with a state meanwhile (then ensure() already replaced the stream).
     */
    scheduleRenewal() {
        this.cancel(this.renewTimer);
        this.renewTimer = null;

        if (this.stream === null) {
            return;
        }

        const left = this.stream.subscription.expiresAt - this.now();

        this.renewTimer = this.schedule(() => this.renewalDue(), Math.max(0, left - RENEW_BEFORE_MS, left / 2));
    }

    renewalDue() {
        this.renewTimer = null;

        if (this.closed || this.suspended || this.stream === null) {
            return;
        }

        const stream = this.stream;
        this.ensure();

        if (this.successor === null) {
            this.refresh();
        }

        // Until the stream was replaced (opened() schedules its renewal), or ended
        this.renewTimer = this.schedule(() => {
            if (this.stream === stream) {
                this.renewalDue();
            }
        }, RENEW_RETRY_MS);
    }

    /**
     * A stream silent for too long (or one that never answers) is dead - closed and opened again.
     */
    watch() {
        if (this.watchTimer !== null) {
            return;
        }

        this.watchTimer = this.schedule(() => {
            this.watchTimer = null;

            for (const stream of [this.successor, this.stream]) {
                if (stream !== null && !stream.done && this.now() - stream.activeAt > SILENCE_MS) {
                    this.ended(stream, 'silent');
                }
            }

            if (this.stream !== null || this.successor !== null) {
                this.watch();
            }
        }, WATCH_EVERY_MS);
    }
}
