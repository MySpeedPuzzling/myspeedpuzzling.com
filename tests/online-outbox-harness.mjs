// Runs the participant page's outboxes - assets/online/event_outbox.js (events) and assets/online/media_outbox.js
// (photos and bursts straight to object storage) - through their scenarios on in-memory stores, a fake clock and
// timers, a fake API and a fake object store, and prints {scenario: "ok" | error} as JSON.
// tests/OnlineOutboxScriptTest.php asserts every one is "ok".

import assert from 'node:assert/strict';
import {
    BATCH_SIZE,
    createBeforeUnloadHandler,
    createEventsStorage,
    createMemoryStorage,
    createTabLock,
    EVENTS_DATABASE,
    EventOutbox,
    LOCK_RETRY_MS,
    OFFLINE_BACKOFF_MS,
    uuidv7,
} from '../assets/online/event_outbox.js';
import {
    BURST_CONTENT_TYPE,
    createMediaMemoryStorage,
    createMediaStorage,
    MAX_AGE_MS,
    MAX_PRESIGNS_IN_A_ROW,
    MEDIA_DATABASE,
    MediaOutbox,
    openMediaOutbox,
    putToStore,
    REFUSED_LISTED,
    UPLOAD_TIMEOUT_FLOOR_MS,
    uploadTimeoutMs,
} from '../assets/online/media_outbox.js';

const ROUND = '018d0020-0000-0000-0000-000000000101';
const START = 1_800_000_000_000;

async function settle() {
    for (let i = 0; i < 30; i++) {
        await new Promise((resolve) => setImmediate(resolve));
    }
}

/**
 * A fake clock with timers: advance() moves it, running every timer that comes due on the way, in order.
 */
function world() {
    const clock = { now: START };
    const timers = [];

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

    return {
        clock,
        timers,
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
        advance,
    };
}

/**
 * Web Locks shared by the tabs of one fake browser.
 */
function fakeLocks() {
    const held = new Set();

    return {
        request: async (name, options, task) => {
            if (held.has(name)) {
                return task(null);
            }

            held.add(name);

            try {
                return await task({ name });
            } finally {
                held.delete(name);
            }
        },
    };
}

// ---- events

let eventCounter = 0;

function event(type = 'finish.button', extra = {}) {
    eventCounter += 1;

    return {
        id: `0199e0a0-0000-7000-8000-${String(eventCounter).padStart(12, '0')}`,
        type,
        roundId: ROUND,
        clientMs: 1_792_002_832_345 + eventCounter,
        perfMs: 2_832_345.5,
        offsetMs: -1234.5,
        rttMs: 38,
        data: {},
        ...extra,
    };
}

/**
 * The events endpoint: answers `answers` first (one per request; 'decide' = the normal answer), then acknowledges or
 * refuses each event by `decide(event)` ('ack' | {refused: reason} | 'silent'). A function answer is called with the
 * events.
 */
function eventsServer(w) {
    const server = {
        requests: [],
        times: [],
        answers: [],
        log: null,
        decide: () => 'ack',
        async send(events) {
            server.log?.push('send');
            server.requests.push(JSON.parse(JSON.stringify(events)));
            server.times.push(w.clock.now);

            if (server.answers.length > 0) {
                const answer = server.answers.shift();

                if (answer !== 'decide') {
                    return typeof answer === 'function' ? answer(events) : answer;
                }
            }

            const acks = [];
            const refused = [];

            for (const sent of events) {
                const verdict = server.decide(sent);

                if (verdict === 'ack') {
                    acks.push(sent.id);
                } else if (verdict !== 'silent') {
                    refused.push({ id: sent.id, reason: verdict.refused });
                }
            }

            return { kind: 'ok', status: 200, data: { serverNowMs: w.clock.now, acks, refused } };
        },
    };

    return server;
}

function eventsSetup({ storage = createMemoryStorage(), scope = 'participant-1', lock = null, autoFlush = false, batchSize = undefined, w = world() } = {}) {
    const server = eventsServer(w);
    const counter = { changes: 0 };
    const outbox = new EventOutbox({
        storage,
        send: (events) => server.send(events),
        scope,
        now: w.now,
        lock,
        schedule: w.schedule,
        cancel: w.cancel,
        onChange: () => {
            counter.changes += 1;
        },
        autoFlush,
        batchSize,
    });

    return { outbox, server, w, storage, counter };
}

// ---- media

function photo(name, size = 100, type = 'image/jpeg') {
    return new Blob([name.padEnd(size, '.')], { type });
}

/**
 * The media endpoint (presign), the object store (PUT) and the page's report, with what each was asked.
 */
function mediaWorld(w = world()) {
    const api = { presigns: [], presignAnswers: [], reports: [], reportFails: 0, nextMediaId: 1, lifetimeMs: 5 * 60_000, serverAheadMs: 0 };
    const store = { puts: [], answers: [], objects: new Map() };

    const presign = async (input) => {
        api.presigns.push(JSON.parse(JSON.stringify(input)));

        if (api.presignAnswers.length > 0) {
            const answer = api.presignAnswers.shift();

            if (answer !== 'default') {
                return answer;
            }
        }

        const mediaId = `m${api.nextMediaId++}`;
        const serverNowMs = w.clock.now + api.serverAheadMs;

        return {
            kind: 'ok',
            status: 200,
            data: {
                serverNowMs,
                mediaId,
                store: 'primary',
                method: 'PUT',
                url: `https://objects.test/online/${mediaId}`,
                headers: { 'Content-Type': input.contentType, 'Content-Length': String(input.bytes) },
                expiresAtMs: serverNowMs + api.lifetimeMs,
            },
        };
    };

    const put = async (url, headers, blob, { method } = {}) => {
        store.puts.push({ url, headers, blob, method, at: w.clock.now });

        if (store.answers.length > 0) {
            const answer = store.answers.shift();

            if (answer === 'offline') {
                throw new TypeError('Failed to fetch');
            }

            if (answer !== 'default') {
                return answer;
            }
        }

        store.objects.set(url, await blob.text());

        return { status: 200 };
    };

    const reportUploaded = async (report) => {
        if (api.reportFails > 0) {
            api.reportFails -= 1;
            throw new Error('the event could not be stored');
        }

        api.reports.push(JSON.parse(JSON.stringify(report)));
    };

    return { w, api, store, presign, put, reportUploaded };
}

function mediaSetup({ storage = createMediaMemoryStorage(), estimate = null, maxBytes = undefined, autoFlush = false, lock = null, scope = 'participant-1', w = world(), quotaShare = undefined, isOnline = undefined } = {}) {
    const media = mediaWorld(w);
    let ids = 0;
    const outbox = new MediaOutbox({
        storage,
        presign: media.presign,
        put: media.put,
        reportUploaded: media.reportUploaded,
        scope,
        now: w.now,
        lock,
        schedule: w.schedule,
        cancel: w.cancel,
        estimate,
        maxBytes,
        quotaShare,
        autoFlush,
        isOnline,
        newId: () => `item-${scope}-${++ids}`,
    });

    return { ...media, outbox, storage };
}

function presignedIds(api) {
    return api.presigns.map((input) => `${input.kind}@${input.takenMs}`);
}

const scenarios = {
    // ---- the event outbox

    async 'events: stored before the network sees them'() {
        const log = [];
        const memory = createMemoryStorage();
        const storage = {
            ...memory,
            put: async (item) => {
                log.push('put');
                await memory.put(item);
            },
        };
        const { outbox, server } = eventsSetup({ storage, autoFlush: true });
        server.log = log;

        const stored = await outbox.enqueue(event());
        await outbox.flush();

        assert.deepEqual(stored.persisted, true);
        assert.deepEqual(log.slice(0, 2), ['put', 'send']);
        assert.equal(outbox.hasUnsent(), false);
    },

    async 'events: batches of 50 in the order stored; acknowledged ones leave the device'() {
        const { outbox, server, storage } = eventsSetup();
        const ids = [];

        for (let index = 0; index < 120; index++) {
            const sent = event();
            ids.push(sent.id);
            await outbox.enqueue(sent);
        }

        assert.equal(outbox.pendingCount(), 120);
        assert.equal(server.requests.length, 0, 'nothing sent without autoFlush');
        await outbox.flush();

        assert.deepEqual(server.requests.map((request) => request.length), [BATCH_SIZE, BATCH_SIZE, 20]);
        assert.deepEqual(server.requests.flat().map((sent) => sent.id), ids);
        assert.deepEqual(server.requests[0][0], { ...server.requests[0][0], type: 'finish.button', roundId: ROUND, offsetMs: -1234.5 }, 'sent as enqueued');
        assert.equal(outbox.pendingCount(), 0);
        assert.equal((await storage.getAll()).length, 0);
    },

    async 'events: refused ones are kept aside with the reason; one the answer says nothing of goes again, alone'() {
        const { outbox, server, storage, w } = eventsSetup();
        const ok = event();
        const unknown = event('finish.dance');
        const silent = event();
        server.decide = (sent) => (sent.id === unknown.id ? { refused: 'unknown_type' } : (sent.id === silent.id ? 'silent' : 'ack'));

        for (const sent of [ok, unknown, silent]) {
            await outbox.enqueue(sent);
        }

        await outbox.flush();

        assert.deepEqual(outbox.refusedEvents().map((refused) => [refused.id, refused.reason, refused.event.type]), [[unknown.id, 'unknown_type', 'finish.dance']]);
        assert.equal(outbox.pendingCount(), 1);
        assert.equal(outbox.status().refused, 1);
        assert.deepEqual((await storage.getAll()).map((item) => [item.id, item.state]).sort(), [[silent.id, 'pending'], [unknown.id, 'refused']].sort());

        // Not before its pause; then alone
        server.decide = () => 'ack';
        await outbox.flush();
        assert.equal(server.requests.length, 1);
        w.clock.now += 5000;
        await outbox.flush();
        assert.deepEqual(server.requests[1].map((sent) => sent.id), [silent.id]);
        assert.equal(outbox.pendingCount(), 0);
        assert.equal(outbox.refusedEvents().length, 1, 'refused ones stay until a fix');

        // A fix arrived: refused events go again
        await outbox.requeueRefused();
        assert.deepEqual(server.requests[2].map((sent) => sent.id), [unknown.id]);
        assert.equal(outbox.refusedEvents().length, 0);
    },

    async 'events: offline - again after 1 s, 2 s, 4 s … at most 30 s, by itself; online() sends at once'() {
        const { outbox, server, w } = eventsSetup({ autoFlush: true });
        server.answers.push(...Array(8).fill({ kind: 'offline' }));

        await outbox.enqueue(event());
        await outbox.flush();
        assert.equal(server.requests.length, 1);
        assert.equal(outbox.status().offline, true);

        for (const pause of [...OFFLINE_BACKOFF_MS, 30_000]) {
            const before = server.requests.length;
            await w.advance(pause - 1);
            assert.equal(server.requests.length, before, `not before ${pause} ms`);
            await w.advance(1);
            assert.equal(server.requests.length, before + 1, `after ${pause} ms`);
        }

        // The browser says it is back: no waiting for the 30 s
        await w.advance(1000);
        const before = server.requests.length;
        await outbox.online();
        assert.equal(server.requests.length, before + 1);
        assert.equal(outbox.hasUnsent(), false);
        assert.equal(outbox.status().offline, false);
        assert.equal(w.timers.length, 0, 'nothing left to retry');
    },

    async 'events: a busy server (429) is waited for as long as its Retry-After asks - nothing refused; without one, a growing pause'() {
        const { outbox, server, w } = eventsSetup();
        server.answers.push({ kind: 'server', status: 429, busy: true, retryAfter: 30_000 });
        await outbox.enqueue(event());
        await outbox.enqueue(event());

        await outbox.flush();
        assert.equal(server.requests.length, 1);
        assert.equal(outbox.status().busy, true);
        assert.equal(outbox.status().waitingUntil, START + 30_000);
        assert.equal(outbox.refusedEvents().length, 0);

        w.clock.now += 29_999;
        await outbox.flush();
        assert.equal(server.requests.length, 1, 'Retry-After honoured');
        w.clock.now += 1;
        await outbox.flush();
        assert.equal(server.requests.length, 2);
        assert.equal(server.requests[1].length, 2, 'still one batch');
        assert.equal(outbox.hasUnsent(), false);

        // No Retry-After: 5 s, then 15 s
        server.answers.push({ kind: 'server', status: 429, busy: true, retryAfter: null }, { kind: 'server', status: 403, busy: true, retryAfter: null });
        await outbox.enqueue(event());
        await outbox.flush();
        w.clock.now += 5000;
        await outbox.flush();
        assert.equal(server.requests.length, 4);
        w.clock.now += 14_999;
        await outbox.flush();
        assert.equal(server.requests.length, 4);
        w.clock.now += 1;
        await outbox.flush();
        assert.equal(server.requests.length, 5);
        assert.equal(outbox.hasUnsent(), false);
    },

    async 'events: a server error pauses everything, then one at a time - an event the server keeps choking on never holds the others back'() {
        const { outbox, server, w } = eventsSetup();
        const first = event();
        const broken = event();
        const third = event();
        server.answers.push((events) => (events.some((sent) => sent.id === broken.id)
            ? { kind: 'server', status: 500, retryAfter: null, busy: false }
            : { kind: 'ok', status: 200, data: { acks: events.map((sent) => sent.id), refused: [] } }));

        for (const sent of [first, broken, third]) {
            await outbox.enqueue(sent);
        }

        // Every answer from the same rule
        const rule = server.answers[0];
        server.answers = [];
        server.send = async (events) => {
            server.requests.push(events.map((sent) => sent.id));
            server.times.push(w.clock.now);

            return rule(events);
        };

        await outbox.flush();
        assert.deepEqual(server.requests, [[first.id, broken.id, third.id]]);
        assert.equal(outbox.status().waitingUntil, START + 5000, 'everything pauses 5 s');

        for (let round = 0; round < 6; round++) {
            w.clock.now += 120_000;
            await outbox.flush();
        }

        assert.equal(outbox.pendingCount(), 1, 'the others went through');
        assert.ok(server.requests.some((request) => request.length === 1 && request[0] === first.id));
        assert.ok(server.requests.some((request) => request.length === 1 && request[0] === third.id));
        const firstAlone = server.requests.findIndex((request) => request.length === 1 && request[0] === broken.id);
        assert.ok(firstAlone > 0);
        assert.ok(
            server.requests.slice(firstAlone).every((request) => !request.includes(broken.id) || request.length === 1),
            'once it failed alone, it is only ever sent alone',
        );

        // A fresh event is not held up by it
        const fresh = event();
        await outbox.enqueue(fresh);
        w.clock.now += 120_000;
        await outbox.flush();
        assert.ok(server.requests.some((request) => request.length === 1 && request[0] === fresh.id) || server.requests.some((request) => request.includes(fresh.id) && !request.includes(broken.id)));
        assert.equal(outbox.pendingCount(), 1);
    },

    async 'events: a 400 for the whole request is narrowed down to the event it is about'() {
        const { outbox, server } = eventsSetup();
        const good = event();
        const bad = event('finish.button', { clientMs: 'yesterday' });
        const later = event();
        server.answers.push(...Array(10).fill((events) => (events.some((sent) => sent.id === bad.id)
            ? { kind: 'client', status: 400, data: { error: 'invalid_json' } }
            : { kind: 'ok', status: 200, data: { acks: events.map((sent) => sent.id), refused: [] } })));

        for (const sent of [good, bad, later]) {
            await outbox.enqueue(sent);
        }

        await outbox.flush();

        assert.deepEqual(server.requests.map((request) => request.length), [3, 1, 2, 1, 1]);
        assert.deepEqual(outbox.refusedEvents().map((refused) => [refused.id, refused.reason]), [[bad.id, 'invalid_json']]);
        assert.equal(outbox.pendingCount(), 0);
        assert.equal(outbox.status().waitingUntil, null, 'no pause on the way');
    },

    async 'events: the link died (unknown_link) - nothing is sent, nothing lost, until retryNow()'() {
        const { outbox, server } = eventsSetup();
        server.answers.push({ kind: 'client', status: 404, data: { error: 'unknown_link' } });
        await outbox.enqueue(event());
        await outbox.enqueue(event());

        await outbox.flush();
        assert.equal(outbox.status().blocked, 'unknown_link');
        await outbox.flush();
        assert.equal(server.requests.length, 1);
        assert.equal(outbox.pendingCount(), 2);
        assert.equal(outbox.refusedEvents().length, 0);

        await outbox.retryNow();
        assert.equal(server.requests.length, 2);
        assert.equal(outbox.hasUnsent(), false);
        assert.equal(outbox.status().blocked, null);
    },

    async 'events: one tab sends - the other tab\'s events go out with the lock holder or once it looks again'() {
        const storage = createMemoryStorage();
        const locks = fakeLocks();
        const w = world();
        const tabA = eventsSetup({ storage, w, lock: createTabLock('msp-online-events', { locks }) });
        const tabB = eventsSetup({ storage, w, lock: createTabLock('msp-online-events', { locks }), autoFlush: true });

        // Tab A is in the middle of sending when tab B stores an event
        let release;
        tabA.server.answers.push(() => new Promise((resolve) => {
            release = resolve;
        }));
        const first = event();
        await tabA.outbox.enqueue(first);
        const sending = tabA.outbox.flush();
        await settle();
        assert.equal(tabA.server.requests.length, 1);

        const second = event();
        await tabB.outbox.enqueue(second);
        await settle();
        assert.equal(tabB.server.requests.length, 0, 'no second sending tab');
        assert.ok(w.timers.some((timer) => timer.ms === LOCK_RETRY_MS), 'it looks again shortly');

        release({ kind: 'ok', status: 200, data: { acks: [first.id], refused: [] } });
        await sending;
        assert.deepEqual(tabA.server.requests.flat().map((sent) => sent.id), [first.id]);

        await w.advance(LOCK_RETRY_MS);
        assert.deepEqual(tabB.server.requests.flat().map((sent) => sent.id), [second.id]);
        assert.equal((await storage.getAll()).length, 0);

        // Every tab reads the shared store: an event stored by B is sent by A's next round
        const third = event();
        tabB.outbox.autoFlush = false;
        await tabB.outbox.enqueue(third);
        await tabA.outbox.flush();
        assert.deepEqual(tabA.server.requests.flat().map((sent) => sent.id), [first.id, third.id]);
        await tabB.outbox.load();
        assert.equal(tabB.outbox.hasUnsent(), false);
    },

    async 'events: a reload - a new outbox over the same store sends what the old one left; refused ones stay aside'() {
        const storage = createMemoryStorage();
        const before = eventsSetup({ storage });
        const refused = event('consent.given');
        const unanswered = event();
        before.server.decide = (sent) => (sent.id === refused.id ? { refused: 'invalid_fields' } : 'silent');
        await before.outbox.enqueue(refused);
        await before.outbox.enqueue(unanswered);
        await before.outbox.flush();
        const notSent = event('timeup.pieces');
        await before.outbox.enqueue(notSent);
        before.outbox.close();

        // The page reloads
        const after = eventsSetup({ storage, w: before.w });
        await after.outbox.load();
        assert.equal(after.outbox.pendingCount(), 2);
        assert.deepEqual(after.outbox.refusedEvents().map((item) => [item.id, item.reason]), [[refused.id, 'invalid_fields']]);

        await after.outbox.flush();
        assert.deepEqual(after.server.requests, [[{ ...notSent }]].map((request) => request.map((sent) => JSON.parse(JSON.stringify(sent)))));
        before.w.clock.now += 5000;
        await after.outbox.flush();
        assert.deepEqual(after.server.requests[1].map((sent) => sent.id), [unanswered.id]);
        assert.equal(after.outbox.pendingCount(), 0);
        assert.equal(after.outbox.refusedEvents().length, 1);
    },

    async 'events: a store that refuses writes - sent from memory all the same'() {
        const memory = createMemoryStorage();
        const storage = {
            ...memory,
            put: async () => {
                throw new DOMException('full', 'QuotaExceededError');
            },
        };
        const { outbox, server } = eventsSetup({ storage });

        const stored = await outbox.enqueue(event());
        assert.equal(stored.persisted, false);
        assert.equal(outbox.pendingCount(), 1);

        await outbox.flush();
        assert.equal(server.requests.length, 1);
        assert.equal(outbox.hasUnsent(), false);
        assert.equal(outbox.status().storageFailed, true);
    },

    async 'events: the same id twice is stored once; the counts, onChange and the leave-page warning follow the queue'() {
        const { outbox, counter } = eventsSetup();
        const tap = event();

        const storing = outbox.enqueue(tap);
        assert.equal(outbox.pendingCount(), 1, 'counted while it is being stored');
        await storing;
        assert.equal((await outbox.enqueue({ ...tap })).duplicate, true);
        assert.equal(outbox.pendingCount(), 1);
        assert.ok(counter.changes > 0);
        await assert.rejects(outbox.enqueue({ type: 'finish.button' }), TypeError);

        const warn = createBeforeUnloadHandler(() => outbox.hasUnsent());
        const leaving = { prevented: false, returnValue: undefined, preventDefault() { this.prevented = true; } };
        assert.equal(warn(leaving), '');
        assert.equal(leaving.prevented, true);
        assert.equal(leaving.returnValue, '');

        await outbox.flush();
        const calm = { prevented: false, preventDefault() { this.prevented = true; } };
        assert.equal(warn(calm), undefined);
        assert.equal(calm.prevented, false);
    },

    async 'events: isPending() - on its way while stored, queued or sent; no more once acknowledged or refused'() {
        const { outbox, server } = eventsSetup();
        const ready = event('device.ready');
        const refusedOne = event('finish.button');

        assert.equal(outbox.isPending(ready.id), false, 'never here');
        const storing = outbox.enqueue(ready);
        assert.equal(outbox.isPending(ready.id), true, 'while it is being stored');
        await storing;
        await outbox.enqueue(refusedOne);
        assert.equal(outbox.isPending(ready.id), true, 'queued');

        server.decide = (sent) => (sent.id === refusedOne.id ? { refused: 'wrong_round' } : 'ack');
        await outbox.flush();

        assert.equal(outbox.isPending(ready.id), false, 'acknowledged - the server stored it');
        assert.equal(outbox.isPending(refusedOne.id), false, 'refused - nothing more to wait for');
    },

    async 'events: another participant\'s events on this device are never sent'() {
        const storage = createMemoryStorage();
        const other = eventsSetup({ storage, scope: 'participant-2' });
        await other.outbox.enqueue(event());

        const mine = eventsSetup({ storage, scope: 'participant-1' });
        await mine.outbox.load();
        await mine.outbox.flush();

        assert.equal(mine.server.requests.length, 0);
        assert.equal(mine.outbox.pendingCount(), 0);
        assert.equal(await mine.outbox.foreignCount(), 1);
    },

    async 'events: a UUIDv7 - version 7, the RFC variant, the moment in its first 48 bits'() {
        const ms = 1_792_002_832_345;
        const id = uuidv7(ms, (bytes) => bytes.fill(0xab));

        assert.match(id, /^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/);
        assert.equal(parseInt(id.replace(/-/g, '').slice(0, 12), 16), ms);
        assert.ok(uuidv7(ms) < uuidv7(ms + 1), 'ids sort by their moment');
        assert.notEqual(uuidv7(ms), uuidv7(ms), 'random apart from the moment');
    },

    async 'the IndexedDB stores open their own databases - never the referees\' outbox'() {
        const opened = [];
        const factory = {
            open(name, version) {
                opened.push([name, version]);
                const request = {};
                const database = {
                    objectStoreNames: { contains: () => false },
                    createObjectStore: () => ({}),
                };
                setImmediate(() => {
                    request.result = database;
                    request.onupgradeneeded?.();
                    request.onsuccess();
                });

                return request;
            },
        };

        const events = await createEventsStorage(factory);
        const media = await createMediaStorage(factory);

        assert.deepEqual(opened, [[EVENTS_DATABASE, 1], [MEDIA_DATABASE, 1]]);
        assert.equal(events.persistent, true);
        assert.equal(media.persistent, true);
        assert.equal(await createEventsStorage(null), null);
        assert.equal(await createMediaStorage(undefined), null);
    },

    // ---- the media outbox

    async 'media: stored first, presigned, uploaded, reported, gone - the blob leaves the device once the store has it'() {
        const log = [];
        const memory = createMediaMemoryStorage();
        const storage = {
            ...memory,
            put: async (item) => {
                log.push(`store:${item.stage}:${item.blob === null ? 'no-blob' : 'blob'}`);
                await memory.put(item);
            },
        };
        const { outbox, api, store } = mediaSetup({ storage });
        const tapId = event().id;
        const blob = photo('finish-photo', 1500);

        const stored = await outbox.enqueue({ kind: 'button', blob, takenMs: START - 500, eventId: tapId, roundId: ROUND });
        assert.deepEqual([stored.persisted, stored.dropped], [true, false]);
        assert.deepEqual(log, ['store:queued:blob'], 'on the device before any request');

        await outbox.flush();

        assert.deepEqual(api.presigns, [{
            kind: 'button',
            takenMs: START - 500,
            bytes: 1500,
            contentType: 'image/jpeg',
            eventId: tapId,
            roundId: ROUND,
            itemId: stored.id,
            previousFailure: null,
        }]);
        assert.equal(store.puts.length, 1);
        assert.equal(store.puts[0].url, 'https://objects.test/online/m1');
        assert.equal(store.puts[0].method, 'PUT');
        assert.equal(store.puts[0].blob, blob);
        assert.deepEqual(store.puts[0].headers, { 'Content-Type': 'image/jpeg', 'Content-Length': '1500' });
        assert.deepEqual(api.reports, [{ mediaId: 'm1', bytes: 1500, manifest: null, kind: 'button', takenMs: START - 500, eventId: tapId, roundId: ROUND, store: 'primary', contentType: 'image/jpeg', itemId: stored.id }]);
        assert.deepEqual(log, ['store:queued:blob', 'store:presigned:blob', 'store:uploaded:no-blob']);
        assert.equal((await memory.getAll()).length, 0);
        assert.equal(outbox.pendingCount(), 0);
        assert.equal(outbox.status().uploaded, 1);
        assert.equal(outbox.queuedBytes(), 0);
    },

    async 'media: critical items first, oldest first - then periodic photos, newest first'() {
        const { outbox, api } = mediaSetup();
        await outbox.enqueue({ kind: 'periodic', blob: photo('p1'), takenMs: 1000 });
        await outbox.enqueue({ kind: 'periodic', blob: photo('p2'), takenMs: 31_000 });
        await outbox.enqueue({ kind: 'button', blob: photo('b1'), takenMs: 40_000 });
        await outbox.enqueue({ kind: 'candidate', blob: photo('c1'), takenMs: 20_000 });
        await outbox.enqueue({ kind: 'periodic', blob: photo('p3'), takenMs: 61_000 });
        await outbox.enqueue({ kind: 'end', blob: photo('e1'), takenMs: 60_000 });

        await outbox.flush();

        assert.deepEqual(presignedIds(api), ['candidate@20000', 'button@40000', 'end@60000', 'periodic@61000', 'periodic@31000', 'periodic@1000']);
        assert.equal(api.reports.length, 6);
    },

    async 'media: a URL that ended while the phone was offline is presigned again - by the presign answer\'s own clock'() {
        // The phone's clock is three hours ahead of the server's: expiresAtMs alone would look long gone
        const { outbox, api, store, w } = mediaSetup({ autoFlush: true, isOnline: () => false });
        api.serverAheadMs = -3 * 3_600_000;
        store.answers.push(...Array(40).fill('offline'));

        await outbox.enqueue({ kind: 'start', blob: photo('start'), takenMs: START });
        await settle();
        assert.equal(api.presigns.length, 1);
        assert.equal(store.puts.length, 1);

        await w.advance(1000);
        assert.equal(store.puts.length, 2);
        assert.equal(api.presigns.length, 1, 'still valid: the same URL again');
        assert.equal(store.puts[1].url, store.puts[0].url);

        // Six minutes offline: the 5-minute URL ends - a new one before the next try
        await w.advance(6 * 60_000);
        assert.equal(api.presigns.length, 2);
        assert.equal(store.puts.at(-1).url, 'https://objects.test/online/m2');

        store.answers = [];
        await outbox.online();
        assert.equal(api.reports.length, 1);
        assert.equal(api.reports[0].mediaId, 'm2');
    },

    async 'media: the store refusing a URL (403) gets a fresh one at once; refused again and again - a pause'() {
        const { outbox, api, store, w } = mediaSetup();
        store.answers.push({ status: 403 });
        await outbox.enqueue({ kind: 'candidate', blob: photo('c'), takenMs: START });

        await outbox.flush();
        assert.equal(api.presigns.length, 2);
        assert.deepEqual(store.puts.map((upload) => upload.url), ['https://objects.test/online/m1', 'https://objects.test/online/m2']);
        assert.equal(api.reports[0].mediaId, 'm2');

        // A store that refuses every fresh URL
        store.answers.push(...Array(MAX_PRESIGNS_IN_A_ROW + 1).fill({ status: 403 }));
        await outbox.enqueue({ kind: 'button', blob: photo('b'), takenMs: START + 1 });
        await outbox.flush();
        assert.equal(api.presigns.length, 2 + MAX_PRESIGNS_IN_A_ROW);
        assert.equal(outbox.pendingCount(), 1);

        w.clock.now += 4999;
        await outbox.flush();
        assert.equal(api.presigns.length, 2 + MAX_PRESIGNS_IN_A_ROW, 'pausing');
        w.clock.now += 1;
        await outbox.flush();
        assert.equal(api.presigns.length, 3 + MAX_PRESIGNS_IN_A_ROW);
        store.answers = [];
        w.clock.now += 15_000;
        await outbox.flush();
        assert.equal(outbox.pendingCount(), 0, 'never dropped');
    },

    async 'media: offline during the upload - the same URL again while it is valid; online() goes at once'() {
        const { outbox, api, store } = mediaSetup({ isOnline: () => false });
        store.answers.push('offline');
        await outbox.enqueue({ kind: 'burst', blob: photo('burst', 4000), takenMs: START, manifest: [{ offset: 0, length: 4000, captureMs: START }] });

        await outbox.flush();
        assert.equal(outbox.status().offline, true);
        assert.equal(outbox.status().waitingUntil, START + 1000);

        await outbox.online();
        assert.equal(api.presigns.length, 1);
        assert.equal(store.puts.length, 2);
        assert.equal(store.puts[0].url, store.puts[1].url);
        assert.equal(api.reports.length, 1);
        assert.equal(outbox.status().offline, false);
        assert.equal(outbox.items.length, 0);
    },

    async 'media: a store that does not answer while the phone is online - from the second failure a fresh URL, the presign telling the breaker (status 0)'() {
        // The failover drill (spikes.md S5): a stopped store, or one answering errors without CORS headers, is a failed
        // fetch in a browser - never a 5xx the page could read
        const { outbox, api, store, w } = mediaSetup({ isOnline: () => true });
        store.answers.push('offline', { status: 0 });
        await outbox.enqueue({ kind: 'periodic', blob: photo('p'), takenMs: START });

        await outbox.flush();
        assert.equal(store.puts.length, 1);
        assert.equal(outbox.status().waitingUntil, START + 1000, 'the offline pause - the next try comes soon');
        assert.deepEqual(outbox.items[0].lastFailure, { status: 0, store: 'primary', mediaId: 'm1' });

        w.clock.now += 1000;
        await outbox.flush();
        assert.equal(store.puts.length, 2);
        assert.equal(store.puts[1].url, store.puts[0].url, 'the second try: the same URL');
        assert.equal(api.presigns.length, 1);

        w.clock.now += 2000;
        await outbox.flush();
        assert.equal(api.presigns.length, 2, 'then a fresh one');
        assert.deepEqual(api.presigns[1].previousFailure, { status: 0, store: 'primary', mediaId: 'm1' });
        assert.equal(store.puts[2].url, 'https://objects.test/online/m2');
        assert.equal(api.reports.length, 1);
        assert.equal(outbox.status().offline, false);
        assert.equal(outbox.items.length, 0);
    },

    async 'media: a phone the browser knows to be offline blames no store - the same URL, no previousFailure'() {
        const { outbox, api, store, w } = mediaSetup({ isOnline: () => false });
        store.answers.push('offline', 'offline', 'offline');
        await outbox.enqueue({ kind: 'button', blob: photo('b'), takenMs: START });

        await outbox.flush();
        w.clock.now += 1000;
        await outbox.flush();
        w.clock.now += 2000;
        await outbox.flush();
        assert.equal(store.puts.length, 3);
        assert.equal(new Set(store.puts.map((put) => put.url)).size, 1);
        assert.equal(api.presigns.length, 1);
        assert.equal(outbox.items[0].lastFailure, null);

        w.clock.now += 4000;
        await outbox.flush();
        assert.equal(api.presigns.length, 1);
        assert.equal(api.reports.length, 1);
    },

    async 'media: a busy API (429) is waited for as long as it asks; a server error pauses and the item waits its own turn'() {
        const { outbox, api, w } = mediaSetup();
        api.presignAnswers.push({ kind: 'server', status: 429, busy: true, retryAfter: 20_000 });
        await outbox.enqueue({ kind: 'start', blob: photo('s'), takenMs: START });

        await outbox.flush();
        assert.equal(outbox.status().busy, true);
        w.clock.now += 19_999;
        await outbox.flush();
        assert.equal(api.presigns.length, 1);
        w.clock.now += 1;
        await outbox.flush();
        assert.equal(api.reports.length, 1);

        api.presignAnswers.push({ kind: 'server', status: 500, busy: false, retryAfter: null });
        await outbox.enqueue({ kind: 'end', blob: photo('e'), takenMs: START + 1 });
        await outbox.flush();
        assert.equal(outbox.status().waitingUntil, w.clock.now + 5000);
        assert.equal(outbox.items[0].attempts, 1);
        w.clock.now += 5000;
        await outbox.flush();
        assert.equal(api.reports.length, 2);
    },

    async 'media: the store failing (5xx) - a pause; from the second failure a fresh URL, the presign telling which store failed'() {
        const { outbox, api, store, w } = mediaSetup();
        store.answers.push({ status: 503 }, { status: 503 });
        await outbox.enqueue({ kind: 'candidate', blob: photo('c'), takenMs: START });

        await outbox.flush();
        assert.equal(store.puts.length, 1);
        assert.equal(outbox.status().waitingUntil, START + 5000);

        w.clock.now += 5000;
        await outbox.flush();
        assert.equal(store.puts.length, 2);
        assert.equal(store.puts[1].url, store.puts[0].url, 'the second try: the same URL');
        assert.equal(api.presigns.length, 1);

        w.clock.now += 15_000;
        await outbox.flush();
        assert.equal(api.presigns.length, 2, 'then a fresh one');
        assert.deepEqual(api.presigns[1].previousFailure, { status: 503, store: 'primary', mediaId: 'm1' });
        assert.equal(store.puts[2].url, 'https://objects.test/online/m2');
        assert.equal(api.reports.length, 1);
    },

    async 'media: quota pressure thins periodic photos evenly, keeps the newest; critical items are never dropped'() {
        const box = {};
        const { outbox } = mediaSetup({ estimate: async () => ({ usage: box.base + box.outbox.queuedBytes(), quota: 1000 }), quotaShare: 0.8 });
        box.outbox = outbox;
        box.base = 0;

        for (let second = 0; second <= 180; second += 30) {
            await outbox.enqueue({ kind: 'periodic', blob: photo(`p${second}`), takenMs: second * 1000 });
        }

        const periodic = () => outbox.items.filter((item) => item.kind === 'periodic').map((item) => item.takenMs / 1000);

        for (const second of [210, 240, 270, 300, 330]) {
            const stored = await outbox.enqueue({ kind: 'periodic', blob: photo(`p${second}`), takenMs: second * 1000 });
            assert.equal(stored.dropped, false);
        }

        assert.deepEqual(periodic(), [0, 60, 120, 180, 240, 270, 300, 330], 'every other one of the oldest went');
        assert.equal(outbox.status().thinned, 4);

        // A finish photo bigger than what is left: periodic photos make room, the newest stays
        const finish = await outbox.enqueue({ kind: 'button', blob: photo('button', 450), takenMs: 340_000 });
        assert.deepEqual([finish.persisted, finish.dropped], [true, false]);
        assert.ok(periodic().includes(330));
        assert.ok(outbox.queuedBytes() <= 800);

        // Something else fills the quota: no periodic photo fits any more - a critical one is kept regardless
        box.base = 950;
        const late = await outbox.enqueue({ kind: 'periodic', blob: photo('late'), takenMs: 400_000 });
        assert.equal(late.dropped, true);
        const end = await outbox.enqueue({ kind: 'end', blob: photo('end'), takenMs: 401_000 });
        assert.equal(end.dropped, false);
        assert.ok(outbox.items.some((item) => item.kind === 'button'));
        assert.ok(outbox.items.some((item) => item.kind === 'end'));
    },

    async 'media: without an estimate the outbox\'s own budget thins the same way'() {
        const { outbox } = mediaSetup({ maxBytes: 300 });

        for (const second of [0, 30, 60, 90]) {
            await outbox.enqueue({ kind: 'periodic', blob: photo(`p${second}`), takenMs: second * 1000 });
        }

        assert.deepEqual(outbox.items.map((item) => item.takenMs / 1000), [0, 60, 90]);
        assert.equal(outbox.queuedBytes(), 300);
    },

    async 'media: the store refuses to keep it - a critical item stays in memory and is uploaded anyway, a periodic one is dropped'() {
        const memory = createMediaMemoryStorage();
        const storage = {
            ...memory,
            put: async () => {
                throw new DOMException('full', 'QuotaExceededError');
            },
        };
        const { outbox, api } = mediaSetup({ storage });

        const critical = await outbox.enqueue({ kind: 'candidate', blob: photo('c'), takenMs: START });
        assert.deepEqual([critical.persisted, critical.dropped], [false, false]);
        const periodic = await outbox.enqueue({ kind: 'periodic', blob: photo('p'), takenMs: START });
        assert.equal(periodic.dropped, true);

        await outbox.flush();
        assert.deepEqual(api.reports.map((report) => report.kind), ['candidate']);
        assert.equal(outbox.status().storageFailed, true);
        assert.equal(outbox.hasUnsent(), false);
    },

    async 'media: a reload - an item uploaded but not reported is only reported; a presigned one keeps its URL'() {
        const storage = createMediaMemoryStorage();
        const w = world();
        const before = mediaSetup({ storage, w });
        before.api.reportFails = 1;
        before.store.answers.push('default', 'offline');
        await before.outbox.enqueue({ kind: 'candidate', blob: photo('reported-later'), takenMs: START });
        await before.outbox.enqueue({ kind: 'button', blob: photo('uploaded-later'), takenMs: START + 1 });
        await before.outbox.flush();

        const kept = await storage.getAll();
        const reportOnly = kept.find((item) => item.kind === 'candidate');
        assert.equal(reportOnly.stage, 'uploaded');
        assert.equal(reportOnly.blob, null, 'the bytes are in the store - not on the device');
        assert.equal(kept.find((item) => item.kind === 'button').stage, 'presigned');
        before.outbox.close();

        // The page reloads, a minute later
        w.clock.now += 60_000;
        const after = mediaSetup({ storage, w });
        await after.outbox.load();
        assert.equal(after.outbox.pendingCount(), 2);
        await after.outbox.flush();

        assert.equal(after.api.presigns.length, 0, 'nothing presigned again');
        assert.deepEqual(after.store.puts.map((upload) => upload.url), ['https://objects.test/online/m2'], 'the button photo with its URL');
        assert.deepEqual(after.api.reports.map((report) => [report.kind, report.mediaId]).sort(), [['button', 'm2'], ['candidate', 'm1']]);
        assert.equal((await storage.getAll()).length, 0);
    },

    async 'media: a refused item (media_too_large) is reported and leaves the device - the others go on'() {
        const { outbox, api, storage } = mediaSetup();
        api.presignAnswers.push({ kind: 'client', status: 422, data: { error: 'media_too_large' } });
        await outbox.enqueue({ kind: 'burst', blob: photo('huge', 3000), takenMs: START });
        await outbox.enqueue({ kind: 'button', blob: photo('ok'), takenMs: START + 1 });

        await outbox.flush();

        assert.deepEqual(outbox.refusedItems().map((item) => [item.kind, item.reason]), [['burst', 'media_too_large']]);
        assert.equal(outbox.status().refused, 1);
        assert.equal(api.reports.length, 1);
        assert.equal(outbox.pendingCount(), 0);
        assert.equal(outbox.queuedBytes(), 0, 'its blob does not stay');
        assert.deepEqual(await storage.getAll(), [], 'nothing of it on the device');

        assert.equal(await outbox.discardRefused(), 1);
        assert.deepEqual(outbox.refusedItems(), []);
        assert.equal(outbox.status().refused, 1, 'the count stays');
    },

    async 'media: the refusals listed are the newest - the count is all of them'() {
        const { outbox, api } = mediaSetup();

        for (let index = 0; index < REFUSED_LISTED + 5; index++) {
            api.presignAnswers.push({ kind: 'client', status: 422, data: { error: 'media_kind_unknown' } });
            await outbox.enqueue({ kind: 'periodic', blob: photo(`p${index}`), takenMs: START + index });
        }

        await outbox.flush();

        assert.equal(outbox.status().refused, REFUSED_LISTED + 5);
        assert.equal(outbox.refusedItems().length, REFUSED_LISTED);
        assert.equal(outbox.queuedBytes(), 0);
    },

    async 'media: a page opening the outbox drops what is older than 7 days and refused items an older page kept'() {
        const w = world();
        const day = 24 * 60 * 60 * 1000;
        const blob = photo('p');
        const item = (id, overrides = {}) => ({
            id,
            scope: 'participant-1',
            seq: 1,
            createdAt: w.clock.now,
            kind: 'periodic',
            priority: 'periodic',
            takenMs: w.clock.now,
            eventId: null,
            roundId: ROUND,
            contentType: 'image/jpeg',
            bytes: blob.size,
            manifest: null,
            blob,
            stage: 'queued',
            mediaId: null,
            store: null,
            url: null,
            method: 'PUT',
            headers: null,
            validUntil: null,
            state: 'pending',
            reason: null,
            refusedAt: null,
            attempts: 0,
            nextAttemptAt: 0,
            presignsInARow: 0,
            lastFailure: null,
            ...overrides,
        });
        const storage = createMediaMemoryStorage([
            item('six-days', { seq: 3, createdAt: w.clock.now - 6 * day }),
            item('eight-days', { seq: 1, createdAt: w.clock.now - 8 * day }),
            item('refused-before', { seq: 2, state: 'refused', reason: 'media_too_large', refusedAt: w.clock.now - day }),
            item('another-participant-a-month-ago', { seq: 4, scope: 'participant-2', createdAt: w.clock.now - 30 * day }),
        ]);
        const media = mediaWorld(w);

        const outbox = await openMediaOutbox({
            storage,
            presign: media.presign,
            put: media.put,
            reportUploaded: media.reportUploaded,
            scope: 'participant-1',
            now: w.now,
            lock: async (task) => {
                await task();

                return true;
            },
            schedule: w.schedule,
            cancel: w.cancel,
            autoFlush: false,
        });

        assert.deepEqual((await storage.getAll()).map((stored) => stored.id), ['six-days']);
        assert.equal(outbox.pendingCount(), 1);
        assert.equal(outbox.status().refused, 0, 'an older page reported its refusals');
        assert.equal(MAX_AGE_MS, 7 * day);
        assert.equal(await outbox.prune(), 0, 'nothing more to drop');
    },

    async 'media: the link died - nothing is uploaded until retryNow()'() {
        const { outbox, api } = mediaSetup();
        api.presignAnswers.push({ kind: 'client', status: 404, data: { error: 'unknown_link' } });
        await outbox.enqueue({ kind: 'start', blob: photo('s'), takenMs: START });

        await outbox.flush();
        assert.equal(outbox.status().blocked, 'unknown_link');
        await outbox.flush();
        assert.equal(api.presigns.length, 1);
        assert.equal(outbox.pendingCount(), 1);

        await outbox.retryNow();
        assert.equal(api.reports.length, 1);
    },

    async 'media: a report that fails is tried again later - never another upload'() {
        const { outbox, api, store, w } = mediaSetup();
        api.reportFails = 1;
        await outbox.enqueue({ kind: 'end', blob: photo('end'), takenMs: START });

        await outbox.flush();
        assert.equal(store.puts.length, 1);
        assert.equal(api.reports.length, 0);
        assert.equal(outbox.pendingCount(), 1);
        assert.equal(outbox.queuedBytes(), 0);

        w.clock.now += 5000;
        await outbox.flush();
        assert.equal(store.puts.length, 1);
        assert.equal(api.reports.length, 1);
        assert.equal(outbox.pendingCount(), 0);
    },

    async 'media: one tab uploads - a tab without the lock leaves everything and looks again shortly'() {
        const w = world();
        const { outbox, api } = mediaSetup({ w, autoFlush: true, lock: async () => false });
        await outbox.enqueue({ kind: 'start', blob: photo('s'), takenMs: START });
        await settle();

        assert.equal(api.presigns.length, 0);
        assert.equal(outbox.pendingCount(), 1);
        assert.ok(w.timers.some((timer) => timer.ms === LOCK_RETRY_MS));

        outbox.lock = async (task) => {
            await task();

            return true;
        };
        await w.advance(LOCK_RETRY_MS);
        assert.equal(api.reports.length, 1);
    },

    async 'media: a burst carries its manifest to the report; spread-out items wait their delay'() {
        const { outbox, api, w } = mediaSetup({ autoFlush: true });
        const manifest = [{ offset: 0, length: 40, captureMs: START - 5000 }, { offset: 40, length: 60, captureMs: START - 4900 }];
        await outbox.enqueue({ kind: 'burst', blob: photo('frames'), takenMs: START, manifest, eventId: 'e1', delayMs: 30_000 });
        await settle();
        assert.equal(api.presigns.length, 0, 'not before its delay');

        await w.advance(30_000);
        assert.equal(api.presigns[0].contentType, BURST_CONTENT_TYPE);
        assert.deepEqual(api.reports[0].manifest, manifest);
        assert.equal(api.reports[0].eventId, 'e1');

        await assert.rejects(outbox.enqueue({ kind: 'selfie', blob: photo('x'), takenMs: START }), TypeError);
        await assert.rejects(outbox.enqueue({ kind: 'end', blob: null, takenMs: START }), TypeError);
    },

    async 'media: putToStore() sends the blob with the presigned headers - not the ones the browser sets, no cookies'() {
        const calls = [];
        const blob = photo('p', 123);
        const response = await putToStore('https://objects.test/online/m1', { 'Content-Type': 'image/jpeg', 'Content-Length': '123', Host: 'objects.test', 'x-amz-meta-round': 7 }, blob, {
            method: 'PUT',
            fetch: async (url, init) => {
                calls.push([url, init]);

                return { status: 200 };
            },
        });

        assert.equal(response.status, 200);
        const [url, init] = calls[0];
        assert.equal(url, 'https://objects.test/online/m1');
        assert.equal(init.method, 'PUT');
        assert.equal(init.body, blob);
        assert.deepEqual(init.headers, { 'Content-Type': 'image/jpeg', 'x-amz-meta-round': '7' });
        assert.equal(init.credentials, 'omit');
        assert.equal(init.cache, 'no-store');
        assert.equal('signal' in init, false, 'no signal asked for, none sent');

        // The outbox's timeout aborts it through the signal
        const abort = new AbortController();
        await putToStore('https://objects.test/online/m2', {}, blob, {
            signal: abort.signal,
            fetch: async (url, init) => {
                calls.push([url, init]);

                return { status: 200 };
            },
        });
        assert.equal(calls[1][1].signal, abort.signal);
    },

    async 'media: the upload timeout - a floor plus the body at a poor mobile speed'() {
        assert.equal(UPLOAD_TIMEOUT_FLOOR_MS, 15_000);
        assert.equal(uploadTimeoutMs(0), 15_000);
        assert.equal(uploadTimeoutMs(Number.NaN), 15_000);
        assert.equal(uploadTimeoutMs(-5), 15_000);
        // A periodic photo (~35 KB), a pessimistic photo (150 KB), a 1 MB burst, a 1.8 MB burst
        assert.equal(uploadTimeoutMs(35 * 1024), 16_094);
        assert.equal(uploadTimeoutMs(150 * 1024), 19_688);
        assert.equal(uploadTimeoutMs(1024 * 1024), 47_000);
        assert.equal(uploadTimeoutMs(1_800_000), 69_932);
    },

    async 'media: a store that hangs - every upload aborted at its bound with a fresh URL at once; three phones are on the second store within 20 s'() {
        // The failover drill's black hole (spikes.md S5: 41 s to switch - every phone waited for its connection timeout).
        // Three phones, one server with a circuit breaker like OnlineStoreBreaker: the primary is left once three phones
        // reported it failing (status 0) within 60 s.
        const w = world();
        const failing = new Map();
        const presigns = [];
        const puts = [];
        const reports = [];
        let nextMediaId = 1;
        let nextItemId = 1;

        const presignFor = (phone) => async (input) => {
            presigns.push({ phone, at: w.clock.now, previousFailure: input.previousFailure });

            if (input.previousFailure?.status === 0 && input.previousFailure?.store === 'primary') {
                failing.set(phone, w.clock.now);
            }

            const open = [...failing.values()].filter((at) => w.clock.now - at <= 60_000).length >= 3;
            const store = open ? 'secondary' : 'primary';
            const mediaId = `m${nextMediaId++}`;

            return {
                kind: 'ok',
                status: 200,
                data: {
                    serverNowMs: w.clock.now,
                    mediaId,
                    store,
                    method: 'PUT',
                    url: `https://${store}.test/online/${mediaId}`,
                    headers: { 'Content-Type': input.contentType, 'Content-Length': String(input.bytes) },
                    expiresAtMs: w.clock.now + 300_000,
                },
            };
        };

        // The primary never answers - its request ends only when aborted; the second store answers at once
        const putFor = (phone) => (url, headers, blob, { signal = null } = {}) => {
            const upload = { phone, url, at: w.clock.now, abortedAt: null };
            puts.push(upload);

            if (url.startsWith('https://secondary.test/')) {
                return Promise.resolve({ status: 200 });
            }

            return new Promise((resolve, reject) => {
                signal?.addEventListener('abort', () => {
                    upload.abortedAt = w.clock.now;
                    reject(new Error('The operation was aborted.'));
                });
            });
        };

        const phones = ['phone-1', 'phone-2', 'phone-3'].map((phone) => new MediaOutbox({
            storage: createMediaMemoryStorage(),
            presign: presignFor(phone),
            put: putFor(phone),
            reportUploaded: async (report) => {
                reports.push({ phone, mediaId: report.mediaId, store: report.store, at: w.clock.now });
            },
            scope: phone,
            now: w.now,
            schedule: w.schedule,
            cancel: w.cancel,
            autoFlush: true,
            isOnline: () => true,
            newId: () => `item-${nextItemId++}`,
        }));

        for (const [index, outbox] of phones.entries()) {
            await outbox.enqueue({ kind: 'periodic', blob: photo(`p${index}`, 35 * 1024), takenMs: START + index });
        }

        await settle();
        assert.equal(puts.length, 3, 'every phone is uploading to the primary');

        for (let elapsed = 0; elapsed < 45_000 && reports.length < 3; elapsed += 500) {
            await w.advance(500);
        }

        const bound = uploadTimeoutMs(35 * 1024);
        const onPrimary = puts.filter((upload) => upload.url.startsWith('https://primary.test/'));
        const firstOnSecondary = puts.find((upload) => upload.url.startsWith('https://secondary.test/'));

        assert.ok(firstOnSecondary, 'the second store took an upload');
        assert.ok(firstOnSecondary.at - START <= 20_000, `the first upload reached the second store ${firstOnSecondary.at - START} ms after the primary began to hang`);
        assert.ok(onPrimary.every((upload) => upload.abortedAt !== null && upload.abortedAt - upload.at === bound), 'every hanging upload was aborted at its bound');
        assert.equal(new Set(onPrimary.map((upload) => upload.url)).size, onPrimary.length, 'never the same URL twice - a fresh one at once');
        assert.ok(presigns.filter((presign) => presign.previousFailure !== null).every((presign) => presign.previousFailure.status === 0 && presign.previousFailure.store === 'primary'));

        // Nothing lost: every photo reached the second store and was reported
        assert.equal(reports.length, 3);
        assert.ok(reports.every((report) => report.store === 'secondary'));
        assert.ok(phones.every((outbox) => outbox.pendingCount() === 0));
    },

    async 'media: a slow upload that keeps going is never cut off - only one past its bound is'() {
        const w = world();
        const media = mediaWorld(w);
        const uploads = [];
        let takesMs = 60_000;
        const put = (url, headers, blob, { signal = null } = {}) => {
            const upload = { url, at: w.clock.now, aborted: false };
            uploads.push(upload);
            signal?.addEventListener('abort', () => {
                upload.aborted = true;
            });

            return new Promise((resolve, reject) => {
                w.schedule(() => (upload.aborted ? reject(new Error('aborted')) : resolve({ status: 200 })), takesMs);
            });
        };
        const outbox = new MediaOutbox({
            storage: createMediaMemoryStorage(),
            presign: media.presign,
            put,
            reportUploaded: media.reportUploaded,
            scope: 'participant-1',
            now: w.now,
            schedule: w.schedule,
            cancel: w.cancel,
            isOnline: () => true,
        });

        // A 1.8 MB burst on a poor connection: 60 s, within its 69.9 s
        await outbox.enqueue({ kind: 'burst', blob: photo('burst', 1_800_000), takenMs: START, manifest: [{ offset: 0, length: 1_800_000, captureMs: START }] });
        await settle();
        await w.advance(61_000);

        assert.equal(uploads.length, 1);
        assert.equal(uploads[0].aborted, false);
        assert.equal(media.api.presigns.length, 1);
        assert.equal(media.api.reports.length, 1);
        assert.equal(outbox.status().offline, false);

        // The same burst taking 75 s: aborted at 69.9 s, then sent again with a fresh URL
        takesMs = 75_000;
        await outbox.enqueue({ kind: 'burst', blob: photo('burst-2', 1_800_000), takenMs: START + 1, manifest: [{ offset: 0, length: 1_800_000, captureMs: START + 1 }] });
        await settle();
        const started = w.clock.now;
        await w.advance(uploadTimeoutMs(1_800_000) - 1);
        assert.equal(uploads[1].aborted, false, 'still within its bound');
        await w.advance(1);
        assert.equal(uploads[1].aborted, true);
        assert.equal(w.clock.now - started, 69_932);
        assert.equal(outbox.items[0].stage, 'queued', 'a fresh URL next time');
        assert.deepEqual(outbox.items[0].lastFailure, { status: 0, store: 'primary', mediaId: 'm2' });
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
