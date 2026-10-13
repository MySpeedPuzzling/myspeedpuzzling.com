/**
 * The participant phone's evidence uploads (docs/features/online-competitions/README.md §5.6, §5.11): photos and finish
 * bursts go from the phone straight to object storage through presigned URLs - never through PHP - and the phone is
 * the first buffer while the storage or the network is down. Timing never waits for any of it.
 *
 * - enqueue({kind, blob, takenMs, eventId, priority}) stores the blob on the device first (IndexedDB, its own
 *   database); then, per item: presign({kind, takenMs, bytes, contentType}) → {mediaId, url, method, headers,
 *   expiresAtMs} → put(url, headers, blob) → reportUploaded({mediaId, bytes, manifest}) (the page turns it into a
 *   `media.uploaded` event) → gone from the device. An uploaded blob is dropped at once; only the report may wait.
 * - Critical items (start, candidate, button, burst, end, empty mat) go first, oldest first; periodic photos after them,
 *   newest first (the control room's thumbnail is the latest one).
 * - A URL about to end (by the presign answer's own serverNowMs - the phone's clock may be off) is presigned again
 *   before the upload; a store refusing the URL (400 / 403 / other 4xx) gets a fresh one at once; a store failing
 *   (5xx, 408, 429) is tried again after a pause - with a fresh URL from the second try on, so the server may send it
 *   to its other store; the next presign carries the failure (`previousFailure`) for the server's circuit breaker.
 * - Offline 1 s → 30 s, a busy server (429) as long as its Retry-After asks, a failing server 5 s → 2 min; a refusal
 *   of the item itself (media_too_large, media_kind_unknown, round_not_online) sets it aside with the reason
 *   (refusedItems()); the link died (unknown_link): nothing is sent until retryNow().
 * - Quota pressure (estimate() = navigator.storage.estimate() over QUOTA_SHARE of the quota, or this outbox's own
 *   budget `maxBytes`): periodic photos are thinned - the one closest to the photo before it goes first, so what stays
 *   is spread evenly; the newest stays - and critical items are never dropped: kept in memory and uploaded anyway when
 *   the store refuses them.
 * - One tab uploads at a time (official_results_outbox.js createTabLock); it retries by itself (injected timers).
 *
 * Storage, the requests, the clock, the timers, the quota and the lock are injected - pinned by
 * tests/online-outbox-harness.mjs.
 */

import { newClientId, retryAfterMs } from '../official_results_api.js';
import {
    backoff,
    BUSY_BACKOFF_MS,
    createNamedIndexedDbStorage,
    createTabLock,
    LOCK_RETRY_MS,
    MAX_RETRY_AFTER_MS,
    OFFLINE_BACKOFF_MS,
    SERVER_BACKOFF_MS,
} from './event_outbox.js';

export const MEDIA_DATABASE = 'msp-online-media';
export const MEDIA_LOCK = 'msp-online-media';
// OnlineMediaKind (contract §2)
export const MEDIA_KINDS = ['periodic', 'empty_mat', 'start', 'candidate', 'button', 'burst', 'end'];
export const CRITICAL = 'critical';
export const PERIODIC = 'periodic';
// A URL with less than this left is presigned again before the upload
export const EXPIRY_MARGIN_MS = 15000;
// Fresh URLs refused by the store this many times in a row: the item pauses like a failing server
export const MAX_PRESIGNS_IN_A_ROW = 5;
// Share of the origin's quota the device's stores may fill before periodic photos are thinned
export const QUOTA_SHARE = 0.8;
// This outbox's own budget of waiting bytes (a 90-minute round is ~20 MB; an outage of hours fits easily)
export const MAX_BYTES = 200 * 1024 * 1024;
export const BURST_CONTENT_TYPE = 'application/octet-stream';
export const PHOTO_CONTENT_TYPE = 'image/jpeg';

// Headers a browser sets itself - never copied from the presigned ones (Content-Length comes from the blob)
const FORBIDDEN_HEADERS = ['content-length', 'host', 'connection', 'keep-alive', 'transfer-encoding', 'expect', 'date', 'origin', 'referer'];

/**
 * The device's store for media (null where IndexedDB is missing - then createMediaMemoryStorage()). Blobs are stored as
 * they are (structured clone).
 */
export function createMediaStorage(factory = globalThis.indexedDB) {
    return createNamedIndexedDbStorage(MEDIA_DATABASE, factory);
}

/**
 * Items in memory - tests, and browsers without a working IndexedDB. Blobs are kept by reference (they never change),
 * everything else copied.
 */
export function createMediaMemoryStorage(initial = []) {
    const copy = (item) => {
        const { blob = null, ...rest } = item;

        return { ...JSON.parse(JSON.stringify(rest)), blob };
    };
    const items = new Map(initial.map((item) => [item.id, copy(item)]));

    return {
        persistent: false,
        async getAll() {
            return [...items.values()].map(copy);
        },
        async get(id) {
            return items.has(id) ? copy(items.get(id)) : null;
        },
        async put(item) {
            items.set(item.id, copy(item));
        },
        async delete(id) {
            items.delete(id);
        },
    };
}

/**
 * The upload itself - the default `put`: the presigned request with the blob as its body, no cookies, no referrer.
 * Headers the browser sets itself (Content-Length - signed, and equal to the blob's size) are left to it.
 *
 * @returns {Promise<Response>} rejects when the request never reached the store
 */
export function putToStore(url, headers, blob, { method = 'PUT', fetch: request = (...args) => globalThis.fetch(...args) } = {}) {
    const sent = {};

    for (const [name, value] of Object.entries(headers ?? {})) {
        if (!FORBIDDEN_HEADERS.includes(name.toLowerCase())) {
            sent[name] = String(value);
        }
    }

    return request(url, {
        method,
        headers: sent,
        body: blob,
        credentials: 'omit',
        cache: 'no-store',
        mode: 'cors',
        referrerPolicy: 'no-referrer',
    });
}

/**
 * What a presign resolved to: an official_results_api.js answer ({kind: 'ok', data}, offline, server, client …) or
 * the presigned upload itself.
 */
function readPresignAnswer(answer) {
    if (answer === null || answer === undefined) {
        return { kind: 'offline' };
    }

    if (typeof answer.kind === 'string') {
        return answer;
    }

    if (typeof answer.url === 'string') {
        return { kind: 'ok', status: 200, data: answer };
    }

    return { kind: 'server', status: null, retryAfter: null, busy: false };
}

function usablePresign(data) {
    return data !== null
        && typeof data === 'object'
        && typeof data.url === 'string'
        && data.url !== ''
        && (typeof data.mediaId === 'string' || typeof data.mediaId === 'number');
}

function retryAfterOf(response) {
    try {
        const value = retryAfterMs(response?.headers?.get?.('Retry-After') ?? null);

        return value === null ? 0 : Math.min(value, MAX_RETRY_AFTER_MS);
    } catch (e) {
        return 0;
    }
}

function clone(value) {
    return value === undefined || value === null ? null : JSON.parse(JSON.stringify(value));
}

/**
 * @typedef {object} MediaItem
 * @property {string} id                 the device's own id (the server's is `mediaId`)
 * @property {string|null} scope
 * @property {number} seq
 * @property {number} createdAt
 * @property {string} kind               MEDIA_KINDS
 * @property {'critical'|'periodic'} priority
 * @property {number} takenMs
 * @property {string|null} eventId       the event it belongs to (relatedEventId)
 * @property {string|null} roundId
 * @property {string} contentType
 * @property {number} bytes
 * @property {object[]|null} manifest    a burst's frames [{offset, length, captureMs}]
 * @property {Blob|null} blob            null once uploaded
 * @property {'queued'|'presigned'|'uploaded'} stage
 * @property {string|null} mediaId
 * @property {string|null} store
 * @property {string|null} url
 * @property {string} method
 * @property {object|null} headers
 * @property {number|null} validUntil    the URL's end on this outbox's clock (null: unknown)
 * @property {'pending'|'refused'} state
 * @property {string|null} reason
 * @property {number|null} refusedAt
 * @property {number} attempts
 * @property {number} nextAttemptAt
 * @property {number} presignsInARow
 * @property {{status: number, store: string|null, mediaId: string|null}|null} lastFailure
 */

export class MediaOutbox {
    /**
     * @param {object} options
     * @param {object} options.storage            createMediaStorage() / createMediaMemoryStorage()
     * @param {function(object): Promise<object>} options.presign   POST /api/compete/{token}/media → the answer
     * @param {function(string, object, Blob, {method: string}): Promise<{status: number}>} options.put  putToStore
     * @param {function(object): Promise<*>} options.reportUploaded  the page enqueues `media.uploaded`
     * @param {string|null} [options.scope]
     * @param {function(): number} [options.now]
     * @param {function(function(): Promise): Promise<boolean>} [options.lock]  createTabLock(MEDIA_LOCK)
     * @param {function(function(), number): *} [options.schedule]
     * @param {function(*): void} [options.cancel]
     * @param {function(): Promise<{usage: number, quota: number}>|null} [options.estimate]  navigator.storage.estimate
     * @param {number} [options.maxBytes]
     * @param {number} [options.quotaShare]
     * @param {function(): string} [options.newId]
     * @param {function(): void} [options.onChange]
     * @param {boolean} [options.autoFlush]
     */
    constructor({
        storage,
        presign,
        put = putToStore,
        reportUploaded,
        scope = null,
        now = () => Date.now(),
        lock = null,
        schedule = (task, ms) => setTimeout(task, ms),
        cancel = (timer) => clearTimeout(timer),
        estimate = () => globalThis.navigator?.storage?.estimate?.() ?? null,
        maxBytes = MAX_BYTES,
        quotaShare = QUOTA_SHARE,
        newId = newClientId,
        onChange = () => {},
        autoFlush = true,
    }) {
        this.storage = storage;
        this.presign = presign;
        this.put = put;
        this.reportUploaded = reportUploaded;
        this.scope = scope;
        this.now = now;
        this.lock = lock ?? (async (task) => {
            await task();

            return true;
        });
        this.schedule = schedule;
        this.cancel = cancel;
        this.estimate = estimate;
        this.maxBytes = maxBytes;
        this.quotaShare = quotaShare;
        this.newId = newId;
        this.onChange = onChange;
        this.autoFlush = autoFlush;

        /** @type {MediaItem[]} */
        this.items = [];
        this.writing = new Set();
        this.unpersisted = new Map();
        this.storageFailed = false;
        this.lastSeq = 0;
        this.queue = Promise.resolve();
        // The item being uploaded right now - never thinned
        this.active = null;

        this.flushing = null;
        this.flushRequested = false;
        this.timer = null;
        this.closed = false;

        this.blocked = null;
        this.offline = false;
        this.offlineAttempts = 0;
        this.busy = false;
        this.busyAttempts = 0;
        this.serverFailures = 0;
        this.storeFailures = 0;
        this.retryAt = 0;

        this.thinnedCount = 0;
        this.uploadedCount = 0;
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
            // The page's listener must never stop an upload
        }
    }

    /**
     * Reads the store again (at start, and before every round of uploading).
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
     * Stores a photo or a burst, then (autoFlush) uploads it. A periodic photo may be dropped when there is no room for
     * it even after thinning (`dropped: true`); a critical item is always kept - in memory when the store refuses it.
     *
     * @param {object} media
     * @param {string} media.kind               MEDIA_KINDS
     * @param {Blob} media.blob
     * @param {number} media.takenMs            server time
     * @param {string|null} [media.eventId]     the event it belongs to (a Finished tap's)
     * @param {'critical'|'periodic'|null} [media.priority]  default: periodic for `periodic`, critical for the rest
     * @param {object[]|null} [media.manifest]  a burst's frames (frame_buffer.js)
     * @param {string|null} [media.contentType] default: the blob's, else image/jpeg (a burst: application/octet-stream)
     * @param {string|null} [media.roundId]
     * @param {number} [media.delayMs]          not before this long (spreads the uploads at the end of a round)
     * @returns {Promise<{id: string, persisted: boolean, dropped: boolean}>}
     */
    async enqueue({ kind, blob, takenMs, eventId = null, priority = null, manifest = null, contentType = null, roundId = null, delayMs = 0 }) {
        if (!MEDIA_KINDS.includes(kind)) {
            throw new TypeError(`Unknown media kind ${kind}`);
        }

        if (blob === null || typeof blob !== 'object' || !Number.isFinite(blob.size) || !Number.isFinite(takenMs)) {
            throw new TypeError('A media item needs its blob and when it was taken');
        }

        const level = priority === CRITICAL || priority === PERIODIC ? priority : (kind === 'periodic' ? PERIODIC : CRITICAL);
        const now = this.now();
        const item = {
            id: this.newId(),
            scope: this.scope,
            seq: this.nextSeq(),
            createdAt: now,
            kind,
            priority: level,
            takenMs,
            eventId,
            roundId,
            contentType: contentType ?? (kind === 'burst' ? BURST_CONTENT_TYPE : (blob.type || PHOTO_CONTENT_TYPE)),
            bytes: blob.size,
            manifest: clone(manifest),
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
            nextAttemptAt: Number.isFinite(delayMs) && delayMs > 0 ? now + delayMs : 0,
            presignsInARow: 0,
            lastFailure: null,
        };

        this.writing.add(item.id);
        this.changed();
        let persisted = false;
        let dropped = false;

        await this.serial(async () => {
            if (!(await this.makeRoom(item))) {
                dropped = true;
                this.thinnedCount += 1;

                return;
            }

            persisted = await this.tryPut(item);

            if (!persisted) {
                // The store refused it (full, broken): room among the periodic photos, then once more
                await this.thin(item.bytes, item);
                persisted = await this.tryPut(item);
            }

            if (!persisted) {
                this.storageFailed = true;

                if (item.priority === PERIODIC) {
                    dropped = true;
                    this.thinnedCount += 1;

                    return;
                }

                // Critical: never dropped - kept in memory, uploaded all the same
                this.unpersisted.set(item.id, item);
            }

            this.items.push(item);
            this.items.sort((a, b) => a.seq - b.seq);
        });

        this.writing.delete(item.id);
        this.changed();

        if (!dropped && this.autoFlush) {
            this.flush();
        }

        return { id: item.id, persisted, dropped };
    }

    /**
     * Uploads whatever may go, one item after another, until nothing is ready or the network says stop. A call while
     * one runs makes it go round once more and resolves with it. Never rejects.
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
     * Uploads again now: every pause and a dead link's stop are skipped.
     */
    async retryNow() {
        this.blocked = null;
        this.retryAt = 0;

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
     * Drops refused items (with their blobs) - all, or those `predicate` picks.
     *
     * @returns {Promise<number>} how many went
     */
    async discardRefused(predicate = () => true) {
        const gone = this.items.filter((item) => item.state === 'refused' && predicate(this.refusedView(item)));

        await this.serial(async () => {
            for (const item of gone) {
                await this.erase(item.id);
            }
        });

        this.changed();

        return gone.length;
    }

    close() {
        this.closed = true;
        this.cancelTimer();
    }

    /**
     * Items not reported yet (being stored, waiting, uploading) - refused ones are not counted.
     */
    pendingCount() {
        return this.items.filter((item) => item.state === 'pending').length + this.writing.size;
    }

    hasUnsent() {
        return this.pendingCount() > 0;
    }

    /**
     * @returns {{id: string, kind: string, takenMs: number, eventId: string|null, reason: string|null, refusedAt: number|null}[]}
     */
    refusedItems() {
        return this.items.filter((item) => item.state === 'refused').map((item) => this.refusedView(item));
    }

    /**
     * Bytes of blobs waiting on the device.
     */
    queuedBytes() {
        return this.items.reduce((sum, item) => sum + (item.blob === null ? 0 : item.bytes), 0);
    }

    status() {
        const now = this.now();
        const active = this.items.find((item) => item.id === this.active);

        return {
            pending: this.pendingCount(),
            refused: this.items.filter((item) => item.state === 'refused').length,
            uploading: active === undefined ? null : active.kind,
            offline: this.offline,
            busy: this.busy,
            blocked: this.blocked,
            waitingUntil: this.retryAt > now ? this.retryAt : null,
            queuedBytes: this.queuedBytes(),
            thinned: this.thinnedCount,
            uploaded: this.uploadedCount,
            storageFailed: this.storageFailed,
        };
    }

    // ---- internals

    refusedView(item) {
        return { id: item.id, kind: item.kind, takenMs: item.takenMs, eventId: item.eventId, reason: item.reason, refusedAt: item.refusedAt };
    }

    nextSeq() {
        const now = this.now() * 1000;
        this.lastSeq = Math.max(now, this.lastSeq + 1);

        return this.lastSeq;
    }

    async tryPut(item) {
        try {
            await this.storage.put(item);
            this.unpersisted.delete(item.id);

            return true;
        } catch (e) {
            return false;
        }
    }

    async write(item) {
        if (!(await this.tryPut(item))) {
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

    async readEstimate() {
        if (typeof this.estimate !== 'function') {
            return null;
        }

        try {
            const value = await this.estimate();

            if (value && Number.isFinite(value.usage) && Number.isFinite(value.quota) && value.quota > 0) {
                return value;
            }
        } catch (e) {
            // No estimate - the own budget alone
        }

        return null;
    }

    /**
     * Thins periodic photos until `item` fits the budget and the quota share.
     *
     * @returns {Promise<boolean>} it may be stored (a critical item always may)
     */
    async makeRoom(item) {
        let need = this.queuedBytes() + item.bytes - this.maxBytes;
        const estimate = await this.readEstimate();

        if (estimate !== null) {
            need = Math.max(need, estimate.usage + item.bytes - estimate.quota * this.quotaShare);
        }

        if (need <= 0) {
            return true;
        }

        const freed = await this.thin(need, item);

        return freed >= need || item.priority === CRITICAL;
    }

    /**
     * Drops periodic photos until `need` bytes are free: each time the one closest to the photo before it (ties: the
     * older), so what stays is spread evenly; the newest periodic photo and the one being uploaded stay.
     *
     * @returns {Promise<number>} bytes freed
     */
    async thin(need, keep = null) {
        let freed = 0;

        while (freed < need) {
            const periodic = this.items
                .filter((item) => item.priority === PERIODIC && item.blob !== null && item !== keep)
                .sort((a, b) => a.takenMs - b.takenMs || a.seq - b.seq);
            let pick = null;
            let pickGap = Infinity;

            periodic.slice(0, -1).forEach((item, index) => {
                if (item.id === this.active) {
                    return;
                }

                const gap = index === 0 ? Infinity : item.takenMs - periodic[index - 1].takenMs;

                if (pick === null || gap < pickGap) {
                    pick = item;
                    pickGap = gap;
                }
            });

            if (pick === null) {
                break;
            }

            await this.erase(pick.id);
            freed += pick.bytes;
            this.thinnedCount += 1;
        }

        return freed;
    }

    async runFlush() {
        let lockRetry = false;

        try {
            do {
                this.flushRequested = false;
                const acquired = await this.lock(() => this.uploadLoop());

                if (!acquired) {
                    lockRetry = true;
                    break;
                }
            } while (this.flushRequested && !this.closed);
        } catch (e) {
            lockRetry = true;
        }

        this.changed();
        this.scheduleNext(lockRetry);
    }

    async uploadLoop() {
        await this.load();

        while (this.blocked === null && !this.closed) {
            const now = this.now();

            if (this.retryAt > now) {
                break;
            }

            const item = this.nextItem(now);

            if (item === null) {
                break;
            }

            this.active = item.id;
            this.changed();
            let goOn;

            try {
                goOn = await this.process(item);
            } finally {
                this.active = null;
            }

            this.changed();

            if (!goOn) {
                break;
            }
        }
    }

    /**
     * The next item: one with only its report left, then critical ones (oldest first), then periodic photos (newest
     * first) - and items that failed before only after all of those, in the same order, so an item that keeps failing
     * is never ahead of the others.
     */
    nextItem(now) {
        const ready = this.items.filter((item) => item.state === 'pending' && item.nextAttemptAt <= now);

        if (ready.length === 0) {
            return null;
        }

        const rank = (item) => (item.attempts > 0 ? 3 : 0) + (item.stage === 'uploaded' ? 0 : (item.priority === CRITICAL ? 1 : 2));
        ready.sort((a, b) => rank(a) - rank(b)
            || (rank(a) % 3 === 2 ? b.takenMs - a.takenMs : a.takenMs - b.takenMs)
            || a.seq - b.seq);

        return ready[0];
    }

    /**
     * @returns {Promise<boolean>} go on with the next item (false: pause - offline, busy, failing, blocked)
     */
    async process(item) {
        if (item.stage === 'presigned' && item.validUntil !== null && item.validUntil - this.now() < EXPIRY_MARGIN_MS) {
            this.dropPresign(item);
        }

        if (item.stage === 'queued') {
            const outcome = await this.presignItem(item);

            if (outcome !== 'ok') {
                return outcome === 'next';
            }
        }

        if (item.stage === 'presigned') {
            const outcome = await this.upload(item);

            if (outcome !== 'ok') {
                return outcome === 'next';
            }
        }

        if (item.stage === 'uploaded') {
            return this.report(item);
        }

        return true;
    }

    async presignItem(item) {
        let answer;

        try {
            answer = readPresignAnswer(await this.presign({
                kind: item.kind,
                takenMs: item.takenMs,
                bytes: item.bytes,
                contentType: item.contentType,
                eventId: item.eventId,
                roundId: item.roundId,
                itemId: item.id,
                previousFailure: clone(item.lastFailure),
            }));
        } catch (e) {
            answer = { kind: 'offline' };
        }

        const now = this.now();

        if (answer.kind === 'offline' || answer.kind === 'auth') {
            this.goOffline(now);

            return 'stop';
        }

        this.offline = false;
        this.offlineAttempts = 0;

        if (answer.kind === 'server' && answer.busy === true) {
            this.busy = true;
            this.busyAttempts += 1;
            this.retryAt = now + (typeof answer.retryAfter === 'number' && answer.retryAfter > 0
                ? Math.min(answer.retryAfter, MAX_RETRY_AFTER_MS)
                : backoff(BUSY_BACKOFF_MS, this.busyAttempts));

            return 'stop';
        }

        this.busy = false;
        this.busyAttempts = 0;

        if (answer.kind === 'client' || answer.kind === 'forbidden') {
            const error = typeof answer.data?.error === 'string' ? answer.data.error : null;

            if (error === 'unknown_link') {
                this.blocked = 'unknown_link';

                return 'stop';
            }

            // The item itself can not be taken (too large, unknown kind, the round is not online): aside, kept
            item.state = 'refused';
            item.reason = error ?? `http_${answer.status ?? 'error'}`;
            item.refusedAt = now;
            await this.write(item);

            return 'next';
        }

        const data = answer.kind === 'ok' ? answer.data : null;

        if (!usablePresign(data)) {
            // A server error (or an answer without a URL): everything pauses; this item waits its own turn too
            this.serverFailures += 1;
            const retryAfter = typeof answer.retryAfter === 'number' && answer.retryAfter > 0 ? Math.min(answer.retryAfter, MAX_RETRY_AFTER_MS) : 0;
            this.retryAt = now + Math.max(backoff(SERVER_BACKOFF_MS, this.serverFailures), retryAfter);
            item.attempts += 1;
            item.nextAttemptAt = now + backoff(SERVER_BACKOFF_MS, item.attempts);
            await this.write(item);

            return 'stop';
        }

        this.serverFailures = 0;
        item.stage = 'presigned';
        item.mediaId = String(data.mediaId);
        item.store = typeof data.store === 'string' ? data.store : null;
        item.url = data.url;
        item.method = typeof data.method === 'string' && data.method !== '' ? data.method.toUpperCase() : 'PUT';
        item.headers = Object.fromEntries(Object.entries(data.headers ?? {}).map(([name, value]) => [name, String(value)]));
        item.validUntil = null;

        if (Number.isFinite(data.expiresAtMs)) {
            // On this outbox's clock: by the answer's own server time where it has one (the phone's clock may be off)
            item.validUntil = Number.isFinite(data.serverNowMs) ? now + (data.expiresAtMs - data.serverNowMs) : data.expiresAtMs;
        }

        item.presignsInARow += 1;
        await this.write(item);

        return 'ok';
    }

    async upload(item) {
        let response;

        try {
            response = await this.put(item.url, { ...item.headers }, item.blob, { method: item.method });
        } catch (e) {
            this.goOffline(this.now());

            return 'stop';
        }

        const now = this.now();
        const status = Number.isFinite(response?.status) ? response.status : 0;

        if (status >= 200 && status < 300) {
            this.offline = false;
            this.offlineAttempts = 0;
            this.storeFailures = 0;
            item.stage = 'uploaded';
            // The bytes are in the store - the device needs only the report
            item.blob = null;
            item.url = null;
            item.headers = null;
            item.validUntil = null;
            item.attempts = 0;
            item.nextAttemptAt = 0;
            item.presignsInARow = 0;
            item.lastFailure = null;
            await this.write(item);

            return 'ok';
        }

        if (status === 0) {
            // Nothing readable came back (a dropped connection, a blocked request)
            this.goOffline(now);

            return 'stop';
        }

        item.lastFailure = { status, store: item.store, mediaId: item.mediaId };

        if (status === 408 || status === 429 || status >= 500) {
            // The store hiccups or is down: everything pauses; from the second failure on with a fresh URL (the
            // server may send it to its other store)
            this.storeFailures += 1;
            item.attempts += 1;
            const retryAfter = retryAfterOf(response);
            item.nextAttemptAt = now + Math.max(backoff(SERVER_BACKOFF_MS, item.attempts), retryAfter);
            this.retryAt = now + Math.max(backoff(SERVER_BACKOFF_MS, this.storeFailures), retryAfter);

            if (item.attempts >= 2) {
                this.dropPresign(item);
            }

            await this.write(item);

            return 'stop';
        }

        // 400 / 403 / other 4xx: the URL is no good (ended, a signature the store refuses) - a fresh one at once,
        // unless fresh ones keep being refused
        this.dropPresign(item);

        if (item.presignsInARow >= MAX_PRESIGNS_IN_A_ROW) {
            item.attempts += 1;
            item.nextAttemptAt = now + backoff(SERVER_BACKOFF_MS, item.attempts);
        }

        await this.write(item);

        return 'next';
    }

    async report(item) {
        try {
            await this.reportUploaded({
                mediaId: item.mediaId,
                bytes: item.bytes,
                manifest: clone(item.manifest),
                kind: item.kind,
                takenMs: item.takenMs,
                eventId: item.eventId,
                roundId: item.roundId,
                store: item.store,
                contentType: item.contentType,
                itemId: item.id,
            });
        } catch (e) {
            item.attempts += 1;
            item.nextAttemptAt = this.now() + backoff(SERVER_BACKOFF_MS, item.attempts);
            await this.write(item);

            return true;
        }

        await this.erase(item.id);
        this.uploadedCount += 1;

        return true;
    }

    dropPresign(item) {
        item.stage = 'queued';
        item.mediaId = null;
        item.store = null;
        item.url = null;
        item.headers = null;
        item.validUntil = null;
    }

    goOffline(now) {
        this.offline = true;
        this.offlineAttempts += 1;
        this.retryAt = now + backoff(OFFLINE_BACKOFF_MS, this.offlineAttempts);
    }

    cancelTimer() {
        if (this.timer !== null) {
            this.cancel(this.timer);
            this.timer = null;
        }
    }

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
 * A media outbox over the device's store (IndexedDB, or memory where it is missing) and the tab lock, loaded.
 *
 * @param {object} options     MediaOutbox's options, without `storage` / `lock`
 * @param {IDBFactory|null} [options.indexedDB]
 * @returns {Promise<MediaOutbox>}
 */
export async function openMediaOutbox({ indexedDB = globalThis.indexedDB, ...options }) {
    const storage = (await createMediaStorage(indexedDB)) ?? createMediaMemoryStorage();
    const outbox = new MediaOutbox({ ...options, storage, lock: options.lock ?? createTabLock(MEDIA_LOCK) });
    await outbox.load();

    return outbox;
}
