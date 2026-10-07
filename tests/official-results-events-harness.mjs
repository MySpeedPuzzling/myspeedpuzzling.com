// Runs assets/official_results_events.js - the live updates stream of the official results organiser pages - through
// its scenarios against a fake hub (fetch), a fake clock and fake timers, and prints {scenario: "ok" | error} as JSON.
// tests/OfficialResultsEventsScriptsTest.php asserts every one is "ok".

import assert from 'node:assert/strict';
import {
    EventStreamParser,
    MIN_OPEN_MS,
    OfficialResultsEvents,
    readSubscription,
    RECONNECT_MS,
    RENEW_BEFORE_MS,
    SILENCE_MS,
    STABLE_MS,
    WATCH_EVERY_MS,
} from '../assets/official_results_events.js';

const HUB = 'https://myspeedpuzzling.test/.well-known/mercure';
const ROUND_TOPIC = '/round-results/018d0020-0000-0000-0000-000000000101';
const STOPWATCH_TOPIC = '/round-stopwatch/018d0020-0000-0000-0000-000000000101';

function subscription(token, expiresIn = 3600) {
    return { url: HUB, topics: [ROUND_TOPIC, STOPWATCH_TOPIC], token, expiresAt: '2026-10-07T12:00:00+00:00', expiresIn };
}

async function settle() {
    for (let i = 0; i < 10; i++) {
        await new Promise((resolve) => setImmediate(resolve));
    }
}

/**
 * A fake hub behind fetch(): every request waits until the test answers it (`answer(status)` / `open()`), an open
 * stream gets text through `send()` and ends through `end()` / `fail()`; aborting (the module closing it) rejects
 * whatever waits, like a browser.
 */
function fakeHub() {
    const requests = [];

    const fetch = (url, init) => {
        let settleResponse;
        const response = new Promise((resolve, reject) => {
            settleResponse = { resolve, reject };
        });
        const pendingReads = [];
        const queue = [];
        const request = {
            url: new URL(url),
            init,
            aborted: false,
            ended: false,
            answer(status) {
                settleResponse.resolve({ status, ok: status >= 200 && status < 300, body: null });
            },
            open() {
                request.isOpen = true;
                settleResponse.resolve({
                    status: 200,
                    ok: true,
                    body: {
                        getReader: () => ({
                            read: () => {
                                if (queue.length > 0) {
                                    return Promise.resolve(queue.shift());
                                }

                                if (request.ended || request.aborted) {
                                    return Promise.resolve({ done: true, value: undefined });
                                }

                                return new Promise((resolve, reject) => pendingReads.push({ resolve, reject }));
                            },
                            cancel: () => {
                                request.aborted = true;
                                pendingReads.splice(0).forEach((read) => read.resolve({ done: true, value: undefined }));

                                return Promise.resolve();
                            },
                        }),
                    },
                });
            },
            send(text) {
                const chunk = { done: false, value: new TextEncoder().encode(text) };
                const read = pendingReads.shift();

                if (read) {
                    read.resolve(chunk);
                } else {
                    queue.push(chunk);
                }
            },
            end() {
                request.ended = true;
                pendingReads.splice(0).forEach((read) => read.resolve({ done: true, value: undefined }));
            },
            fail() {
                request.ended = true;
                pendingReads.splice(0).forEach((read) => read.reject(new TypeError('network error')));
            },
        };

        init.signal?.addEventListener('abort', () => {
            request.aborted = true;
            settleResponse.reject(new DOMException('aborted', 'AbortError'));
            pendingReads.splice(0).forEach((read) => read.reject(new DOMException('aborted', 'AbortError')));
        });
        requests.push(request);

        return response;
    };

    return { requests, fetch, last: () => requests[requests.length - 1] };
}

/**
 * The module with a fake hub, clock and timers; `page.refresh` answers like a page's state fetch: it passes the next
 * subscription (`page.next`) to update() and resolves to `page.kind`.
 */
function setup({ initial = subscription('token-1') } = {}) {
    const clock = { now: 1_800_000_000_000 };
    const timers = [];
    const hub = fakeHub();
    const messages = [];
    const page = { refreshes: 0, kind: 'ok', next: null, tokens: 1 };
    let events;

    page.refresh = async () => {
        page.refreshes += 1;
        await Promise.resolve();

        if (page.kind === 'ok') {
            page.tokens += 1;
            events.update(page.next === undefined ? null : (page.next ?? subscription(`token-${page.tokens}`)));
        }

        return page.kind;
    };

    events = new OfficialResultsEvents({
        subscription: initial,
        refresh: () => page.refresh(),
        onMessage: (data) => messages.push(data),
        fetch: hub.fetch,
        now: () => clock.now,
        schedule: (task, ms) => {
            const timer = { task, at: clock.now + ms, ms };
            timers.push(timer);

            return timer;
        },
        cancel: (timer) => {
            const index = timers.indexOf(timer);

            if (index >= 0) {
                timers.splice(index, 1);
            }
        },
        baseUrl: 'https://myspeedpuzzling.test/en/manage-round-results/x',
    });

    /**
     * Moves the clock, running every timer that comes due on the way (in order).
     */
    async function advance(ms) {
        const until = clock.now + ms;

        for (;;) {
            timers.sort((a, b) => a.at - b.at);
            const next = timers[0];

            if (next === undefined || next.at > until) {
                break;
            }

            timers.shift();
            clock.now = Math.max(clock.now, next.at);
            next.task();
            await settle();
        }

        clock.now = until;
        await settle();
    }

    /**
     * Moves the clock like advance(), the hub sending its heartbeat on every open stream every 40 s on the way.
     */
    async function advanceWithHeartbeats(ms) {
        const until = clock.now + ms;

        while (clock.now < until) {
            hub.requests.filter((request) => request.isOpen && !request.aborted && !request.ended).forEach((request) => request.send(':\n'));
            await settle();
            await advance(Math.min(40_000, until - clock.now));
        }
    }

    return { events, hub, clock, timers, messages, page, advance, advanceWithHeartbeats };
}

function frame(id, data) {
    return `id: ${id}\ndata: ${JSON.stringify(data)}\n\n`;
}

const scenarios = {
    async 'the parser reads frames however the stream is cut'() {
        const text = ': hello\r\n\r\nid: urn:1\r\ndata: {"a":\r\ndata: 1}\r\n\r\nevent: ping\ndata: x\n\nid: urn:2\rdata:{"b":2}\r\rdata\n\n';
        const expected = [
            { type: 'message', data: '{"a":\n1}', id: 'urn:1' },
            { type: 'ping', data: 'x', id: '' },
            { type: 'message', data: '{"b":2}', id: 'urn:2' },
            { type: 'message', data: '', id: '' },
        ];

        // Whole, one character at a time, and in every two-piece cut (a CR LF split between pieces included)
        const cuts = [[text], text.split('')];
        for (let at = 1; at < text.length; at++) {
            cuts.push([text.slice(0, at), text.slice(at)]);
        }

        for (const pieces of cuts) {
            const events = [];
            const parser = new EventStreamParser({ onEvent: (event) => events.push(event) });
            // An empty piece (a multi-byte character cut by the network decodes to nothing) changes nothing
            pieces.forEach((piece) => { parser.push(piece); parser.push(''); });
            assert.deepEqual(events, expected, JSON.stringify(pieces));
            assert.equal(parser.lastEventId, 'urn:2', 'kept for reconnecting');
        }
    },

    async 'the parser takes retry in digits only, drops one space after the colon, never dispatches an event without data'() {
        const events = [];
        const retries = [];
        const parser = new EventStreamParser({ onEvent: (event) => events.push(event), onRetry: (ms) => retries.push(ms) });

        parser.push('﻿retry: 5000\nretry: 5s\n\nid: 7\nevent: nothing\n\ndata:  two spaces\n\n');

        assert.deepEqual(retries, [5000]);
        assert.deepEqual(events, [{ type: 'message', data: ' two spaces', id: '' }]);
        assert.equal(parser.lastEventId, '7');
    },

    async 'a subscription is usable only with a url, a token and topics; its end counts from when it came'() {
        assert.equal(readSubscription(null, 0), null);
        assert.equal(readSubscription({ url: HUB, topics: [], token: 't', expiresIn: 3600 }, 0), null);
        assert.equal(readSubscription({ url: HUB, topics: [ROUND_TOPIC], token: '', expiresIn: 3600 }, 0), null);
        assert.equal(readSubscription({}, 0), null);
        assert.deepEqual(readSubscription(subscription('t'), 1000), { url: HUB, topics: [ROUND_TOPIC, STOPWATCH_TOPIC], token: 't', expiresAt: 1000 + 3600 * 1000 });
        // A device whose clock is a day off still renews on time: expiresIn wins over expiresAt
        assert.equal(readSubscription(subscription('t'), Date.parse('2026-10-08T12:00:00+00:00')).expiresAt, Date.parse('2026-10-08T13:00:00+00:00'));
    },

    async 'the token goes in a header, never in the URL; the cookie is not sent; updates are handed over parsed'() {
        const { events, hub, messages } = setup();
        events.start();
        await settle();

        assert.equal(hub.requests.length, 1);
        const request = hub.last();
        assert.deepEqual(request.url.searchParams.getAll('topic'), [ROUND_TOPIC, STOPWATCH_TOPIC]);
        assert.equal(request.url.toString().toLowerCase().includes('token'), false);
        assert.equal(request.url.searchParams.has('authorization'), false);
        assert.equal(request.init.headers.Authorization, 'Bearer token-1');
        assert.equal(request.init.headers.Accept, 'text/event-stream');
        assert.equal(request.init.headers['Last-Event-ID'], undefined);
        assert.equal(request.init.credentials, 'omit');
        assert.equal(request.init.cache, 'no-store');

        assert.equal(events.isLive(), false);
        request.open();
        await settle();
        assert.equal(events.isLive(), true);

        request.send(':\n\n' + frame('urn:a', { type: 'official_results.refresh', roundId: 'r' }) + 'data: not json\n\n');
        await settle();
        assert.deepEqual(messages, [{ type: 'official_results.refresh', roundId: 'r' }]);
    },

    async 'a stream the hub ends opens again after a second, with Last-Event-ID, and catches up once open'() {
        const { events, hub, page, advance, clock } = setup();
        events.start();
        await settle();
        hub.last().open();
        await settle();
        hub.last().send(frame('urn:1', { type: 'official_results.round', roundId: 'r' }));
        await settle();
        assert.equal(page.refreshes, 0, 'the page came with its state');

        // The hub's write timeout, ten minutes later
        clock.now += 600_000;
        hub.last().end();
        await settle();
        assert.equal(events.isLive(), false);
        assert.equal(hub.requests.length, 1);

        await advance(RECONNECT_MS[0]);
        assert.equal(hub.requests.length, 2);
        assert.equal(hub.last().init.headers['Last-Event-ID'], 'urn:1');
        assert.equal(hub.last().init.headers.Authorization, 'Bearer token-1');
        assert.equal(page.refreshes, 0, 'not before the stream is open - updates in between would be lost');

        hub.last().open();
        await settle();
        assert.equal(page.refreshes, 1, 'whatever was published meanwhile is fetched');
        assert.equal(events.isLive(), true);
        assert.equal(hub.requests.length, 2, 'the fresh token of the catch-up does not replace a token with 50 minutes left');
    },

    async 'network errors and server errors back off 1 s, 2 s, 5 s, 15 s, then every minute; a stream that stayed open starts over'() {
        const { events, hub, advance, clock } = setup();
        events.start();
        await settle();

        hub.last().answer(502);
        await settle();

        for (let attempt = 1; attempt <= 7; attempt++) {
            const pause = RECONNECT_MS[Math.min(attempt, RECONNECT_MS.length) - 1];
            const before = hub.requests.length;

            await advance(pause - 1);
            assert.equal(hub.requests.length, before, `not before ${pause} ms`);
            await advance(1);
            assert.equal(hub.requests.length, before + 1, `after ${pause} ms`);

            if (attempt % 2 === 0) {
                hub.last().answer(503);
            } else {
                // The connection itself fails, or the stream breaks right after opening
                hub.last().open();
                await settle();
                hub.last().fail();
            }

            await settle();
        }

        // Open long enough: the next drop waits the shortest pause again
        await advance(60_000);
        hub.last().open();
        await settle();
        clock.now += STABLE_MS;
        hub.last().fail();
        await settle();
        const before = hub.requests.length;
        await advance(RECONNECT_MS[0]);
        assert.equal(hub.requests.length, before + 1);
    },

    async 'the hub retry field is the shortest pause'() {
        const { events, hub, advance, clock } = setup();
        events.start();
        await settle();
        hub.last().open();
        await settle();
        hub.last().send('retry: 7000\n\n');
        await settle();
        clock.now += STABLE_MS;
        hub.last().end();
        await settle();

        await advance(6999);
        assert.equal(hub.requests.length, 1);
        await advance(1);
        assert.equal(hub.requests.length, 2);
    },

    async '401: the state brings a fresh token and the stream opens with it; the refused token is never used again'() {
        const { events, hub, page, advance } = setup();
        events.start();
        await settle();

        hub.last().answer(401);
        await settle();
        assert.equal(page.refreshes, 0);

        await advance(RECONNECT_MS[0]);
        assert.equal(page.refreshes, 1, 'a fresh token is fetched with the state');
        assert.equal(hub.requests.length, 2);
        assert.equal(hub.last().init.headers.Authorization, 'Bearer token-2');

        // The state brings no token (the server could not make one): back off, never the refused one again
        hub.last().answer(401);
        page.next = undefined;
        await settle();
        await advance(RECONNECT_MS[1]);
        assert.equal(page.refreshes, 2);
        assert.equal(hub.requests.length, 2);
        await advance(RECONNECT_MS[2]);
        assert.equal(page.refreshes, 3);
        assert.equal(hub.requests.length, 2);

        page.next = null;
        await advance(RECONNECT_MS[3]);
        assert.equal(hub.requests.length, 3);
        assert.equal(hub.last().init.headers.Authorization, 'Bearer token-5');
    },

    async 'states fetched every minute renew nothing until the token is in its last 15 minutes, then the new stream opens before the old one closes'() {
        const { events, hub, messages, page, clock } = setup();
        events.start();
        await settle();
        const first = hub.last();
        first.open();
        await settle();

        // The page's minute refreshes: fresh tokens, the stream stays
        for (let minute = 1; minute <= 44; minute++) {
            clock.now += 60_000;
            await page.refresh();
            await settle();
        }

        assert.equal(hub.requests.length, 1);

        clock.now += 60_000;
        await page.refresh();
        await settle();
        assert.equal(hub.requests.length, 2, 'renewed with 15 minutes left');
        const second = hub.last();
        assert.notEqual(second.init.headers.Authorization, first.init.headers.Authorization);
        assert.equal(first.aborted, false, 'the old stream stays until the new one is open');

        first.send(frame('urn:x', { n: 1 }));
        await settle();
        second.open();
        await settle();
        assert.equal(first.aborted, true);

        // The same update on the new stream (the hub replays, or both streams had it): handed over once
        second.send(frame('urn:x', { n: 1 }) + frame('urn:y', { n: 2 }));
        await settle();
        assert.deepEqual(messages, [{ n: 1 }, { n: 2 }]);
        assert.equal(events.isLive(), true);
    },

    async 'a renewal stream the hub refuses leaves the old stream on; the next fresh token tries again'() {
        const { events, hub, page, clock } = setup();
        events.start();
        await settle();
        const first = hub.last();
        first.open();
        await settle();

        clock.now += 3600_000 - RENEW_BEFORE_MS;
        await page.refresh();
        await settle();
        const refused = hub.last();
        assert.equal(hub.requests.length, 2);
        refused.answer(401);
        first.send(':\n');
        await settle();
        assert.equal(first.aborted, false);
        assert.equal(events.isLive(), true);

        // The page's next minute: another fresh token, another try - never the refused one again
        clock.now += 60_000;
        first.send(':\n');
        await page.refresh();
        await settle();
        assert.equal(hub.requests.length, 3);
        assert.notEqual(hub.last().init.headers.Authorization, refused.init.headers.Authorization);
        hub.last().open();
        await settle();
        assert.equal(first.aborted, true);
    },

    async 'the old stream ending while its successor opens: the successor takes over and catches up'() {
        const { events, hub, page, clock } = setup();
        events.start();
        await settle();
        const first = hub.last();
        first.open();
        await settle();

        clock.now += 3600_000 - RENEW_BEFORE_MS;
        await page.refresh();
        await settle();
        const successor = hub.last();
        const refreshesBefore = page.refreshes;

        first.end();
        await settle();
        assert.equal(hub.requests.length, 2, 'no third stream - the successor is on its way');
        successor.open();
        await settle();
        assert.equal(page.refreshes, refreshesBefore + 1, 'caught up - the old one ended before the new one was open');
        assert.equal(events.isLive(), true);
    },

    async 'a page in the background renews its token by itself before it ends'() {
        const { events, hub, page, advance, advanceWithHeartbeats } = setup();
        events.start();
        await settle();
        hub.last().open();
        await settle();

        await advanceWithHeartbeats(3600_000 - RENEW_BEFORE_MS - 1);
        assert.equal(page.refreshes, 0);
        assert.equal(hub.requests.length, 1);
        await advance(1);
        assert.equal(page.refreshes, 1);
        assert.equal(hub.requests.length, 2);
        hub.last().open();
        await settle();
        assert.equal(hub.requests[0].aborted, true);
    },

    async 'the renewal tries again every minute while the state does not answer'() {
        const { events, hub, page, advanceWithHeartbeats } = setup();
        events.start();
        await settle();
        hub.last().open();
        await settle();

        page.kind = 'offline';
        await advanceWithHeartbeats(3600_000 - RENEW_BEFORE_MS);
        assert.equal(page.refreshes, 1);
        assert.equal(hub.requests.length, 1);

        await advanceWithHeartbeats(60_000);
        assert.equal(page.refreshes, 2);
        page.kind = 'ok';
        await advanceWithHeartbeats(60_000);
        assert.equal(page.refreshes, 3);
        assert.equal(hub.requests.length, 2);
    },

    async 'signed out or no rights: no stream until a state answers again'() {
        for (const kind of ['auth', 'forbidden']) {
            const { events, hub, page, advance, timers } = setup();
            events.start();
            await settle();
            hub.last().answer(401);
            await settle();

            page.kind = kind;
            await advance(RECONNECT_MS[0]);
            assert.equal(page.refreshes, 1);
            assert.equal(hub.requests.length, 1);
            assert.equal(timers.length, 0, 'nothing waits to reconnect');

            await advance(3600_000);
            assert.equal(hub.requests.length, 1);

            // Signed in again (the banner's Retry): the page's state answers
            page.kind = 'ok';
            await page.refresh();
            await settle();
            assert.equal(hub.requests.length, 2);
        }
    },

    async 'the page suspends an open stream when its own state fetch says signed out'() {
        const { events, hub } = setup();
        events.start();
        await settle();
        hub.last().open();
        await settle();

        events.suspend();
        assert.equal(hub.last().aborted, true);
        assert.equal(events.isLive(), false);

        events.update(subscription('token-9'));
        await settle();
        assert.equal(hub.requests.length, 2);
        assert.equal(hub.last().init.headers.Authorization, 'Bearer token-9');
    },

    async 'a stream silent for longer than the heartbeat allows is dead: closed, opened again, caught up'() {
        const { events, hub, page, advance } = setup();
        events.start();
        await settle();
        hub.last().open();
        await settle();

        await advance(SILENCE_MS - 5000);
        hub.last().send(':\n');
        await settle();
        await advance(SILENCE_MS - 5000);
        assert.equal(hub.requests.length, 1, 'a heartbeat keeps it');
        assert.equal(events.isLive(), true);

        await advance(5000 + WATCH_EVERY_MS + 1);
        assert.equal(hub.requests[0].aborted, true);
        await advance(RECONNECT_MS[0]);
        assert.equal(hub.requests.length, 2);
        hub.last().open();
        await settle();
        assert.equal(page.refreshes, 1);
    },

    async 'a hub that never answers is given up and asked again'() {
        const { events, hub, advance } = setup();
        events.start();
        await settle();

        await advance(SILENCE_MS + WATCH_EVERY_MS);
        assert.equal(hub.requests[0].aborted, true);
        await advance(RECONNECT_MS[0]);
        assert.equal(hub.requests.length, 2);
    },

    async 'a token about to end is not used to open a stream'() {
        const { events, hub, page } = setup({ initial: subscription('token-1', (MIN_OPEN_MS - 1000) / 1000) });
        events.start();
        await settle();

        assert.equal(page.refreshes, 1);
        assert.equal(hub.requests.length, 1);
        assert.equal(hub.last().init.headers.Authorization, 'Bearer token-2');
    },

    async 'no subscription (none could be made): no stream, nothing scheduled, until a state brings one'() {
        const { events, hub, timers, page, advance } = setup({ initial: null });
        page.next = undefined;
        events.start();
        await advance(3600_000);
        // No state fetches of its own - the page lives on its own state refreshes meanwhile
        assert.equal(page.refreshes, 0);
        assert.equal(hub.requests.length, 0);
        assert.equal(timers.length, 0);

        events.update(subscription('token-7'));
        await settle();
        assert.equal(hub.requests.length, 1);
        assert.ok(timers.every((timer) => timer.ms === WATCH_EVERY_MS || timer.ms >= RECONNECT_MS[0]));
    },

    async 'closed (the page left): everything stops and nothing reopens'() {
        const { events, hub, timers, advance } = setup();
        events.start();
        await settle();
        hub.last().open();
        await settle();

        events.close();
        assert.equal(hub.last().aborted, true);
        assert.equal(timers.length, 0);

        events.update(subscription('token-2'));
        await advance(3600_000);
        assert.equal(hub.requests.length, 1);
    },

    async 'not started (a page that redirects at once): no stream'() {
        const { hub } = setup();
        await settle();
        assert.equal(hub.requests.length, 0);
    },
};

const results = {};

for (const [name, run] of Object.entries(scenarios)) {
    try {
        await run();
        results[name] = 'ok';
    } catch (error) {
        results[name] = String(error?.stack ?? error);
    }
}

process.stdout.write(JSON.stringify(results));
