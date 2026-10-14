/**
 * The participant phone's event outbox (docs/features/online-competitions/README.md §5.6): every event - ready,
 * countdown seen, Finished, camera lost, time's up, photo uploaded … - is written to the phone first (IndexedDB, its
 * own database) and only then sent, in batches, until the server acknowledges it. A finish recorded offline keeps its
 * exact time; the server stores it as received (how late it came is the review's business).
 *
 * - The event carries its own UUIDv7 `id` (the server's primary key: a resend is acknowledged again, never stored
 *   twice) and its times (clock_sync.js stamp()); the outbox never changes it.
 * - The server answers per event (`{acks, refused}`): acknowledged → gone from the phone; refused (unknown type,
 *   invalid fields, wrong round) → kept aside with the reason (refusedEvents()), never dropped silently; neither →
 *   sent again later.
 * - Offline: again after 1 s, 2 s, 4 s … at most 30 s - at once when the browser says it is back (online()). A busy
 *   server (429, a ban or proxy page) pauses everything as long as its Retry-After asks. A server error pauses 5 s,
 *   15 s, 30 s, 1 min, then every 2 min, and the next try sends one event at a time until one goes through, so an event
 *   the server chokes on never holds the others back (it is only ever sent alone again). A refusal of a whole request
 *   (400 invalid_json …) is narrowed down to the event it is about. The link died (404 unknown_link - the organiser
 *   made a new one): nothing is sent, nothing is lost, until retryNow().
 * - One tab sends at a time (Web Locks, a localStorage lease where they are missing - official_results_outbox.js);
 *   every tab reads the shared store, a tab without the lock tries again shortly.
 * - It retries by itself (injected timers); hasUnsent() feeds the page's beforeunload warning.
 *
 * Storage, the network, the clock, the timers and the lock are injected - pinned by tests/online-outbox-harness.mjs.
 */

import { createIndexedDbStorage, createMemoryStorage, createTabLock } from '../official_results_outbox.js';

export { createMemoryStorage, createTabLock };

export const EVENTS_DATABASE = 'msp-online-events';
export const EVENTS_LOCK = 'msp-online-events';
export const BATCH_SIZE = 50;
// Offline: 1 s, 2 s, 4 s … at most 30 s between attempts
export const OFFLINE_BACKOFF_MS = [1000, 2000, 4000, 8000, 15000, 30000];
// The server fails: 5 s, 15 s, 30 s, 1 min, then every 2 min
export const SERVER_BACKOFF_MS = [5000, 15000, 30000, 60000, 120000];
// The server is busy or something in between blocks it (no verdict on any event) and says nothing about how long
export const BUSY_BACKOFF_MS = [5000, 15000, 30000, 60000, 120000];
// A Retry-After longer than this is not waited for in full
export const MAX_RETRY_AFTER_MS = 15 * 60 * 1000;
// Another tab held the lock: this tab looks again after this long (that tab may not have seen our events)
export const LOCK_RETRY_MS = 2000;

/**
 * The step of a growing pause: attempt 1 → steps[0], … the last step from then on.
 */
export function backoff(steps, attempt) {
    return steps[Math.min(Math.max(attempt, 1), steps.length) - 1];
}

function clone(value) {
    return value === undefined ? undefined : JSON.parse(JSON.stringify(value));
}

/**
 * IndexedDB as official_results_outbox.js opens it, under another database name - the participant page's stores
 * never share a database with the referees' outbox. Null where IndexedDB can not be opened.
 *
 * @param {string} database
 * @param {IDBFactory|null} [factory]
 */
export function createNamedIndexedDbStorage(database, factory = globalThis.indexedDB) {
    if (!factory) {
        return Promise.resolve(null);
    }

    return createIndexedDbStorage({ open: (name, version) => factory.open(database, version) });
}

/**
 * The events' store on the device (null where IndexedDB is missing - then createMemoryStorage()).
 */
export function createEventsStorage(factory = globalThis.indexedDB) {
    return createNamedIndexedDbStorage(EVENTS_DATABASE, factory);
}

/**
 * A UUIDv7 for an event (RFC 9562: 48 bits of Unix milliseconds, version 7, 74 random bits) - the event's id and the
 * server's primary key.
 *
 * @param {number} [ms]      the moment (the server's time from clock_sync.js keeps ids in event order)
 * @param {function(Uint8Array): void} [fillRandom]
 */
export function uuidv7(ms = Date.now(), fillRandom = (bytes) => globalThis.crypto.getRandomValues(bytes)) {
    const bytes = new Uint8Array(16);
    fillRandom(bytes);
    let time = Math.max(0, Math.floor(Number.isFinite(ms) ? ms : Date.now()));

    for (let index = 5; index >= 0; index--) {
        bytes[index] = time % 256;
        time = Math.floor(time / 256);
    }

    bytes[6] = (bytes[6] & 0x0f) | 0x70;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    const hex = Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');

    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

/**
 * A `beforeunload` listener that asks the browser to warn while something is unsent:
 * `addEventListener('beforeunload', createBeforeUnloadHandler(() => events.hasUnsent() || media.hasUnsent()))`.
 *
 * @param {function(): boolean} hasUnsent
 */
export function createBeforeUnloadHandler(hasUnsent) {
    return (event) => {
        if (!hasUnsent()) {
            return undefined;
        }

        event.preventDefault();
        // Older browsers read the text (and show their own)
        event.returnValue = '';

        return '';
    };
}

/**
 * What a send resolved to, read like official_results_api.js answers it: {kind: 'ok', data: {acks, refused}},
 * {kind: 'offline'}, {kind: 'server', status, retryAfter, busy}, {kind: 'client' | 'forbidden', status, data},
 * {kind: 'auth'} (a page instead of our JSON - a captive portal: like offline). A bare `{acks, refused}` is an ok.
 */
export function readAnswer(answer) {
    if (answer === null || answer === undefined) {
        return { kind: 'offline' };
    }

    if (typeof answer.kind === 'string') {
        return answer;
    }

    if (Array.isArray(answer.acks) || Array.isArray(answer.refused)) {
        return { kind: 'ok', status: 200, data: answer };
    }

    return { kind: 'server', status: null, retryAfter: null, busy: false };
}

/**
 * @typedef {object} OutboxEvent  what the page enqueues and the server receives (implementation-plan.md §3.1)
 * @property {string} id          UUIDv7 (uuidv7())
 * @property {string} type
 * @property {string} [roundId]
 * @property {number} [clientMs]
 * @property {number} [perfMs]
 * @property {number|null} [offsetMs]
 * @property {number|null} [rttMs]
 * @property {object} [data]
 */

/**
 * @typedef {object} EventItem    what the store keeps
 * @property {string} id
 * @property {string|null} scope  whose events (the participant) - another scope's are never sent by this page
 * @property {number} seq
 * @property {number} createdAt
 * @property {OutboxEvent} event
 * @property {'pending'|'refused'} state
 * @property {number} attempts    failed attempts of this event alone (it is only ever sent alone from then on)
 * @property {number} nextAttemptAt
 * @property {string|null} reason
 * @property {number|null} refusedAt
 */

export class EventOutbox {
    /**
     * @param {object} options
     * @param {object} options.storage   createEventsStorage() / createMemoryStorage()
     * @param {function(OutboxEvent[]): Promise<object>} options.send  POST /api/compete/{token}/events → the answer
     * @param {string|null} [options.scope]
     * @param {function(): number} [options.now]
     * @param {function(function(): Promise): Promise<boolean>} [options.lock]  createTabLock(EVENTS_LOCK)
     * @param {function(function(), number): *} [options.schedule]
     * @param {function(*): void} [options.cancel]
     * @param {function(): void} [options.onChange]
     * @param {number} [options.batchSize]
     * @param {boolean} [options.autoFlush]  sends after enqueue() and retries by itself (false: only flush() sends)
     */
    constructor({
        storage,
        send,
        scope = null,
        now = () => Date.now(),
        lock = null,
        schedule = (task, ms) => setTimeout(task, ms),
        cancel = (timer) => clearTimeout(timer),
        onChange = () => {},
        batchSize = BATCH_SIZE,
        autoFlush = true,
    }) {
        this.storage = storage;
        this.send = send;
        this.scope = scope;
        this.now = now;
        this.lock = lock ?? (async (task) => {
            await task();

            return true;
        });
        this.schedule = schedule;
        this.cancel = cancel;
        this.onChange = onChange;
        this.batchSize = batchSize;
        this.autoFlush = autoFlush;

        /** @type {EventItem[]} */
        this.items = [];
        // Being written to the store - unsent, not sendable yet
        this.writing = new Set();
        this.inFlight = new Set();
        // Items the store refused to keep - in memory, sent anyway
        this.unpersisted = new Map();
        this.storageFailed = false;
        this.lastSeq = 0;
        this.queue = Promise.resolve();

        this.flushing = null;
        this.flushRequested = false;
        this.timer = null;
        this.closed = false;

        // null | 'unknown_link' - nothing is sent until retryNow()
        this.blocked = null;
        this.offline = false;
        this.offlineAttempts = 0;
        this.busy = false;
        this.busyAttempts = 0;
        this.serverFailures = 0;
        // Everything waits until then (offline, busy, failing)
        this.retryAt = 0;
        // One event per request until one goes through
        this.isolate = false;
    }

    serial(operation) {
        const run = this.queue.then(operation, operation);
        this.queue = run.catch(() => {});

        return run;
    }

    changed() {
        try {
            this.onChange();
        } catch (e) {
            // The page's listener must never stop the outbox
        }
    }

    /**
     * Reads the store again (at start, and before every round of sending - another tab may have sent or added events).
     */
    load() {
        return this.serial(async () => {
            let stored = null;

            try {
                stored = await this.storage.getAll();
            } catch (e) {
                this.storageFailed = true;
            }

            if (stored !== null) {
                const mine = stored.filter((item) => this.scope === null || item.scope === this.scope);
                const ids = new Set(mine.map((item) => item.id));
                this.items = [...mine, ...[...this.unpersisted.values()].filter((item) => !ids.has(item.id))]
                    .sort((a, b) => a.seq - b.seq);
                this.lastSeq = Math.max(this.lastSeq, ...this.items.map((item) => item.seq), 0);
            }

            this.changed();
        });
    }

    /**
     * Stores an event, then (autoFlush) sends it. Resolves once it is stored - or kept in memory when the store
     * refused it (`persisted: false`; it is sent all the same). The same id twice is stored once.
     *
     * @param {OutboxEvent} event
     * @returns {Promise<{id: string, persisted: boolean, duplicate: boolean}>}
     */
    async enqueue(event) {
        if (event === null || typeof event !== 'object' || typeof event.id !== 'string' || event.id === '') {
            throw new TypeError('An event needs its id');
        }

        const known = this.items.find((item) => item.id === event.id);

        if (known !== undefined || this.writing.has(event.id)) {
            return { id: event.id, persisted: known !== undefined && !this.unpersisted.has(event.id), duplicate: true };
        }

        const item = {
            id: event.id,
            scope: this.scope,
            seq: this.nextSeq(),
            createdAt: this.now(),
            event: clone(event),
            state: 'pending',
            attempts: 0,
            nextAttemptAt: 0,
            reason: null,
            refusedAt: null,
        };

        this.writing.add(item.id);
        this.changed();
        let persisted = true;

        await this.serial(async () => {
            try {
                await this.storage.put(item);
                this.unpersisted.delete(item.id);
            } catch (e) {
                persisted = false;
                this.storageFailed = true;
                this.unpersisted.set(item.id, item);
            }

            if (!this.items.some((candidate) => candidate.id === item.id)) {
                this.items.push(item);
                this.items.sort((a, b) => a.seq - b.seq);
            }
        });

        this.writing.delete(item.id);
        this.changed();

        if (this.autoFlush) {
            this.flush();
        }

        return { id: item.id, persisted, duplicate: false };
    }

    /**
     * Sends whatever may go, batch after batch, until nothing is ready or the network says stop. A call while one runs
     * makes it go round once more and resolves with it. Never rejects.
     */
    flush() {
        if (this.closed) {
            return Promise.resolve();
        }

        if (this.flushing !== null) {
            this.flushRequested = true;

            return this.flushing;
        }

        this.flushing = this.runFlush().then(() => {
            this.flushing = null;

            if (this.flushRequested && !this.closed) {
                this.flushRequested = false;

                return this.flush();
            }

            return undefined;
        });

        return this.flushing;
    }

    /**
     * Sends again now: a pause, a dead link's stop and every event's own wait are skipped.
     */
    async retryNow() {
        this.blocked = null;
        this.retryAt = 0;
        this.isolate = false;

        await this.serial(async () => {
            for (const item of this.items) {
                if (item.state === 'pending' && item.nextAttemptAt > 0) {
                    item.nextAttemptAt = 0;
                    await this.write(item);
                }
            }
        });

        this.changed();

        return this.flush();
    }

    /**
     * The browser is back online: an offline pause ends now.
     */
    online() {
        if (this.offline) {
            this.retryAt = 0;
        }

        return this.flush();
    }

    /**
     * Refused events back into the queue (a fix arrived) - all, or those `predicate` picks.
     */
    async requeueRefused(predicate = () => true) {
        await this.serial(async () => {
            for (const item of this.items) {
                if (item.state === 'refused' && predicate(this.refusedView(item))) {
                    item.state = 'pending';
                    item.reason = null;
                    item.refusedAt = null;
                    item.attempts = 0;
                    item.nextAttemptAt = 0;
                    await this.write(item);
                }
            }
        });

        this.changed();

        return this.flush();
    }

    /**
     * Stops sending and every timer (the page is going). Nothing stored is touched.
     */
    close() {
        this.closed = true;
        this.cancelTimer();
    }

    /**
     * Events not acknowledged yet (being stored, waiting, being sent) - refused ones are not counted.
     */
    pendingCount() {
        return this.items.filter((item) => item.state === 'pending').length + this.writing.size;
    }

    hasUnsent() {
        return this.pendingCount() > 0;
    }

    /**
     * The event is still on its way: being stored, waiting or being sent - false once the server acknowledged it (or
     * refused it, or it never was here).
     */
    isPending(id) {
        return this.writing.has(id) || this.items.some((item) => item.id === id && item.state === 'pending');
    }

    /**
     * @returns {{id: string, reason: string|null, refusedAt: number|null, event: OutboxEvent}[]}
     */
    refusedEvents() {
        return this.items.filter((item) => item.state === 'refused').map((item) => this.refusedView(item));
    }

    /**
     * Events another scope (another participant on this device) left in the store - never sent by this page.
     */
    async foreignCount() {
        try {
            const all = await this.storage.getAll();

            return all.filter((item) => this.scope !== null && item.scope !== this.scope).length;
        } catch (e) {
            return 0;
        }
    }

    status() {
        const now = this.now();

        return {
            pending: this.pendingCount(),
            refused: this.items.filter((item) => item.state === 'refused').length,
            sending: this.inFlight.size > 0,
            offline: this.offline,
            busy: this.busy,
            blocked: this.blocked,
            isolating: this.isolate,
            waitingUntil: this.retryAt > now ? this.retryAt : null,
            storageFailed: this.storageFailed,
        };
    }

    // ---- internals

    refusedView(item) {
        return { id: item.id, reason: item.reason, refusedAt: item.refusedAt, event: clone(item.event) };
    }

    nextSeq() {
        const now = this.now() * 1000;
        this.lastSeq = Math.max(now, this.lastSeq + 1);

        return this.lastSeq;
    }

    async write(item) {
        try {
            await this.storage.put(item);
            this.unpersisted.delete(item.id);
        } catch (e) {
            this.storageFailed = true;
            this.unpersisted.set(item.id, item);
        }
    }

    async erase(id) {
        this.unpersisted.delete(id);
        this.items = this.items.filter((item) => item.id !== id);

        try {
            await this.storage.delete(id);
        } catch (e) {
            this.storageFailed = true;
        }
    }

    async runFlush() {
        let lockRetry = false;

        try {
            do {
                this.flushRequested = false;
                const acquired = await this.lock(() => this.flushLoop());

                if (!acquired) {
                    // Another tab sends - it may not have read our events yet
                    lockRetry = true;
                    break;
                }
            } while (this.flushRequested && !this.closed);
        } catch (e) {
            // The store or the lock failed - tried again shortly
            lockRetry = true;
        }

        this.changed();
        this.scheduleNext(lockRetry);
    }

    async flushLoop() {
        await this.load();

        while (this.blocked === null && !this.closed) {
            const now = this.now();

            if (this.retryAt > now) {
                break;
            }

            const batch = this.nextBatch(now);

            if (batch.length === 0) {
                break;
            }

            batch.forEach((item) => this.inFlight.add(item.id));
            this.changed();
            let answer;

            try {
                answer = readAnswer(await this.send(batch.map((item) => clone(item.event))));
            } catch (e) {
                answer = { kind: 'offline' };
            } finally {
                batch.forEach((item) => this.inFlight.delete(item.id));
            }

            const goOn = await this.serial(() => this.settle(batch.map((item) => item.id), answer));
            this.changed();

            if (!goOn) {
                break;
            }
        }
    }

    /**
     * The next request: up to batchSize ready events that never failed alone (the first of them alone while
     * isolating); only when none is left, the oldest event that failed alone - alone. An event the server keeps
     * choking on is never ahead of the others.
     */
    nextBatch(now) {
        const ready = this.items.filter((item) => item.state === 'pending' && item.nextAttemptAt <= now && !this.inFlight.has(item.id));

        if (ready.length === 0) {
            return [];
        }

        const fresh = ready.filter((item) => item.attempts === 0);

        if (fresh.length === 0) {
            return [ready[0]];
        }

        return this.isolate ? [fresh[0]] : fresh.slice(0, this.batchSize);
    }

    /**
     * @returns {Promise<boolean>} send on (false: pause - offline, busy, failing, blocked)
     */
    async settle(ids, answer) {
        const now = this.now();
        const batch = ids.map((id) => this.items.find((item) => item.id === id)).filter((item) => item !== undefined);

        if (answer.kind === 'offline' || answer.kind === 'auth') {
            this.offline = true;
            this.offlineAttempts += 1;
            this.retryAt = now + backoff(OFFLINE_BACKOFF_MS, this.offlineAttempts);

            return false;
        }

        this.offline = false;
        this.offlineAttempts = 0;

        if (answer.kind === 'server' && answer.busy === true) {
            this.busy = true;
            this.busyAttempts += 1;
            this.retryAt = now + (typeof answer.retryAfter === 'number' && answer.retryAfter > 0
                ? Math.min(answer.retryAfter, MAX_RETRY_AFTER_MS)
                : backoff(BUSY_BACKOFF_MS, this.busyAttempts));

            return false;
        }

        this.busy = false;
        this.busyAttempts = 0;

        if (answer.kind === 'client' || answer.kind === 'forbidden') {
            const error = typeof answer.data?.error === 'string' ? answer.data.error : null;

            if (error === 'unknown_link') {
                this.blocked = 'unknown_link';

                return false;
            }

            if (batch.length > 1) {
                // Which event is it about? One at a time from here
                this.isolate = true;

                return true;
            }

            if (batch.length === 1) {
                await this.refuse(batch[0], error ?? `http_${answer.status ?? 'error'}`, now);
            }

            this.isolate = false;

            return true;
        }

        if (answer.kind !== 'ok') {
            // A server error (or an answer nobody understands): everything pauses, then one event at a time
            this.serverFailures += 1;
            const retryAfter = typeof answer.retryAfter === 'number' && answer.retryAfter > 0 ? Math.min(answer.retryAfter, MAX_RETRY_AFTER_MS) : 0;
            this.retryAt = now + Math.max(backoff(SERVER_BACKOFF_MS, this.serverFailures), retryAfter);

            if (batch.length > 1) {
                this.isolate = true;
            } else if (batch.length === 1) {
                const item = batch[0];
                item.attempts += 1;
                item.nextAttemptAt = now + backoff(SERVER_BACKOFF_MS, item.attempts);
                await this.write(item);
            }

            return false;
        }

        this.serverFailures = 0;
        this.isolate = false;
        const acks = new Set((Array.isArray(answer.data?.acks) ? answer.data.acks : []).map((id) => String(id).toLowerCase()));
        const refused = new Map((Array.isArray(answer.data?.refused) ? answer.data.refused : [])
            .filter((entry) => entry !== null && typeof entry === 'object')
            .map((entry) => [String(entry.id).toLowerCase(), typeof entry.reason === 'string' ? entry.reason : 'refused']));

        for (const item of batch) {
            const key = item.id.toLowerCase();

            if (acks.has(key)) {
                await this.erase(item.id);
            } else if (refused.has(key)) {
                await this.refuse(item, refused.get(key), now);
            } else {
                // The server answered without a word on it: again later, alone
                item.attempts += 1;
                item.nextAttemptAt = now + backoff(SERVER_BACKOFF_MS, item.attempts);
                await this.write(item);
            }
        }

        return true;
    }

    async refuse(item, reason, now) {
        item.state = 'refused';
        item.reason = reason;
        item.refusedAt = now;
        item.nextAttemptAt = 0;
        await this.write(item);
    }

    cancelTimer() {
        if (this.timer !== null) {
            this.cancel(this.timer);
            this.timer = null;
        }
    }

    /**
     * The next try by itself: when the pause ends or the first waiting event may go again; shortly when another tab
     * held the lock.
     */
    scheduleNext(lockRetry) {
        this.cancelTimer();

        if (this.closed || !this.autoFlush || this.blocked !== null) {
            return;
        }

        const pending = this.items.filter((item) => item.state === 'pending');

        if (pending.length === 0) {
            return;
        }

        const now = this.now();
        const at = lockRetry
            ? now + LOCK_RETRY_MS
            : Math.max(this.retryAt, Math.min(...pending.map((item) => item.nextAttemptAt)));

        this.timer = this.schedule(() => {
            this.timer = null;
            this.flush();
        }, Math.max(0, at - now));
    }
}

/**
 * An event outbox over the device's store (IndexedDB, or memory where it is missing) and the tab lock, loaded.
 *
 * @param {object} options     EventOutbox's options, without `storage` / `lock`
 * @param {IDBFactory|null} [options.indexedDB]
 * @returns {Promise<EventOutbox>}
 */
export async function openEventOutbox({ indexedDB = globalThis.indexedDB, ...options }) {
    const storage = (await createEventsStorage(indexedDB)) ?? createMemoryStorage();
    const outbox = new EventOutbox({ ...options, storage, lock: options.lock ?? createTabLock(EVENTS_LOCK) });
    await outbox.load();

    return outbox;
}
