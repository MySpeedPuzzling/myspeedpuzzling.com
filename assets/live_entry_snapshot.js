/**
 * Snapshots of the live result entry (competition toolkit C4, docs/features/competitions-management/toolkit/
 * C-judge-page.md §1.5, R §2.7): the last known state of a round per signed-in player, kept on the device so the page
 * still works when the network drops - a page whose first state fetch fails shows the round as it was last known, and
 * (toolkit C5) the offline copy of the page renders from it.
 *
 * - Own IndexedDB database `msp-live-entry`, store `snapshots`, key `<playerId>:<roundId>` - never a store in the
 *   outbox's database (a version upgrade there would block while another tab holds it open).
 * - Written on every state the page gets (state fetches, live updates merged in) at most SNAPSHOT_WRITE_DELAY_MS after
 *   the first change not yet written, the newest state of the round then (reliability hardening F1, D4: a steady
 *   stream of updates never postpones the write); with `firstAtOnce` the first state of the page load is written at
 *   once (D3 - a reload offline a second after opening still finds the round). The state's Mercure subscription is
 *   never stored (a credential, and useless offline).
 * - Only for the player the device cookie names (live_entry_device.js): no cookie, or another player → nothing is
 *   read or written; start() deletes every other player's snapshots, those older than 36 h, and more than 30 per player.
 * - Like the outbox: store operations run one after another, a store that refuses a write keeps the snapshot in memory
 *   for this page, a store that cannot be opened at all is replaced by memory.
 * - Prefetch: the event's other rounds that start within ±12 h (at most 4, the nearest first), one request at a time.
 *
 * Storage, the clock and the timers are injected: tests/live-entry-offline-harness.mjs runs it under node.
 */

import { DEVICE_MARKER_KEY, SNAPSHOT_DATABASE, samePlayer } from './live_entry_device.js';

const SNAPSHOT_STORE = 'snapshots';

export const SNAPSHOT_RETENTION_MS = 36 * 3600 * 1000;
export const SNAPSHOTS_PER_PLAYER = 30;
export const SNAPSHOT_WRITE_DELAY_MS = 2000;
export const PREFETCH_WINDOW_MS = 12 * 3600 * 1000;
export const PREFETCH_MAX_ROUNDS = 4;
export const PREFETCH_GAP_MS = 10000;

function clone(value) {
    return value === undefined ? undefined : JSON.parse(JSON.stringify(value));
}

export function snapshotKey(playerId, roundId) {
    return `${String(playerId).toLowerCase()}:${roundId}`;
}

/**
 * The stored record of a round's state - a deep copy, without the stream's token.
 */
export function snapshotOf({ playerId, roundId, state, savedAt, clockOffsetMs = null, clockMeasuredAt = null, eventPeople = null }) {
    const { mercure, ...kept } = state ?? {};

    return {
        key: snapshotKey(playerId, roundId),
        playerId: String(playerId).toLowerCase(),
        roundId,
        competitionId: typeof kept.competition?.id === 'string' ? kept.competition.id : null,
        savedAt,
        clockOffsetMs: typeof clockOffsetMs === 'number' && Number.isFinite(clockOffsetMs) ? clockOffsetMs : null,
        clockMeasuredAt: typeof clockMeasuredAt === 'number' ? clockMeasuredAt : null,
        state: clone(kept),
        eventPeople: Array.isArray(eventPeople) ? clone(eventPeople) : null,
    };
}

/**
 * Snapshots kept in memory - tests, and browsers without a working IndexedDB.
 */
export function createMemorySnapshotStorage(initial = []) {
    const records = new Map(initial.map((record) => [record.key, clone(record)]));

    return {
        persistent: false,
        async getAll() {
            return [...records.values()].map(clone);
        },
        async get(key) {
            return records.has(key) ? clone(records.get(key)) : null;
        },
        async put(record) {
            records.set(record.key, clone(record));
        },
        async delete(key) {
            records.delete(key);
        },
    };
}

function requestPromise(request) {
    return new Promise((resolve, reject) => {
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
    });
}

/**
 * The device's snapshots in IndexedDB, or null where they cannot be opened. A sign-out on another page deletes the
 * database (live_entry_device.js): this connection closes then, and every later operation fails (memory takes over).
 */
export async function createIndexedDbSnapshotStorage(factory = globalThis.indexedDB) {
    if (!factory) {
        return null;
    }

    let database;

    try {
        database = await new Promise((resolve, reject) => {
            const request = factory.open(SNAPSHOT_DATABASE, 1);
            request.onupgradeneeded = () => {
                if (!request.result.objectStoreNames.contains(SNAPSHOT_STORE)) {
                    request.result.createObjectStore(SNAPSHOT_STORE, { keyPath: 'key' });
                }
            };
            request.onsuccess = () => resolve(request.result);
            request.onerror = () => reject(request.error);
            request.onblocked = () => reject(new Error('blocked'));
        });
    } catch (e) {
        return null;
    }

    database.onversionchange = () => database.close();

    const run = async (mode, operation) => {
        const transaction = database.transaction(SNAPSHOT_STORE, mode);
        const result = await requestPromise(operation(transaction.objectStore(SNAPSHOT_STORE)));

        if (mode === 'readwrite') {
            await new Promise((resolve, reject) => {
                transaction.oncomplete = () => resolve();
                transaction.onerror = () => reject(transaction.error);
                transaction.onabort = () => reject(transaction.error ?? new Error('aborted'));
            });
        }

        return result;
    };

    return {
        persistent: true,
        getAll: () => run('readonly', (store) => store.getAll()),
        get: async (key) => (await run('readonly', (store) => store.get(key))) ?? null,
        put: (record) => run('readwrite', (store) => store.put(record)),
        delete: (key) => run('readwrite', (store) => store.delete(key)),
    };
}

/**
 * The rounds worth a snapshot before the network goes: the event's other rounds starting within ±12 h of `nowMs`,
 * the nearest first, at most 4.
 *
 * @returns {string[]} round ids
 */
export function prefetchTargets(rounds, currentRoundId, nowMs, { windowMs = PREFETCH_WINDOW_MS, max = PREFETCH_MAX_ROUNDS } = {}) {
    return (Array.isArray(rounds) ? rounds : [])
        .filter((round) => typeof round?.id === 'string' && round.id !== currentRoundId)
        .map((round) => ({ id: round.id, distance: Math.abs(Date.parse(round.startsAt ?? '') - nowMs) }))
        .filter((round) => Number.isFinite(round.distance) && round.distance <= windowMs)
        .sort((a, b) => a.distance - b.distance)
        .slice(0, max)
        .map((round) => round.id);
}

/**
 * Asks for the rounds' states one after another, `gapMs` apart, and stores each answer. Stops when `shouldStop()`
 * says so (offline, the page closed, another player) or at the first failed request.
 *
 * @returns {Promise<string[]>} the rounds stored
 */
export async function prefetchRounds({ roundIds, fetchState, store, wait, gapMs = PREFETCH_GAP_MS, shouldStop = () => false, extra = () => ({}) }) {
    const stored = [];

    for (const [index, roundId] of roundIds.entries()) {
        if (index > 0) {
            await wait(gapMs);
        }

        if (shouldStop()) {
            break;
        }

        let state = null;

        try {
            state = await fetchState(roundId);
        } catch (e) {
            state = null;
        }

        if (state === null || shouldStop()) {
            break;
        }

        if (await store.saveNow({ ...extra(), roundId, state })) {
            stored.push(roundId);
        }
    }

    return stored;
}

export class SnapshotStore {
    /**
     * @param {object} options
     * @param {object} options.storage          createIndexedDbSnapshotStorage() / createMemorySnapshotStorage()
     * @param {string} options.playerId         the page's reader
     * @param {function(): (string|null)} options.devicePlayer  the player the device cookie names right now
     * @param {function(): number} [options.now]
     * @param {object|null} [options.marker]    a Storage (localStorage) - the device's marker (live_entry_device.js)
     * @param {boolean} [options.firstAtOnce]    a round's first save() of this page load is written at once (D3)
     */
    constructor({
        storage,
        playerId,
        devicePlayer,
        now = () => Date.now(),
        marker = null,
        delayMs = SNAPSHOT_WRITE_DELAY_MS,
        schedule = (task, ms) => setTimeout(task, ms),
        cancel = (timer) => clearTimeout(timer),
        firstAtOnce = false,
    }) {
        this.storage = storage;
        this.firstAtOnce = firstAtOnce;
        // Rounds saved by this page load (firstAtOnce)
        this.savedRounds = new Set();
        this.playerId = String(playerId ?? '').toLowerCase();
        this.devicePlayer = devicePlayer;
        this.now = now;
        this.marker = marker;
        this.delayMs = delayMs;
        this.schedule = schedule;
        this.cancel = cancel;
        this.queue = Promise.resolve();
        // key → {record, timer} - written when the timer runs (or on flush())
        this.waiting = new Map();
        // Records the store refused (full, broken, deleted under us) - this page still reads them
        this.unpersisted = new Map();
        this.storageFailed = false;
    }

    serial(operation) {
        const run = this.queue.then(operation, operation);
        this.queue = run.catch(() => {});

        return run;
    }

    /**
     * Snapshots are only for the player the device names: no cookie (signed out) or another player - none.
     */
    allowed() {
        return this.playerId !== '' && samePlayer(this.devicePlayer(), this.playerId);
    }

    fresh(record, now = this.now()) {
        return record !== null && record !== undefined
            && samePlayer(record.playerId, this.playerId)
            && typeof record.savedAt === 'number'
            && now - record.savedAt <= SNAPSHOT_RETENTION_MS;
    }

    /**
     * On the page's start: every other player's snapshots, those too old and those over the cap go. Marks the device
     * as holding this player's copies.
     *
     * @returns {Promise<number>} how many went
     */
    start() {
        return this.serial(async () => {
            if (this.allowed()) {
                try {
                    this.marker?.setItem(DEVICE_MARKER_KEY, this.playerId);
                } catch (e) {
                    // Other pages cannot wipe after a sign-out then - the live page still does when it opens
                }
            }

            let all;

            try {
                all = await this.storage.getAll();
            } catch (e) {
                this.storageFailed = true;

                return 0;
            }

            const now = this.now();
            const own = all.filter((record) => this.allowed() && this.fresh(record, now)).sort((a, b) => b.savedAt - a.savedAt);
            const keep = new Set(own.slice(0, SNAPSHOTS_PER_PLAYER).map((record) => record.key));
            let removed = 0;

            for (const record of all) {
                if (!keep.has(record.key)) {
                    await this.erase(record.key);
                    removed += 1;
                }
            }

            return removed;
        });
    }

    /**
     * Every snapshot on the device goes (a sign-out seen by the page).
     */
    wipe() {
        for (const waiting of this.waiting.values()) {
            this.cancel(waiting.timer);
        }

        this.waiting.clear();
        this.unpersisted.clear();

        return this.serial(async () => {
            try {
                for (const record of await this.storage.getAll()) {
                    await this.erase(record.key);
                }
            } catch (e) {
                this.storageFailed = true;
            }
        });
    }

    /**
     * The round's state changed: stored at most `delayMs` after the first change not yet written - a later change
     * within the pause replaces the record, never the pause (D4). The page load's first state of a round is written at
     * once with `firstAtOnce` (D3).
     */
    save({ roundId, state, clockOffsetMs = null, clockMeasuredAt = null, eventPeople = null }) {
        if (!this.allowed()) {
            return false;
        }

        const record = snapshotOf({ playerId: this.playerId, roundId, state, savedAt: this.now(), clockOffsetMs, clockMeasuredAt, eventPeople });
        const before = this.waiting.get(record.key);

        if (this.firstAtOnce && !this.savedRounds.has(record.key)) {
            this.savedRounds.add(record.key);

            if (before !== undefined) {
                this.cancel(before.timer);
                this.waiting.delete(record.key);
            }

            this.serial(() => this.write(record));

            return true;
        }

        if (before !== undefined) {
            before.record = record;

            return true;
        }

        const waiting = { record, timer: null };
        waiting.timer = this.schedule(() => {
            this.waiting.delete(record.key);
            this.serial(() => this.write(waiting.record));
        }, this.delayMs);

        this.waiting.set(record.key, waiting);

        return true;
    }

    /**
     * Stored at once (prefetch).
     *
     * @returns {Promise<boolean>} stored (on the device or, the store refusing, in memory)
     */
    saveNow({ roundId, state, clockOffsetMs = null, clockMeasuredAt = null, eventPeople = null }) {
        if (!this.allowed()) {
            return Promise.resolve(false);
        }

        const record = snapshotOf({ playerId: this.playerId, roundId, state, savedAt: this.now(), clockOffsetMs, clockMeasuredAt, eventPeople });
        const before = this.waiting.get(record.key);

        // An older state waiting for its pause would overwrite this one
        if (before !== undefined) {
            this.cancel(before.timer);
            this.waiting.delete(record.key);
        }

        return this.serial(() => this.write(record)).then(() => true);
    }

    /**
     * Writes what waits now (the page is being hidden or closed).
     */
    flush() {
        const records = [...this.waiting.values()];
        this.waiting.clear();

        for (const { record, timer } of records) {
            this.cancel(timer);
        }

        return this.serial(async () => {
            for (const { record } of records) {
                await this.write(record);
            }
        });
    }

    async write(record) {
        // The device changed hands meanwhile: nothing of the previous player is written any more
        if (!this.allowed()) {
            return;
        }

        try {
            await this.storage.put(record);
            this.unpersisted.delete(record.key);
        } catch (e) {
            this.unpersisted.set(record.key, record);
            this.storageFailed = true;
        }
    }

    async erase(key) {
        this.unpersisted.delete(key);

        try {
            await this.storage.delete(key);
        } catch (e) {
            this.storageFailed = true;
        }
    }

    /**
     * The newest snapshot of the round this player has - null when none, too old, or the device names nobody/another.
     */
    read(roundId) {
        return this.serial(async () => {
            if (!this.allowed()) {
                return null;
            }

            const key = snapshotKey(this.playerId, roundId);
            let stored = null;

            try {
                stored = await this.storage.get(key);
            } catch (e) {
                this.storageFailed = true;
            }

            const candidates = [this.waiting.get(key)?.record, this.unpersisted.get(key), stored]
                .filter((record) => this.fresh(record))
                .sort((a, b) => b.savedAt - a.savedAt);

            return candidates.length > 0 ? clone(candidates[0]) : null;
        });
    }

    /**
     * This player's snapshots (of one event), the newest first - for the offline copy of the page (toolkit C5/C8).
     */
    list({ competitionId = null } = {}) {
        return this.serial(async () => {
            if (!this.allowed()) {
                return [];
            }

            let stored = [];

            try {
                stored = await this.storage.getAll();
            } catch (e) {
                this.storageFailed = true;
            }

            const newest = new Map();

            for (const record of [...stored, ...this.unpersisted.values(), ...[...this.waiting.values()].map((waiting) => waiting.record)]) {
                if (!this.fresh(record) || (competitionId !== null && record.competitionId !== competitionId)) {
                    continue;
                }

                const known = newest.get(record.key);

                if (known === undefined || known.savedAt <= record.savedAt) {
                    newest.set(record.key, record);
                }
            }

            return [...newest.values()].sort((a, b) => b.savedAt - a.savedAt).map(clone);
        });
    }
}
