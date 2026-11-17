/**
 * The outbox of the official results tools (docs/features/competitions-management/live-results.md): every change a
 * referee saves is written to the device first (IndexedDB, per signed-in player) and only then sent - so nothing
 * typed is lost to bad venue Wi-Fi, a closed tab or an expired session.
 *
 * - Sent in the order saved, in batches per round (the server chains changes of one field in a set); changes of one
 *   entry's field never overtake each other, other entries go on when one is stuck.
 * - The server answers per change (RecordRoundResults): applied / unchanged → gone from the outbox; conflict →
 *   kept with the other device's value until the referee keeps theirs (sent again from that value) or takes the
 *   other; rejected → kept with the reason until fixed or discarded.
 * - Signed out (`auth`) or a stale page (`csrf`) stop sending and keep everything. No rights for a round's event (any
 *   more) sets that round's changes apart (`forbidden` - kept until retried or discarded); every other round goes on.
 * - Offline retries with a growing pause; a busy server (429, a ban or proxy page - `busy`) pauses everything, as
 *   long as its Retry-After asks; a server error retries the change alone (isolating a batch first), each change
 *   with its own pause. A round that is gone refuses its changes (`round_not_found`).
 * - One tab sends at a time (`lock`); every tab reads the shared store, so a change saved in one tab is sent by another.
 * - A group (`enqueueGroup()`, one Save that changes several fields of an entry - a K-of-N round's `puzzles` + `result`,
 *   competition toolkit R §1.1) is stored in one write, sent only as a whole and never split across requests: not by
 *   the batch size, not by isolating a failing set. The server applies it together or not at all (A rule 7).
 * - The connection back (`connectionBack()`: the `online` event, a state answered) sends what waited only for being
 *   offline at once, without waiting out the offline pause; changes set apart (rights, refusals, conflicts) stay apart.
 *
 * Storage, the network, the clock and the lock are injected: tests/LiveResultsScriptsTest.php drives the state machine
 * under node with an in-memory store.
 */

export const OUTBOX_DATABASE = 'msp-official-results';
const OUTBOX_STORE = 'outbox';

export const BATCH_SIZE = 100;
// Offline: 1 s, 2 s, 4 s … at most 30 s between attempts
const OFFLINE_BACKOFF_MS = [1000, 2000, 4000, 8000, 15000, 30000];
// A change the server keeps failing: 5 s, 15 s, 30 s, 1 min, then every 2 min
const SERVER_BACKOFF_MS = [5000, 15000, 30000, 60000, 120000];
// The server is busy or something in between blocks it (no verdict on any change): 5 s, 15 s, 30 s, 1 min, 2 min
const BUSY_BACKOFF_MS = [5000, 15000, 30000, 60000, 120000];
// A Retry-After longer than this is not waited for in full - a Retry now still sends at once
const MAX_RETRY_AFTER_MS = 15 * 60 * 1000;
// After this many failed attempts a change counts as stuck (shown to the referee, still retried)
export const STUCK_AFTER_ATTEMPTS = 3;

function backoff(steps, attempt) {
    return steps[Math.min(Math.max(attempt, 1), steps.length) - 1];
}

function clone(value) {
    return value === undefined ? undefined : JSON.parse(JSON.stringify(value));
}

/**
 * Items kept in memory - tests, and browsers without a working IndexedDB (private windows of old Safari).
 */
export function createMemoryStorage(initial = []) {
    const items = new Map(initial.map((item) => [item.id, clone(item)]));

    return {
        persistent: false,
        async getAll() {
            return [...items.values()].map(clone);
        },
        async get(id) {
            return items.has(id) ? clone(items.get(id)) : null;
        },
        async put(item) {
            items.set(item.id, clone(item));
        },
        async putMany(many) {
            for (const item of many) {
                items.set(item.id, clone(item));
            }
        },
        async delete(id) {
            items.delete(id);
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
 * The device's outbox in IndexedDB, or null where it cannot be opened.
 */
export async function createIndexedDbStorage(factory = globalThis.indexedDB) {
    if (!factory) {
        return null;
    }

    let database;

    try {
        database = await new Promise((resolve, reject) => {
            const request = factory.open(OUTBOX_DATABASE, 1);
            request.onupgradeneeded = () => {
                if (!request.result.objectStoreNames.contains(OUTBOX_STORE)) {
                    request.result.createObjectStore(OUTBOX_STORE, { keyPath: 'id' });
                }
            };
            request.onsuccess = () => resolve(request.result);
            request.onerror = () => reject(request.error);
            request.onblocked = () => reject(new Error('blocked'));
        });
    } catch (e) {
        return null;
    }

    const run = async (mode, operation) => {
        const transaction = database.transaction(OUTBOX_STORE, mode);
        const result = await requestPromise(operation(transaction.objectStore(OUTBOX_STORE)));

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
        get: async (id) => (await run('readonly', (store) => store.get(id))) ?? null,
        put: (item) => run('readwrite', (store) => store.put(item)),
        // A group in one transaction - a reload never finds half of it
        putMany: async (many) => {
            const transaction = database.transaction(OUTBOX_STORE, 'readwrite');
            const store = transaction.objectStore(OUTBOX_STORE);
            const done = new Promise((resolve, reject) => {
                transaction.oncomplete = () => resolve();
                transaction.onerror = () => reject(transaction.error);
                transaction.onabort = () => reject(transaction.error ?? new Error('aborted'));
            });

            for (const item of many) {
                store.put(item);
            }

            await done;
        },
        delete: (id) => run('readwrite', (store) => store.delete(id)),
    };
}

/**
 * Runs `task` only when no other tab of the browser is sending (Web Locks; a localStorage lease where they are
 * missing). Resolves to false when another tab holds it. Two tabs sending at once would do no harm - every change
 * replays as `unchanged` - the lock just saves the requests.
 */
export function createTabLock(name, { locks = globalThis.navigator?.locks, storage = globalThis.localStorage, now = () => Date.now() } = {}) {
    if (locks && typeof locks.request === 'function') {
        return (task) => locks.request(name, { ifAvailable: true }, async (lock) => {
            if (lock === null) {
                return false;
            }

            await task();

            return true;
        });
    }

    const owner = Math.random().toString(36).slice(2);
    const key = `${name}:lease`;

    return async (task) => {
        try {
            const lease = JSON.parse(storage?.getItem(key) ?? 'null');

            if (lease && lease.owner !== owner && lease.until > now()) {
                return false;
            }

            storage?.setItem(key, JSON.stringify({ owner, until: now() + 60000 }));
        } catch (e) {
            // No storage at all: this tab sends
        }

        try {
            await task();
        } finally {
            try {
                const lease = JSON.parse(storage?.getItem(key) ?? 'null');

                if (lease && lease.owner === owner) {
                    storage.removeItem(key);
                }
            } catch (e) {
                // Nothing to release
            }
        }

        return true;
    };
}

/**
 * @typedef {object} OutboxItem
 * @property {string} id               the change's clientChangeId
 * @property {string} userId
 * @property {string} roundId
 * @property {number} seq              order of saving
 * @property {number} createdAt
 * @property {string} entryRef         "participant_round:<id>" / "team:<id>" - for a new entry its future ref
 * @property {object|null} newEntry    {clientEntryId, kind, name, members?} until the server has it
 * @property {string|null} label       the entry's name when saved - for lists of unsent changes
 * @property {string} field            result | table_number | qualified
 * @property {string} [groupId]        changes saved together (enqueueGroup) - sent together, never split
 * @property {*} from
 * @property {*} to
 * @property {'pending'|'conflict'|'rejected'|'forbidden'} state  forbidden: no rights for the round's event (any more)
 * @property {number} attempts         failed attempts (server errors, a refused set sent alone)
 * @property {number} nextAttemptAt
 * @property {object|null} lastError   {kind, status}
 * @property {*} [current]             conflict: the value somebody else saved
 * @property {object|null} [enteredBy] conflict on a result: who saved it {playerId, name}
 * @property {string|null} [enteredAt]
 * @property {string|null} [reason]    rejected: reason key
 * @property {string|null} [message]   rejected: translated reason
 */
export class Outbox {
    /**
     * @param {object} options
     * @param {object} options.storage    createIndexedDbStorage() / createMemoryStorage()
     * @param {function(string, object[]): Promise<object>} options.send  (roundId, changes) → officialResultsRequest() answer
     * @param {string} options.userId     the signed-in player - another player's changes on the device are never sent
     * @param {function(): number} [options.now]
     * @param {function(): string} [options.newId]
     * @param {function(function(): Promise): Promise<boolean>} [options.lock]
     * @param {function(object[], string): void} [options.onEntries]  the server's entries after a set (roundId)
     * @param {function(): void} [options.onChange]  the outbox changed (status, items)
     */
    constructor({ storage, send, userId, now = () => Date.now(), newId, lock = null, onEntries = () => {}, onChange = () => {}, batchSize = BATCH_SIZE }) {
        this.storage = storage;
        this.send = send;
        this.userId = userId;
        this.now = now;
        this.newId = newId ?? (() => globalThis.crypto.randomUUID());
        this.lock = lock ?? (async (task) => { await task(); return true; });
        this.onEntries = onEntries;
        this.onChange = onChange;
        this.batchSize = batchSize;

        /** @type {OutboxItem[]} */
        this.items = [];
        this.inFlight = new Set();
        this.flushing = false;
        this.flushRequested = false;
        // null | 'auth' (signed out) | 'csrf' (the page's token is no longer accepted) - nothing is sent until a retry
        this.blocked = null;
        this.offline = false;
        this.offlineAttempts = 0;
        // The server answered "not now" (429, a ban or proxy page) - everything pauses until retryAt
        this.busy = false;
        this.busyAttempts = 0;
        this.retryAt = 0;
        // After a batch failed on the server: send one change at a time until one goes through
        this.isolate = false;
        this.lastSeq = 0;
        // Store operations run one after another - a reload never sees a change half written
        this.queue = Promise.resolve();
        // Saved changes the store refused (full, broken) - kept in memory and sent anyway
        this.unpersisted = new Map();
        this.storageFailed = false;
    }

    serial(operation) {
        const run = this.queue.then(operation, operation);
        this.queue = run.catch(() => {});

        return run;
    }

    /**
     * Reads the store again - after another tab changed it.
     */
    load() {
        return this.serial(async () => {
            const stored = (await this.storage.getAll()).filter((item) => item.userId === this.userId);
            const storedIds = new Set(stored.map((item) => item.id));
            this.items = [...stored, ...[...this.unpersisted.values()].filter((item) => !storedIds.has(item.id))]
                .sort((a, b) => a.seq - b.seq);
            this.lastSeq = Math.max(this.lastSeq, ...this.items.map((item) => item.seq), 0);
            this.onChange();
        });
    }

    async write(item) {
        try {
            await this.storage.put(item);
            this.unpersisted.delete(item.id);
        } catch (e) {
            this.unpersisted.set(item.id, item);
            this.storageFailed = true;
            throw e;
        }
    }

    async writeMany(many) {
        if (many.length === 1 || typeof this.storage.putMany !== 'function') {
            for (const item of many) {
                await this.write(item);
            }

            return;
        }

        try {
            await this.storage.putMany(many);
            many.forEach((item) => this.unpersisted.delete(item.id));
        } catch (e) {
            many.forEach((item) => this.unpersisted.set(item.id, item));
            this.storageFailed = true;
            throw e;
        }
    }

    async erase(id) {
        this.unpersisted.delete(id);

        try {
            await this.storage.delete(id);
        } catch (e) {
            this.storageFailed = true;
        }
    }

    /**
     * Changes stored on the device by another player (signed in on this device before) - never sent by this one.
     */
    async foreignCount() {
        const all = await this.storage.getAll();

        return all.filter((item) => item.userId !== this.userId).length;
    }

    all() {
        return this.items;
    }

    nextSeq() {
        const now = this.now() * 1000;
        this.lastSeq = Math.max(now, this.lastSeq + 1);

        return this.lastSeq;
    }

    itemsOf(entryRef, field) {
        return this.items.filter((item) => item.entryRef === entryRef && (field === undefined || item.field === field));
    }

    /**
     * Saves a change on the device. `from` is what the server holds as far as the device knows: the value its own
     * earlier waiting change will have set, the other device's value of a conflict being overwritten, else
     * `serverValue`. Refused or conflicting changes of the same field are replaced by this one.
     *
     * The item is in memory at once (the page shows it right away); `persisted` resolves once it is stored - the
     * network is never asked before that.
     *
     * @returns {{item: OutboxItem, persisted: Promise<void>}}
     */
    enqueue(change) {
        const { items, persisted } = this.enqueueAll([change], null);

        return { item: items[0], persisted };
    }

    /**
     * Saves changes that belong together (one Save of several fields of an entry - a K-of-N round's `puzzles` and
     * `result`): one write to the store, one shared `groupId`; they are sent in one request or not at all.
     *
     * @param {object[]} changes  each as for enqueue(); one round
     * @returns {{items: OutboxItem[], groupId: string|null, persisted: Promise<void>}}
     */
    enqueueGroup(changes) {
        const groupId = changes.length > 1 ? this.newId() : null;
        const { items, persisted } = this.enqueueAll(changes, groupId);

        return { items, groupId, persisted };
    }

    enqueueAll(changes, groupId) {
        const created = [];
        const supersededAll = [];

        for (const { roundId, entryRef, newEntry = null, field, serverValue = null, to, label = null } of changes) {
            const earlier = this.itemsOf(entryRef, field);
            const superseded = earlier.filter((item) => item.state !== 'pending' && !this.inFlight.has(item.id));
            const waiting = earlier.filter((item) => !superseded.includes(item));
            const conflict = superseded.filter((item) => item.state === 'conflict').pop();

            let from = serverValue;

            if (waiting.length > 0) {
                from = waiting[waiting.length - 1].to;
            } else if (conflict !== undefined) {
                from = conflict.current ?? null;
            }

            const item = {
                id: this.newId(),
                userId: this.userId,
                roundId,
                seq: this.nextSeq(),
                createdAt: this.now(),
                entryRef,
                newEntry: newEntry === null ? null : clone(newEntry),
                label,
                field,
                from: clone(from) ?? null,
                to: clone(to) ?? null,
                state: 'pending',
                attempts: 0,
                nextAttemptAt: 0,
                lastError: null,
            };

            if (groupId !== null) {
                item.groupId = groupId;
            }

            this.items = this.items.filter((candidate) => !superseded.includes(candidate));
            this.items.push(item);
            this.unpersisted.set(item.id, item);
            created.push(item);
            supersededAll.push(...superseded);
        }

        this.onChange();

        const persisted = this.serial(async () => {
            for (const old of supersededAll) {
                await this.erase(old.id);
            }

            await this.writeMany(created);
        });

        return { items: created, persisted };
    }

    /**
     * Takes a saved change back: still waiting → it simply goes; sent already → the opposite change is saved. A
     * change of a group takes the group back (its members still on the device go with it; the opposite changes are
     * a group again).
     *
     * @param {OutboxItem|OutboxItem[]} saved  the change, or every change of one Save
     * @returns {Promise<OutboxItem|OutboxItem[]|null>} the opposite change(s), if needed
     */
    async undo(saved) {
        const originals = Array.isArray(saved) ? [...saved] : [saved];

        for (const member of this.items) {
            if (originals[0]?.groupId && member.groupId === originals[0].groupId && !originals.some((original) => original.id === member.id)) {
                originals.push(member);
            }
        }

        const opposites = [];

        for (const original of originals) {
            const stored = this.items.find((candidate) => candidate.id === original.id);

            if (stored !== undefined && (stored.state !== 'pending' || !this.inFlight.has(stored.id))) {
                await this.remove(stored.id);
                continue;
            }

            opposites.push({
                roundId: original.roundId,
                entryRef: original.entryRef,
                newEntry: original.newEntry,
                field: original.field,
                serverValue: original.to,
                to: original.from,
                label: original.label ?? null,
            });
        }

        if (opposites.length === 0) {
            return null;
        }

        const { items, persisted } = this.enqueueGroup(opposites);
        await persisted;

        return Array.isArray(saved) ? items : items[0];
    }

    remove(id) {
        return this.serial(async () => {
            this.items = this.items.filter((item) => item.id !== id);
            await this.erase(id);
            this.onChange();
        });
    }

    /**
     * Conflict → "keep mine": every change of this field is sent again as one, from the other device's value. A
     * change of a group sends its group again: every member in conflict that way, a refused member as it was - a new
     * group.
     */
    keepMine(id) {
        return this.serial(async () => {
            const item = this.items.find((candidate) => candidate.id === id);

            if (item === undefined || item.state !== 'conflict') {
                return null;
            }

            if (!item.groupId) {
                return this.replaceChain(id);
            }

            const groupId = this.newId();
            const members = this.items.filter((candidate) => candidate.groupId === item.groupId && !this.inFlight.has(candidate.id));
            const replacements = [];

            for (const member of members) {
                if (member.state === 'conflict') {
                    replacements.push(await this.replaceChain(member.id, groupId));
                } else if (member.state === 'rejected' || member.state === 'pending') {
                    replacements.push(await this.resend(member, groupId));
                }
            }

            return replacements;
        });
    }

    async resend(item, groupId) {
        const replacement = {
            ...clone(item),
            id: this.newId(),
            groupId,
            state: 'pending',
            attempts: 0,
            nextAttemptAt: 0,
            lastError: null,
            reason: undefined,
            message: undefined,
        };

        this.items = this.items.map((candidate) => (candidate.id === item.id ? replacement : candidate));
        await this.erase(item.id);
        this.onChange();
        await this.write(replacement);

        return replacement;
    }

    async replaceChain(id, groupId = undefined) {
        const item = this.items.find((candidate) => candidate.id === id);

        if (item === undefined || item.state !== 'conflict') {
            return null;
        }

        const chain = this.itemsOf(item.entryRef, item.field).filter((candidate) => !this.inFlight.has(candidate.id));
        const last = chain[chain.length - 1];
        const replacement = {
            ...clone(item),
            id: this.newId(),
            from: clone(item.current) ?? null,
            to: clone(last.to) ?? null,
            state: 'pending',
            attempts: 0,
            nextAttemptAt: 0,
            lastError: null,
            current: undefined,
            enteredBy: undefined,
            enteredAt: undefined,
        };

        if (groupId !== undefined) {
            replacement.groupId = groupId;
        }

        this.items = this.items.filter((candidate) => !chain.includes(candidate));
        this.items.push(replacement);
        this.items.sort((a, b) => a.seq - b.seq);

        for (const old of chain) {
            await this.erase(old.id);
        }

        this.onChange();
        await this.write(replacement);

        return replacement;
    }

    /**
     * Conflict → "take theirs": this device's changes of the field go (of every field of the change's group).
     */
    async takeTheirs(id) {
        const item = this.items.find((candidate) => candidate.id === id);

        if (item === undefined) {
            return;
        }

        const members = item.groupId ? this.items.filter((candidate) => candidate.groupId === item.groupId) : [item];
        const fields = [...new Set(members.map((member) => `${member.entryRef}|${member.field}`))];

        for (const key of fields) {
            const [entryRef, field] = [key.slice(0, key.lastIndexOf('|')), key.slice(key.lastIndexOf('|') + 1)];

            for (const old of this.itemsOf(entryRef, field).filter((candidate) => !this.inFlight.has(candidate.id))) {
                await this.remove(old.id);
            }
        }
    }

    /**
     * Sends again now - a waiting change's pause, a signed-out stop, the offline pause are all skipped.
     */
    async retryNow(id = null) {
        this.blocked = null;
        this.retryAt = 0;

        await this.serial(async () => {
            for (const item of this.items) {
                if (id !== null && item.id !== id) {
                    continue;
                }

                // Set apart for missing rights: tried once more (the rights may be back)
                if (item.state === 'forbidden') {
                    item.state = 'pending';
                    item.lastError = null;
                    item.nextAttemptAt = 0;
                    await this.write(item).catch(() => {});
                } else if (item.state === 'pending' && item.nextAttemptAt > 0) {
                    item.nextAttemptAt = 0;
                    await this.write(item).catch(() => {});
                }
            }
        });

        this.onChange();

        return this.flush();
    }

    hasUnsent() {
        return this.items.length > 0;
    }

    /**
     * Drops every change the predicate picks (the referee confirmed it) - e.g. those of an event they lost the rights to.
     *
     * @returns {Promise<number>} how many went
     */
    async discardWhere(predicate) {
        const gone = this.items.filter((item) => predicate(item) && !this.inFlight.has(item.id));

        for (const item of gone) {
            await this.remove(item.id);
        }

        return gone.length;
    }

    status() {
        const now = this.now();
        const pending = this.items.filter((item) => item.state === 'pending');

        return {
            total: this.items.length,
            pending: pending.length,
            stuck: pending.filter((item) => item.attempts >= STUCK_AFTER_ATTEMPTS).length,
            conflicts: this.items.filter((item) => item.state === 'conflict').length,
            rejected: this.items.filter((item) => item.state === 'rejected').length,
            forbidden: this.items.filter((item) => item.state === 'forbidden').length,
            forbiddenRounds: [...new Set(this.items.filter((item) => item.state === 'forbidden').map((item) => item.roundId))],
            blocked: this.blocked,
            offline: this.offline,
            busy: this.busy,
            sending: this.inFlight.size > 0,
            waitingUntil: Math.max(this.retryAt, 0) > now ? this.retryAt : null,
        };
    }

    /**
     * The changes that may go now, in order: pending, not pausing, and no earlier change of the same field
     * waiting for anything. A group goes only as a whole: one member that may not go holds the others (and what
     * waits behind them).
     */
    ready(now) {
        const sizes = new Map();

        for (const item of this.items) {
            if (item.groupId) {
                sizes.set(item.groupId, (sizes.get(item.groupId) ?? 0) + 1);
            }
        }

        const heldGroups = new Set();

        for (;;) {
            const held = new Set();
            const ready = [];
            const readyInGroup = new Map();

            for (const item of this.items) {
                const key = `${item.entryRef}|${item.field}`;

                if (held.has(key)) {
                    continue;
                }

                if (item.state !== 'pending' || item.nextAttemptAt > now || this.inFlight.has(item.id) || (item.groupId && heldGroups.has(item.groupId))) {
                    held.add(key);
                    continue;
                }

                ready.push(item);

                if (item.groupId) {
                    readyInGroup.set(item.groupId, (readyInGroup.get(item.groupId) ?? 0) + 1);
                }
            }

            let incomplete = false;

            for (const [groupId, count] of readyInGroup) {
                if (count < sizes.get(groupId)) {
                    heldGroups.add(groupId);
                    incomplete = true;
                }
            }

            if (!incomplete) {
                return ready;
            }
        }
    }

    /**
     * The next request's changes of one round: whole units (a group, or a single change) in order, at most
     * `batchSize` changes - a group that does not fit waits for the next request (one larger than a batch goes
     * alone); one unit while a failing set is being isolated.
     *
     * @returns {{batch: OutboxItem[], units: number}}
     */
    nextBatch(ready, roundId) {
        const units = [];
        const byGroup = new Map();

        for (const item of ready) {
            if (item.roundId !== roundId) {
                continue;
            }

            if (item.groupId) {
                if (!byGroup.has(item.groupId)) {
                    const unit = [];
                    byGroup.set(item.groupId, unit);
                    units.push(unit);
                }

                byGroup.get(item.groupId).push(item);
            } else {
                units.push([item]);
            }
        }

        const batch = [];
        let taken = 0;

        for (const unit of units) {
            if (taken > 0 && (this.isolate || batch.length + unit.length > this.batchSize)) {
                break;
            }

            batch.push(...unit);
            taken += 1;
        }

        return { batch, units: taken };
    }

    /**
     * The connection is back (the `online` event, a state answered): what waited only for being offline goes now -
     * not after the rest of the offline pause. Changes set apart (no rights, refused, in conflict, a server error's
     * own pause) and a busy server's pause stay as they are.
     */
    connectionBack() {
        if (this.offline && this.blocked === null) {
            this.retryAt = 0;
            this.offlineAttempts = 0;
            this.onChange();
        }

        return this.flush();
    }

    /**
     * Sends whatever may go, batch after batch, until nothing is ready or the network/session says stop.
     * A call while another runs makes that one go round once more.
     */
    async flush() {
        if (this.flushing) {
            this.flushRequested = true;

            return;
        }

        this.flushing = true;

        try {
            do {
                this.flushRequested = false;
                const acquired = await this.lock(() => this.flushLoop());

                if (!acquired) {
                    // Another tab sends - it reads the shared store, our changes included
                    break;
                }
            } while (this.flushRequested);
        } finally {
            this.flushing = false;
        }
    }

    async flushLoop() {
        // Another tab may have saved or settled changes meanwhile
        await this.load();

        while (this.blocked === null) {
            const now = this.now();

            if (this.retryAt > now) {
                break;
            }

            const ready = this.ready(now);

            if (ready.length === 0) {
                break;
            }

            const roundId = ready[0].roundId;
            const { batch, units } = this.nextBatch(ready, roundId);

            batch.forEach((item) => this.inFlight.add(item.id));
            this.onChange();

            let answer;

            try {
                answer = await this.send(roundId, batch.map((item) => this.wire(item)));
            } catch (e) {
                answer = { kind: 'offline' };
            } finally {
                batch.forEach((item) => this.inFlight.delete(item.id));
            }

            await this.serial(() => this.settle(roundId, batch.map((item) => item.id), answer, units));

            // Signed out, a stale page (blocked), offline or a busy server (retryAt) end the round of sending;
            // a round without rights only sets its own changes apart
            if (answer.kind === 'offline' || answer.kind === 'auth') {
                break;
            }
        }

        this.onChange();
    }

    wire(item) {
        const change = {
            clientChangeId: item.id,
            field: item.field,
            from: item.from ?? null,
            to: item.to ?? null,
        };

        if (item.newEntry) {
            change.newEntry = item.newEntry;
        } else {
            change.entry = item.entryRef;
        }

        return change;
    }

    async settle(roundId, batchIds, answer, units = batchIds.length) {
        const now = this.now();
        // The items as they are now - a reload may have replaced the objects while the request was out
        const batch = batchIds.map((id) => this.items.find((item) => item.id === id)).filter((item) => item !== undefined);

        if (answer.kind === 'auth') {
            this.blocked = 'auth';

            return;
        }

        if (answer.kind === 'forbidden') {
            if (answer.data?.error === 'invalid_csrf_token') {
                this.blocked = 'csrf';

                return;
            }

            // No rights for this round's event (any more): its changes wait apart - never the whole device
            for (const item of this.items) {
                if (item.roundId === roundId && item.state === 'pending' && !this.inFlight.has(item.id)) {
                    item.state = 'forbidden';
                    item.attempts = 0;
                    item.nextAttemptAt = 0;
                    item.lastError = { kind: 'forbidden', status: answer.status ?? 403 };
                    await this.write(item).catch(() => {});
                }
            }

            return;
        }

        if (answer.kind === 'offline') {
            this.offline = true;
            this.offlineAttempts += 1;
            this.retryAt = now + backoff(OFFLINE_BACKOFF_MS, this.offlineAttempts);

            return;
        }

        this.offline = false;
        this.offlineAttempts = 0;
        this.retryAt = 0;

        if (answer.kind === 'server' && answer.busy === true) {
            // No verdict on any change - everything waits, as long as the server asks (Retry-After) or a growing pause
            this.busy = true;
            this.busyAttempts += 1;
            const pause = typeof answer.retryAfter === 'number' && answer.retryAfter > 0
                ? Math.min(answer.retryAfter, MAX_RETRY_AFTER_MS)
                : backoff(BUSY_BACKOFF_MS, this.busyAttempts);
            this.retryAt = now + pause;

            return;
        }

        this.busy = false;
        this.busyAttempts = 0;

        if (answer.kind === 'client' && answer.data?.error === 'round_not_found') {
            // The round is gone: none of its changes can be saved any more - each kept with the reason until discarded
            for (const item of this.items) {
                if (item.roundId === roundId && item.state === 'pending' && !this.inFlight.has(item.id)) {
                    item.state = 'rejected';
                    item.reason = 'round_not_found';
                    item.message = answer.data?.message ?? null;
                    item.lastError = { kind: 'client', status: answer.status ?? null };
                    await this.write(item).catch(() => {});
                }
            }

            this.isolate = false;

            return;
        }

        if (answer.kind === 'server' || answer.kind === 'client') {
            if (units > 1) {
                // Which change does the server choke on? One at a time (a group as one) from here
                this.isolate = true;

                return;
            }

            // A single change, or one group - it pauses or is refused as a whole
            for (const item of batch) {
                if (answer.kind === 'server') {
                    item.attempts += 1;
                    item.nextAttemptAt = now + Math.max(backoff(SERVER_BACKOFF_MS, item.attempts), Math.min(answer.retryAfter ?? 0, MAX_RETRY_AFTER_MS));
                    item.lastError = { kind: 'server', status: answer.status ?? null };
                } else {
                    // A change the server cannot read at all
                    item.state = 'rejected';
                    item.reason = answer.data?.error ?? 'invalid_change';
                    item.message = answer.data?.message ?? null;
                    item.lastError = { kind: 'client', status: answer.status ?? null };
                    this.isolate = false;
                }

                await this.write(item).catch(() => {});
            }

            return;
        }

        // ok
        this.isolate = false;
        const outcomes = new Map((answer.data?.outcomes ?? []).map((outcome) => [String(outcome.clientChangeId).toLowerCase(), outcome]));
        const createdEntries = new Set();

        for (const item of batch) {
            const outcome = outcomes.get(item.id.toLowerCase());

            if (outcome === undefined) {
                item.attempts += 1;
                item.nextAttemptAt = now + backoff(SERVER_BACKOFF_MS, item.attempts);
                item.lastError = { kind: 'server', status: null };
                await this.write(item).catch(() => {});
                continue;
            }

            if (outcome.status === 'applied' || outcome.status === 'unchanged') {
                if (item.newEntry) {
                    createdEntries.add(item.newEntry.clientEntryId);
                }

                this.items = this.items.filter((candidate) => candidate.id !== item.id);
                await this.erase(item.id);
                continue;
            }

            if (outcome.status === 'conflict') {
                item.state = 'conflict';
                item.current = outcome.current ?? null;
                item.enteredBy = outcome.enteredBy ?? null;
                item.enteredAt = outcome.enteredAt ?? null;
            } else {
                item.state = 'rejected';
                item.reason = outcome.reason ?? 'invalid_change';
                item.message = outcome.message ?? null;
            }

            item.attempts = 0;
            item.nextAttemptAt = 0;
            item.lastError = null;
            await this.write(item).catch(() => {});
        }

        // A new entry exists on the server now - its other changes address it by ref
        for (const item of this.items) {
            if (item.newEntry && createdEntries.has(item.newEntry.clientEntryId) && !this.inFlight.has(item.id)) {
                item.newEntry = null;
                await this.write(item).catch(() => {});
            }
        }

        if (Array.isArray(answer.data?.entries)) {
            this.onEntries(answer.data.entries, roundId);
        }
    }
}
