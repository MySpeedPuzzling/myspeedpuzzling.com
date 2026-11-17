// Runs the live result entry's device copies (competition toolkit C4: assets/live_entry_snapshot.js,
// live_entry_draft.js, live_entry_device.js) through their scenarios and prints {scenario: "ok" | error} as JSON -
// tests/LiveEntryOfflineScriptsTest.php asserts every one is "ok". Snapshots run on an in-memory store with a fake
// clock, fake timers and a fake device cookie; drafts and preferences on a fake Web Storage.

import assert from 'node:assert/strict';
import {
    createMemorySnapshotStorage,
    PREFETCH_GAP_MS,
    prefetchRounds,
    prefetchTargets,
    SNAPSHOT_RETENTION_MS,
    SNAPSHOT_WRITE_DELAY_MS,
    snapshotKey,
    snapshotOf,
    SnapshotStore,
    SNAPSHOTS_PER_PLAYER,
} from '../assets/live_entry_snapshot.js';
import {
    clearDraft,
    DRAFT_MAX_AGE_MS,
    draftKey,
    LEGACY_KEYBOARD_KEY,
    normaliseDraft,
    PREFS_KEY,
    readDraft,
    readPrefs,
    writeDraft,
    writePrefs,
} from '../assets/live_entry_draft.js';
import {
    DEVICE_MARKER_KEY,
    devicePlayerOf,
    DRAFT_KEY_PREFIX,
    forgetDraftsExcept,
    leftLiveEntryCleanup,
    samePlayer,
    SNAPSHOT_DATABASE,
} from '../assets/live_entry_device.js';

const ANNA = '018d0020-0000-0000-0000-00000000a001';
const BEN = '018d0020-0000-0000-0000-00000000b002';
const COMPETITION = '018d0020-0000-0000-0000-000000000c01';
const ROUND_A = '018d0020-0000-0000-0000-000000000101';
const ROUND_B = '018d0020-0000-0000-0000-000000000102';
const ROUND_C = '018d0020-0000-0000-0000-000000000103';
const T0 = Date.parse('2026-11-21T09:00:00Z');

function fakeStorage(initial = {}) {
    const items = new Map(Object.entries(initial));

    return {
        get length() {
            return items.size;
        },
        key(index) {
            return [...items.keys()][index] ?? null;
        },
        getItem(key) {
            return items.has(key) ? items.get(key) : null;
        },
        setItem(key, value) {
            items.set(key, String(value));
        },
        removeItem(key) {
            items.delete(key);
        },
        items,
    };
}

function brokenStorage() {
    const fail = () => {
        throw new Error('SecurityError');
    };

    return { get length() { return fail(); }, key: fail, getItem: fail, setItem: fail, removeItem: fail };
}

function fakeTimers() {
    const timers = new Map();
    let next = 1;

    return {
        schedule(task, ms) {
            const id = next++;
            timers.set(id, { task, ms });

            return id;
        },
        cancel(id) {
            timers.delete(id);
        },
        runAll() {
            const due = [...timers.values()];
            timers.clear();
            due.forEach(({ task }) => task());
        },
        get count() {
            return timers.size;
        },
    };
}

function roundState(roundId, extra = {}) {
    return {
        serverNowMs: T0,
        competition: { id: COMPETITION, name: 'Cup', isOnline: false },
        round: { id: roundId, name: 'Group A', stopwatch: { status: 'running', startedAt: '2026-11-21T08:50:00+00:00', stoppedAt: null } },
        rounds: [],
        entries: [{ ref: 'participant_round:1', name: 'Anna', result: { seconds: 1234 } }],
        viewer: { playerId: ANNA, organiser: false },
        mercure: { hubUrl: 'https://hub.test', token: 'secret-token', expiresAt: T0 + 3600000 },
        ...extra,
    };
}

function setup({ storage = createMemorySnapshotStorage(), playerId = ANNA, cookie = ANNA, marker = null } = {}) {
    const clock = { now: T0 };
    const timers = fakeTimers();
    const device = { cookie };
    const store = new SnapshotStore({
        storage,
        playerId,
        devicePlayer: () => device.cookie,
        now: () => clock.now,
        marker,
        schedule: timers.schedule,
        cancel: timers.cancel,
    });

    return { store, storage, clock, timers, device };
}

const scenarios = {
    async 'a snapshot never keeps the stream token and is a copy'() {
        const state = roundState(ROUND_A);
        const record = snapshotOf({ playerId: ANNA.toUpperCase(), roundId: ROUND_A, state, savedAt: T0, clockOffsetMs: -412, clockMeasuredAt: T0 - 5 });

        assert.equal(record.key, `${ANNA}:${ROUND_A}`);
        assert.equal(record.playerId, ANNA);
        assert.equal(record.competitionId, COMPETITION);
        assert.equal(record.clockOffsetMs, -412);
        assert.equal('mercure' in record.state, false);
        assert.equal(JSON.stringify(record).includes('secret-token'), false);
        state.entries[0].name = 'changed';
        assert.equal(record.state.entries[0].name, 'Anna');
        assert.equal(snapshotOf({ playerId: ANNA, roundId: ROUND_A, state, savedAt: T0, clockOffsetMs: NaN }).clockOffsetMs, null);
    },

    async 'a write waits a moment and the last state of the round wins'() {
        const { store, storage, timers, clock } = setup();

        assert.equal(store.save({ roundId: ROUND_A, state: roundState(ROUND_A) }), true);
        clock.now += 500;
        store.save({ roundId: ROUND_A, state: roundState(ROUND_A, { entries: [] }) });

        assert.equal(timers.count, 1, 'the earlier write was replaced');
        assert.deepEqual(await storage.getAll(), [], 'nothing stored before the pause');

        // The page reads what waits
        assert.deepEqual((await store.read(ROUND_A)).state.entries, []);

        timers.runAll();
        await store.queue;
        const stored = await storage.getAll();
        assert.equal(stored.length, 1);
        assert.deepEqual(stored[0].state.entries, []);
        assert.equal(stored[0].savedAt, T0 + 500);
        assert.equal(SNAPSHOT_WRITE_DELAY_MS, 2000);
    },

    async 'flush writes what waits at once (the page is hidden or closed)'() {
        const { store, storage, timers } = setup();

        store.save({ roundId: ROUND_A, state: roundState(ROUND_A) });
        store.save({ roundId: ROUND_B, state: roundState(ROUND_B) });
        await store.flush();

        assert.equal(timers.count, 0);
        assert.deepEqual((await storage.getAll()).map((record) => record.roundId).sort(), [ROUND_A, ROUND_B]);
    },

    async 'a snapshot is read back only for its player and round'() {
        const storage = createMemorySnapshotStorage([
            snapshotOf({ playerId: BEN, roundId: ROUND_A, state: roundState(ROUND_A, { viewer: { playerId: BEN } }), savedAt: T0 }),
        ]);
        const { store } = setup({ storage });

        assert.equal(await store.read(ROUND_A), null, "Ben's snapshot is never Anna's");

        await store.saveNow({ roundId: ROUND_A, state: roundState(ROUND_A) });
        const mine = await store.read(ROUND_A);
        assert.equal(mine.playerId, ANNA);
        assert.equal(mine.state.entries[0].name, 'Anna');
        assert.equal(await store.read(ROUND_B), null);
    },

    async 'nothing is read or written when the device names nobody or another player'() {
        const { store, storage, device, timers } = setup();

        await store.saveNow({ roundId: ROUND_A, state: roundState(ROUND_A) });

        device.cookie = null;
        assert.equal(await store.read(ROUND_A), null, 'signed out: no snapshot shown');
        assert.equal(store.save({ roundId: ROUND_A, state: roundState(ROUND_A) }), false);
        assert.equal(await store.saveNow({ roundId: ROUND_B, state: roundState(ROUND_B) }), false);
        assert.deepEqual(await store.list(), []);

        device.cookie = BEN;
        assert.equal(await store.read(ROUND_A), null, 'another player signed in');

        // A write waiting when the device changes hands is dropped
        device.cookie = ANNA;
        store.save({ roundId: ROUND_C, state: roundState(ROUND_C) });
        device.cookie = BEN;
        timers.runAll();
        await store.queue;
        assert.deepEqual((await storage.getAll()).map((record) => record.roundId), [ROUND_A]);
    },

    async 'start deletes other players, old snapshots and those over the cap, and marks the device'() {
        const records = [
            snapshotOf({ playerId: BEN, roundId: ROUND_A, state: roundState(ROUND_A), savedAt: T0 }),
            snapshotOf({ playerId: ANNA, roundId: ROUND_A, state: roundState(ROUND_A), savedAt: T0 - SNAPSHOT_RETENTION_MS - 1 }),
            snapshotOf({ playerId: ANNA, roundId: ROUND_B, state: roundState(ROUND_B), savedAt: T0 - SNAPSHOT_RETENTION_MS + 1000 }),
        ];

        for (let i = 0; i < SNAPSHOTS_PER_PLAYER + 2; i += 1) {
            records.push(snapshotOf({ playerId: ANNA, roundId: `round-${i}`, state: roundState(`round-${i}`), savedAt: T0 - 1000 * (i + 1) }));
        }

        const marker = fakeStorage();
        const { store, storage } = setup({ storage: createMemorySnapshotStorage(records), marker });
        const removed = await store.start();
        const kept = await storage.getAll();

        assert.equal(kept.length, SNAPSHOTS_PER_PLAYER);
        assert.equal(removed, records.length - SNAPSHOTS_PER_PLAYER);
        assert.ok(kept.every((record) => record.playerId === ANNA));
        assert.ok(!kept.some((record) => record.roundId === ROUND_A), 'older than 36 h');
        assert.ok(!kept.some((record) => record.roundId === ROUND_B), 'the oldest over the cap');
        assert.ok(!kept.some((record) => record.roundId === `round-${SNAPSHOTS_PER_PLAYER + 1}`));
        assert.equal(marker.getItem(DEVICE_MARKER_KEY), ANNA);
        assert.equal(SNAPSHOT_RETENTION_MS, 36 * 3600 * 1000);
    },

    async 'start without the device cookie deletes every snapshot'() {
        const storage = createMemorySnapshotStorage([
            snapshotOf({ playerId: ANNA, roundId: ROUND_A, state: roundState(ROUND_A), savedAt: T0 }),
            snapshotOf({ playerId: BEN, roundId: ROUND_B, state: roundState(ROUND_B), savedAt: T0 }),
        ]);
        const marker = fakeStorage();
        const { store } = setup({ storage, cookie: null, marker });

        assert.equal(await store.start(), 2);
        assert.deepEqual(await storage.getAll(), []);
        assert.equal(marker.getItem(DEVICE_MARKER_KEY), null, 'no player to mark');
    },

    async 'a snapshot older than 36 hours is not read'() {
        const { store, clock } = setup();

        await store.saveNow({ roundId: ROUND_A, state: roundState(ROUND_A) });
        clock.now += SNAPSHOT_RETENTION_MS + 1;
        assert.equal(await store.read(ROUND_A), null);
    },

    async 'list gives the newest of each round of the event'() {
        const { store, clock } = setup();

        await store.saveNow({ roundId: ROUND_A, state: roundState(ROUND_A) });
        clock.now += 1000;
        await store.saveNow({ roundId: ROUND_B, state: roundState(ROUND_B) });
        clock.now += 1000;
        await store.saveNow({ roundId: ROUND_C, state: roundState(ROUND_C, { competition: { id: 'other' } }) });
        clock.now += 1000;
        store.save({ roundId: ROUND_A, state: roundState(ROUND_A, { entries: [] }) });

        const list = await store.list({ competitionId: COMPETITION });
        assert.deepEqual(list.map((record) => record.roundId), [ROUND_A, ROUND_B]);
        assert.deepEqual(list[0].state.entries, [], 'the waiting write is the newest');
        assert.equal((await store.list()).length, 3);
    },

    async 'a store that refuses writes keeps the snapshot in memory'() {
        const storage = createMemorySnapshotStorage();
        storage.put = async () => {
            throw new Error('QuotaExceededError');
        };
        const { store } = setup({ storage });

        assert.equal(await store.saveNow({ roundId: ROUND_A, state: roundState(ROUND_A) }), true);
        assert.equal(store.storageFailed, true);
        assert.equal((await store.read(ROUND_A)).roundId, ROUND_A, 'read from memory');
        assert.equal((await store.list()).length, 1);
    },

    async 'a store that fails entirely leaves the page working'() {
        const fail = async () => {
            throw new Error('InvalidStateError');
        };
        const { store } = setup({ storage: { getAll: fail, get: fail, put: fail, delete: fail } });

        assert.equal(await store.start(), 0);
        assert.equal(await store.read(ROUND_A), null);
        await store.saveNow({ roundId: ROUND_A, state: roundState(ROUND_A) });
        assert.equal((await store.read(ROUND_A)).roundId, ROUND_A);
        await store.wipe();
        assert.equal(await store.read(ROUND_A), null, 'wipe empties memory too');
    },

    async 'prefetch picks the nearest other rounds within 12 hours, at most 4'() {
        const hour = 3600 * 1000;
        const at = (offset) => new Date(T0 + offset).toISOString();
        const rounds = [
            { id: 'current', startsAt: at(0) },
            { id: 'in-1h', startsAt: at(hour) },
            { id: 'before-2h', startsAt: at(-2 * hour) },
            { id: 'in-3h', startsAt: at(3 * hour) },
            { id: 'before-11h', startsAt: at(-11 * hour) },
            { id: 'in-12h', startsAt: at(12 * hour) },
            { id: 'in-13h', startsAt: at(13 * hour) },
            { id: 'yesterday', startsAt: at(-30 * hour) },
            { id: 'no-date' },
            { id: 'bad-date', startsAt: 'soon' },
        ];

        assert.deepEqual(prefetchTargets(rounds, 'current', T0), ['in-1h', 'before-2h', 'in-3h', 'before-11h']);
        assert.deepEqual(prefetchTargets(rounds, 'current', T0, { max: 10 }), ['in-1h', 'before-2h', 'in-3h', 'before-11h', 'in-12h']);
        assert.deepEqual(prefetchTargets(null, 'current', T0), []);
    },

    async 'prefetch asks one round at a time, apart, and stores each answer without its token'() {
        const { store, storage } = setup();
        const waits = [];
        const asked = [];
        const stored = await prefetchRounds({
            roundIds: [ROUND_B, ROUND_C],
            store,
            wait: async (ms) => {
                waits.push(ms);
            },
            fetchState: async (roundId) => {
                asked.push(roundId);

                return roundState(roundId);
            },
            extra: () => ({ clockOffsetMs: 250 }),
        });

        assert.deepEqual(stored, [ROUND_B, ROUND_C]);
        assert.deepEqual(asked, [ROUND_B, ROUND_C]);
        assert.deepEqual(waits, [PREFETCH_GAP_MS]);
        const records = await storage.getAll();
        assert.equal(records.length, 2);
        assert.ok(records.every((record) => record.clockOffsetMs === 250 && !('mercure' in record.state)));
    },

    async 'prefetch stops at a failed request or when told to'() {
        const { store } = setup();
        const asked = [];
        const fetchState = async (roundId) => {
            asked.push(roundId);

            if (roundId === ROUND_B) {
                throw new Error('offline');
            }

            return roundState(roundId);
        };

        assert.deepEqual(await prefetchRounds({ roundIds: [ROUND_A, ROUND_B, ROUND_C], store, wait: async () => {}, fetchState }), [ROUND_A]);
        assert.deepEqual(asked, [ROUND_A, ROUND_B]);

        let stop = false;
        asked.length = 0;
        const stored = await prefetchRounds({
            roundIds: [ROUND_A, ROUND_C],
            store,
            wait: async () => {
                stop = true;
            },
            fetchState,
            shouldStop: () => stop,
        });
        assert.deepEqual(stored, [ROUND_A]);
        assert.deepEqual(asked, [ROUND_A]);
    },

    async 'the device cookie names a player or nobody'() {
        assert.equal(devicePlayerOf(`a=1; msp_live_player=${ANNA.toUpperCase()}; b=2`), ANNA);
        assert.equal(devicePlayerOf(`msp_live_player=${ANNA}`), ANNA);
        assert.equal(devicePlayerOf('msp_live_player=not-an-id'), null);
        assert.equal(devicePlayerOf('xmsp_live_player=' + ANNA), null);
        assert.equal(devicePlayerOf('msp_live_player=%E0%A4%A'), null);
        assert.equal(devicePlayerOf(''), null);
        assert.equal(devicePlayerOf(undefined), null);
        assert.equal(samePlayer(ANNA, ANNA.toUpperCase()), true);
        assert.equal(samePlayer(null, null), false);
        assert.equal(samePlayer('', ''), false);
    },

    async 'other pages wipe the copies after a sign-out or another player signing in'() {
        const deleted = [];
        const indexedDB = { deleteDatabase: (name) => deleted.push(name) };
        const drafts = () => fakeStorage({ [draftKey(ANNA)]: '{}', 'unrelated': '1' });

        // A device that never opened the live entry: nothing
        let local = fakeStorage();
        assert.equal(leftLiveEntryCleanup({ cookie: '', local, session: drafts(), indexedDB }), false);

        // Still the same player
        local = fakeStorage({ [DEVICE_MARKER_KEY]: ANNA });
        assert.equal(leftLiveEntryCleanup({ cookie: `msp_live_player=${ANNA}`, local, session: drafts(), indexedDB }), false);
        assert.deepEqual(deleted, []);

        // Signed out: the cookie is gone
        const session = drafts();
        assert.equal(leftLiveEntryCleanup({ cookie: 'other=1', local, session, indexedDB }), true);
        assert.deepEqual(deleted, [SNAPSHOT_DATABASE]);
        assert.equal(local.getItem(DEVICE_MARKER_KEY), null);
        assert.equal(session.getItem(draftKey(ANNA)), null);
        assert.equal(session.getItem('unrelated'), '1', 'nothing else touched');

        // Another player signed in
        local = fakeStorage({ [DEVICE_MARKER_KEY]: ANNA });
        assert.equal(leftLiveEntryCleanup({ cookie: `msp_live_player=${BEN}`, local, session: drafts(), indexedDB }), true);
        assert.equal(deleted.length, 2);

        // Storage refused (a private window): nothing breaks
        assert.equal(leftLiveEntryCleanup({ cookie: '', local: brokenStorage(), session: brokenStorage(), indexedDB }), false);
        assert.equal(leftLiveEntryCleanup({ cookie: '', local: fakeStorage({ [DEVICE_MARKER_KEY]: ANNA }), session: brokenStorage(), indexedDB: { deleteDatabase() { throw new Error('x'); } } }), true);
    },

    async "the live page forgets other players' drafts"() {
        const session = fakeStorage({ [draftKey(ANNA)]: '{}', [draftKey(BEN)]: '{}', 'msp.other': '1' });

        assert.equal(forgetDraftsExcept(session, ANNA), 1);
        assert.deepEqual([...session.items.keys()].sort(), [draftKey(ANNA), 'msp.other'].sort());
        assert.equal(forgetDraftsExcept(session, null), 1);
        assert.deepEqual([...session.items.keys()], ['msp.other']);
        assert.equal(forgetDraftsExcept(brokenStorage(), null), 0);
        assert.ok(draftKey(ANNA).startsWith(DRAFT_KEY_PREFIX));
    },

    async 'an opened entry with a typed time comes back after a reload'() {
        const session = fakeStorage();

        assert.equal(writeDraft(session, ANNA, { roundId: ROUND_A, ref: 'participant_round:1', view: 'entry', mode: 'time', typed: '5812', pieces: '' }, T0), true);
        const draft = readDraft(session, ANNA, ROUND_A, T0 + 60000);

        assert.equal(draft.ref, 'participant_round:1');
        assert.equal(draft.view, 'entry');
        assert.equal(draft.mode, 'time');
        assert.equal(draft.typed, '5812');
        assert.equal(draft.pending, null);
    },

    async 'pieces placed being typed come back'() {
        const session = fakeStorage();

        writeDraft(session, ANNA, { roundId: ROUND_A, ref: 'team:7', view: 'entry', mode: 'pieces', typed: '', pieces: '480' }, T0);
        const draft = readDraft(session, ANNA, ROUND_A, T0);
        assert.equal(draft.mode, 'pieces');
        assert.equal(draft.pieces, '480');
    },

    async 'a value waiting on the confirm view comes back, each kind'() {
        const session = fakeStorage();

        for (const value of [{ seconds: 3492 }, { piecesPlaced: 480 }, { didNotStart: true }, null]) {
            writeDraft(session, ANNA, { roundId: ROUND_A, ref: 'participant_round:1', view: 'confirm', mode: 'time', typed: '', pieces: '', pending: { value } }, T0);
            const draft = readDraft(session, ANNA, ROUND_A, T0);

            assert.equal(draft.view, 'confirm');
            assert.deepEqual(draft.pending.value, value);
            assert.equal(draft.finishedNow, null);
        }

        assert.equal(writeDraft(session, ANNA, { roundId: ROUND_A, ref: 'x', view: 'confirm', pending: null }, T0), false, 'confirm needs the value');
        assert.equal(writeDraft(session, ANNA, { roundId: ROUND_A, ref: 'x', view: 'confirm', pending: { value: { seconds: -1 } } }, T0), false);
    },

    async 'a tapped "Finished now" comes back exactly as frozen'() {
        const session = fakeStorage();
        const tappedAt = T0 - 4321;

        writeDraft(session, ANNA, {
            roundId: ROUND_A,
            ref: 'participant_round:1',
            view: 'confirm',
            mode: 'time',
            typed: '',
            pieces: '',
            pending: { value: { seconds: 2899 } },
            finishedNow: { seconds: 2899, tappedAt },
        }, T0);

        // Minutes later: still the frozen value, never measured again
        const draft = readDraft(session, ANNA, ROUND_A, T0 + 5 * 60000);
        assert.deepEqual(draft.pending.value, { seconds: 2899 });
        assert.deepEqual(draft.finishedNow, { seconds: 2899, tappedAt });

        // A tap that does not match the value is no draft
        assert.equal(normaliseDraft({ ...draft, finishedNow: { seconds: 2900, tappedAt } }), null);
        assert.equal(normaliseDraft({ ...draft, finishedNow: { seconds: 2899 } }), null);
    },

    async 'a draft is restored only for the same player and round, within 10 minutes'() {
        const session = fakeStorage();
        const draft = { roundId: ROUND_A, ref: 'participant_round:1', view: 'entry', mode: 'time', typed: '1', pieces: '' };

        writeDraft(session, ANNA, draft, T0);
        assert.equal(readDraft(session, BEN, ROUND_A, T0), null, "Ben never gets Anna's draft");
        assert.equal(readDraft(session, ANNA, ROUND_B, T0), null, 'another round');
        assert.notEqual(session.getItem(draftKey(ANNA)), null, "kept for its round's page");
        assert.notEqual(readDraft(session, ANNA, ROUND_A, T0 + DRAFT_MAX_AGE_MS), null);
        assert.equal(readDraft(session, ANNA, ROUND_A, T0 + DRAFT_MAX_AGE_MS + 1), null, 'too old');
        assert.equal(session.getItem(draftKey(ANNA)), null, 'and gone');

        // Stored under Anna's key but naming Ben (a hand edit): not restored
        session.setItem(draftKey(ANNA), JSON.stringify({ ...draft, playerId: BEN, savedAt: T0, v: 1 }));
        assert.equal(readDraft(session, ANNA, ROUND_A, T0), null);
    },

    async 'Save, Back and Undo clear the draft; broken drafts and storage are ignored'() {
        const session = fakeStorage();

        writeDraft(session, ANNA, { roundId: ROUND_A, ref: 'participant_round:1', view: 'entry' }, T0);
        clearDraft(session, ANNA);
        assert.equal(readDraft(session, ANNA, ROUND_A, T0), null);

        session.setItem(draftKey(ANNA), '{not json');
        assert.equal(readDraft(session, ANNA, ROUND_A, T0), null);
        session.setItem(draftKey(ANNA), JSON.stringify({ roundId: ROUND_A, ref: 'x', view: 'somewhere', savedAt: T0, playerId: ANNA }));
        assert.equal(readDraft(session, ANNA, ROUND_A, T0), null);

        assert.equal(writeDraft(brokenStorage(), ANNA, { roundId: ROUND_A, ref: 'x', view: 'entry' }, T0), false);
        assert.equal(readDraft(brokenStorage(), ANNA, ROUND_A, T0), null);
        clearDraft(brokenStorage(), ANNA);
        assert.equal(writeDraft(session, '', { roundId: ROUND_A, ref: 'x', view: 'entry' }, T0), false, 'no player, no draft');
    },

    async 'the preferences are one stored setting, the keyboard taken over from the older key'() {
        const local = fakeStorage({ [LEGACY_KEYBOARD_KEY]: 'numeric' });
        const prefs = readPrefs(local);

        assert.deepEqual(prefs, { v: 1, keyboard: 'numeric', autoOpenTable: true, scanAfterSave: false, myTables: [] });
        assert.equal(local.getItem(LEGACY_KEYBOARD_KEY), null);
        assert.equal(JSON.parse(local.getItem(PREFS_KEY)).keyboard, 'numeric');

        assert.equal(writePrefs(local, { keyboard: 'text', scanAfterSave: true }).keyboard, 'text');
        assert.deepEqual(readPrefs(local), { v: 1, keyboard: 'text', autoOpenTable: true, scanAfterSave: true, myTables: [] });

        local.setItem(PREFS_KEY, JSON.stringify({ keyboard: 'qwerty', autoOpenTable: 'yes', myTables: [3, -1, 'x', 4] }));
        assert.deepEqual(readPrefs(local), { v: 1, keyboard: null, autoOpenTable: true, scanAfterSave: false, myTables: [3, 4] });

        assert.deepEqual(readPrefs(fakeStorage()), { v: 1, keyboard: null, autoOpenTable: true, scanAfterSave: false, myTables: [] });
        assert.equal(readPrefs(brokenStorage()).keyboard, null);
        assert.equal(writePrefs(brokenStorage(), { keyboard: 'numeric' }).keyboard, 'numeric', 'this page keeps it');
    },

    async 'the snapshot key is per player and round'() {
        assert.equal(snapshotKey(ANNA.toUpperCase(), ROUND_A), `${ANNA}:${ROUND_A}`);
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
