// Runs the browser modules of the live result entry (assets/official_results_outbox.js, official_results_live.js,
// official_results_scan.js, official_results_time.js) through their scenarios and prints {scenario: "ok" | error} as
// JSON - tests/LiveResultsScriptsTest.php asserts every one is "ok". The outbox runs on an in-memory store with a
// fake clock and a fake server.

import assert from 'node:assert/strict';
import { createMemoryStorage, createTabLock, Outbox, STUCK_AFTER_ATTEMPTS } from '../assets/official_results_outbox.js';
import { isGone, officialResultsRequest, retryAfterMs } from '../assets/official_results_api.js';
import {
    buildSearchIndex,
    describeResult,
    entryForEnter,
    entryOfParticipant,
    foldText,
    keepWithheldPlayers,
    preferredRound,
    recentEntries,
    sameValue,
    searchEntries,
    shownValue,
} from '../assets/official_results_live.js';
import { parseNameTagUrl } from '../assets/official_results_scan.js';
import { formatResultTime, parseResultTime } from '../assets/official_results_time.js';

const ROUND_A = '018d0020-0000-0000-0000-000000000101';
const ROUND_B = '018d0020-0000-0000-0000-000000000102';
const ANNA = 'participant_round:018d0020-0000-0000-0000-000000000301';
const BEN = 'participant_round:018d0020-0000-0000-0000-000000000302';
const CARA = 'participant_round:018d0020-0000-0000-0000-000000000303';

/**
 * A fake server: answers every change with `decide(change)` (default: applied) unless `answer` is set for the
 * next request(s); records what it was sent.
 */
function fakeServer() {
    const server = {
        requests: [],
        log: null,
        answers: [],
        decide: () => ({ status: 'applied' }),
        async send(roundId, changes) {
            server.log?.push('send');
            server.requests.push({ roundId, changes: JSON.parse(JSON.stringify(changes)) });

            if (server.answers.length > 0) {
                const answer = server.answers.shift();

                if (answer !== 'decide') {
                    return answer;
                }
            }

            return {
                kind: 'ok',
                status: 200,
                data: {
                    dryRun: false,
                    outcomes: changes.map((change) => ({ clientChangeId: change.clientChangeId, entry: change.entry ?? null, field: change.field, ...server.decide(change) })),
                    entries: [],
                },
            };
        },
    };

    return server;
}

function setup({ storage = createMemoryStorage(), userId = 'referee-1', lock = null, batchSize } = {}) {
    const clock = { now: 1_800_000_000_000 };
    const server = fakeServer();
    let ids = 0;
    const outbox = new Outbox({
        storage,
        userId,
        send: (roundId, changes) => server.send(roundId, changes),
        now: () => clock.now,
        newId: () => `00000000-0000-4000-8000-${String(++ids).padStart(12, '0')}`,
        lock,
        batchSize,
    });

    return { outbox, server, clock, storage };
}

async function save(outbox, entryRef, to, { roundId = ROUND_A, field = 'result', serverValue = null, newEntry = null } = {}) {
    const { item, persisted } = outbox.enqueue({ roundId, entryRef, newEntry, field, serverValue, to });
    await persisted;

    return item;
}

const scenarios = {
    async 'a change is stored before the network sees it'() {
        const log = [];
        const memory = createMemoryStorage();
        const storage = { ...memory, put: async (item) => { log.push('put'); await memory.put(item); } };
        const { outbox, server } = setup({ storage });
        server.log = log;

        const { persisted } = outbox.enqueue({ roundId: ROUND_A, entryRef: ANNA, field: 'result', serverValue: null, to: { seconds: 5025 } });
        // A flush asked for before the write finished still waits for it
        const flushed = outbox.flush();
        await persisted;
        await flushed;
        await outbox.flush();

        assert.deepEqual(log.slice(0, 2), ['put', 'send']);
    },

    async 'changes go in the order saved, one batch per round'() {
        const { outbox, server } = setup();
        await save(outbox, ANNA, { seconds: 100 });
        await save(outbox, BEN, { seconds: 200 }, { roundId: ROUND_B });
        await save(outbox, CARA, { seconds: 300 });
        await save(outbox, ANNA, 7, { field: 'table_number' });

        await outbox.flush();

        assert.equal(server.requests.length, 2);
        assert.equal(server.requests[0].roundId, ROUND_A);
        assert.deepEqual(server.requests[0].changes.map((change) => [change.entry, change.field]), [[ANNA, 'result'], [CARA, 'result'], [ANNA, 'table_number']]);
        assert.equal(server.requests[1].roundId, ROUND_B);
        assert.equal(outbox.hasUnsent(), false);
    },

    async 'applied and unchanged changes leave the device'() {
        const { outbox, server, storage } = setup();
        server.decide = (change) => ({ status: change.entry === ANNA ? 'applied' : 'unchanged' });
        await save(outbox, ANNA, { seconds: 100 });
        await save(outbox, BEN, { seconds: 200 });

        await outbox.flush();

        assert.equal(outbox.all().length, 0);
        assert.equal((await storage.getAll()).length, 0);
        assert.equal(outbox.status().pending, 0);
    },

    async 'a second save of a waiting entry continues from the first'() {
        const { outbox, server } = setup();
        await save(outbox, ANNA, { seconds: 100 }, { serverValue: { seconds: 50 } });
        await save(outbox, ANNA, { seconds: 120 }, { serverValue: { seconds: 50 } });

        await outbox.flush();

        assert.deepEqual(server.requests[0].changes.map((change) => [change.from, change.to]), [[{ seconds: 50 }, { seconds: 100 }], [{ seconds: 100 }, { seconds: 120 }]]);
    },

    async 'a conflict is kept until the referee keeps theirs'() {
        const { outbox, server } = setup();
        server.decide = () => ({ status: 'conflict', reason: 'changed_meanwhile', current: { seconds: 4000 }, enteredBy: { playerId: 'p2', name: 'Eva' }, enteredAt: '2026-10-07T10:42:00+00:00' });
        const mine = await save(outbox, ANNA, { seconds: 3600 });

        await outbox.flush();

        const conflict = outbox.all()[0];
        assert.equal(conflict.state, 'conflict');
        assert.deepEqual(conflict.current, { seconds: 4000 });
        assert.equal(conflict.enteredBy.name, 'Eva');
        assert.equal(outbox.status().conflicts, 1);

        // A conflict holds nothing else back, and is not sent again by itself
        await outbox.flush();
        assert.equal(server.requests.length, 1);

        server.decide = () => ({ status: 'applied' });
        const replacement = await outbox.keepMine(mine.id);
        await outbox.flush();

        assert.notEqual(replacement.id, mine.id);
        assert.deepEqual(server.requests[1].changes[0].from, { seconds: 4000 });
        assert.deepEqual(server.requests[1].changes[0].to, { seconds: 3600 });
        assert.equal(outbox.hasUnsent(), false);
    },

    async 'taking theirs drops every change of that field'() {
        const { outbox, server } = setup();
        server.decide = () => ({ status: 'conflict', current: { seconds: 4000 } });
        const first = await save(outbox, ANNA, { seconds: 3600 });
        await save(outbox, ANNA, { seconds: 3700 });
        await save(outbox, ANNA, 4, { field: 'table_number' });

        await outbox.flush();
        await outbox.takeTheirs(first.id);

        assert.deepEqual(outbox.all().map((item) => item.field), ['table_number']);
    },

    async 'a new save over a conflict starts from the other device\'s value'() {
        const { outbox, server } = setup();
        server.decide = () => ({ status: 'conflict', current: { seconds: 4000 } });
        await save(outbox, ANNA, { seconds: 3600 });
        await outbox.flush();

        const fresh = await save(outbox, ANNA, { didNotStart: true }, { serverValue: { seconds: 1 } });

        assert.equal(outbox.all().length, 1);
        assert.deepEqual(fresh.from, { seconds: 4000 });
    },

    async 'a refused change waits for a fix; the fix replaces it'() {
        const { outbox, server } = setup();
        server.decide = () => ({ status: 'rejected', reason: 'invalid_pieces_placed', message: 'Pieces placed must be…' });
        await save(outbox, ANNA, { piecesPlaced: 1000 });

        await outbox.flush();

        assert.equal(outbox.all()[0].state, 'rejected');
        assert.equal(outbox.all()[0].message, 'Pieces placed must be…');
        assert.equal(outbox.status().rejected, 1);

        server.decide = () => ({ status: 'applied' });
        const fix = await save(outbox, ANNA, { piecesPlaced: 999 }, { serverValue: null });
        await outbox.flush();

        assert.equal(fix.from, null);
        assert.equal(outbox.hasUnsent(), false);
    },

    async 'signed out: sending stops, nothing is lost, retry sends'() {
        const { outbox, server } = setup();
        server.answers.push({ kind: 'auth', status: 302 });
        await save(outbox, ANNA, { seconds: 100 });
        await save(outbox, BEN, { seconds: 200 });

        await outbox.flush();

        assert.equal(outbox.status().blocked, 'auth');
        assert.equal(outbox.all().length, 2);
        assert.ok(outbox.all().every((item) => item.attempts === 0 && item.state === 'pending'));

        await outbox.flush();
        assert.equal(server.requests.length, 1, 'no request while signed out');

        await outbox.retryNow();
        assert.equal(server.requests.length, 2);
        assert.equal(outbox.hasUnsent(), false);
        assert.equal(outbox.status().blocked, null);
    },

    async 'no rights for one event sets its changes apart - the other rounds still go'() {
        // Yesterday's event (round A) the referee is no maintainer of any more; today's (round B) they are
        const { outbox, server } = setup();
        const sent = [];
        server.send = async (roundId, changes) => {
            sent.push(roundId);

            if (roundId === ROUND_A) {
                return { kind: 'forbidden', status: 403, data: { error: 'forbidden' } };
            }

            return { kind: 'ok', status: 200, data: { outcomes: changes.map((change) => ({ clientChangeId: change.clientChangeId, status: 'applied' })), entries: [] } };
        };
        await save(outbox, ANNA, { seconds: 3600 });
        await save(outbox, BEN, { seconds: 3700 }, { roundId: ROUND_B });

        await outbox.flush();

        assert.deepEqual(sent, [ROUND_A, ROUND_B]);
        assert.equal(outbox.status().blocked, null, 'never the whole device');
        assert.deepEqual(outbox.all().map((item) => [item.roundId, item.state]), [[ROUND_A, 'forbidden']]);
        assert.equal(outbox.status().forbidden, 1);
        assert.deepEqual(outbox.status().forbiddenRounds, [ROUND_A]);

        // Kept apart: a later save of round B is not held up by it, round A is not asked again on its own
        await save(outbox, CARA, { seconds: 3800 }, { roundId: ROUND_B });
        await outbox.flush();
        assert.deepEqual(sent, [ROUND_A, ROUND_B, ROUND_B]);

        // Retry asks once more (the rights may be back); the referee may also let them go
        await outbox.retryNow();
        assert.deepEqual(sent, [ROUND_A, ROUND_B, ROUND_B, ROUND_A]);
        assert.equal(outbox.all()[0].state, 'forbidden');

        assert.equal(await outbox.discardWhere((item) => item.state === 'forbidden'), 1);
        assert.equal(outbox.hasUnsent(), false);
    },

    async 'a page whose security token is refused stops sending, nothing is lost'() {
        const { outbox, server } = setup();
        server.answers.push({ kind: 'forbidden', status: 403, data: { error: 'invalid_csrf_token' } });
        await save(outbox, ANNA, { seconds: 100 });
        await save(outbox, BEN, { seconds: 200 }, { roundId: ROUND_B });

        await outbox.flush();

        assert.equal(outbox.status().blocked, 'csrf');
        assert.equal(server.requests.length, 1);
        assert.ok(outbox.all().every((item) => item.state === 'pending'));

        await outbox.retryNow();
        assert.equal(outbox.hasUnsent(), false);
    },

    async 'a busy server (429, a ban page) refuses nothing - everything waits as long as it asks'() {
        const { outbox, server, clock } = setup();
        server.answers.push({ kind: 'server', status: 429, busy: true, retryAfter: 30000 });
        await save(outbox, ANNA, { seconds: 3600 });
        await save(outbox, BEN, { seconds: 3700 });

        await outbox.flush();

        assert.equal(server.requests.length, 1, 'no batch split, no request per change');
        assert.ok(outbox.all().every((item) => item.state === 'pending' && item.attempts === 0), 'nothing refused');
        assert.equal(outbox.status().busy, true);

        clock.now += 29000;
        await outbox.flush();
        assert.equal(server.requests.length, 1, 'Retry-After honoured');

        clock.now += 1000;
        await outbox.flush();
        assert.equal(server.requests.length, 2);
        assert.equal(outbox.hasUnsent(), false);
        assert.equal(outbox.status().busy, false);
    },

    async 'a busy server without Retry-After: a growing pause'() {
        const { outbox, server, clock } = setup();
        server.answers.push({ kind: 'server', status: 403, busy: true, retryAfter: null }, { kind: 'server', status: 429, busy: true, retryAfter: null });
        await save(outbox, ANNA, { seconds: 3600 });

        await outbox.flush();
        clock.now += 5000;
        await outbox.flush();
        assert.equal(server.requests.length, 2);

        clock.now += 5000;
        await outbox.flush();
        assert.equal(server.requests.length, 2, 'the second pause is longer');

        clock.now += 10000;
        await outbox.flush();
        assert.equal(server.requests.length, 3);
        assert.equal(outbox.hasUnsent(), false);
    },

    async 'a round that is gone refuses its waiting changes, with the server\'s text'() {
        const { outbox, server } = setup();
        server.answers.push({ kind: 'client', status: 404, data: { error: 'round_not_found', message: 'This round does not exist any more.' } });
        await save(outbox, ANNA, { seconds: 100 });
        await save(outbox, BEN, { seconds: 200 });
        await save(outbox, CARA, { seconds: 300 }, { roundId: ROUND_B });

        await outbox.flush();

        assert.deepEqual(server.requests.map((request) => [request.roundId, request.changes.length]), [[ROUND_A, 2], [ROUND_B, 1]]);
        assert.deepEqual(outbox.all().map((item) => [item.entryRef, item.state, item.reason, item.message]), [
            [ANNA, 'rejected', 'round_not_found', 'This round does not exist any more.'],
            [BEN, 'rejected', 'round_not_found', 'This round does not exist any more.'],
        ]);
    },

    async 'the JSON client: only our endpoint\'s JSON refuses, everything else is retried'() {
        const realFetch = globalThis.fetch;
        const respond = (status, body, headers = {}) => {
            globalThis.fetch = async () => new Response(body, { status, headers });
        };

        try {
            respond(429, '<html>Too many</html>', { 'Content-Type': 'text/html', 'Retry-After': '20' });
            assert.deepEqual(await officialResultsRequest('/x'), { kind: 'server', status: 429, retryAfter: 20000, busy: true });

            respond(429, JSON.stringify({ error: 'rate_limited' }), { 'Content-Type': 'application/json' });
            assert.equal((await officialResultsRequest('/x')).busy, true, '429 is retried even with JSON');

            respond(408, '', {});
            assert.equal((await officialResultsRequest('/x')).kind, 'server');

            // A CrowdSec ban page: HTML 403 - no verdict on the rights
            respond(403, '<html>Banned</html>', { 'Content-Type': 'text/html' });
            assert.deepEqual(await officialResultsRequest('/x'), { kind: 'server', status: 403, retryAfter: null, busy: true });

            respond(403, JSON.stringify({ error: 'forbidden' }), { 'Content-Type': 'application/json' });
            assert.equal((await officialResultsRequest('/x')).kind, 'forbidden');

            respond(400, JSON.stringify({ error: 'invalid_changes', message: 'Translated' }), { 'Content-Type': 'application/json' });
            assert.deepEqual(await officialResultsRequest('/x'), { kind: 'client', status: 400, data: { error: 'invalid_changes', message: 'Translated' } });

            respond(404, JSON.stringify({ title: 'Not Found', status: 404 }), { 'Content-Type': 'application/problem+json' });
            assert.equal((await officialResultsRequest('/x')).kind, 'server', 'a 404 that is not our answer');
            assert.equal(isGone(await officialResultsRequest('/x')), false);
        } finally {
            globalThis.fetch = realFetch;
        }
    },

    async 'the JSON client: a deleted round or event is gone, never retried'() {
        const realFetch = globalThis.fetch;
        const respond = (status, body, headers = {}) => {
            globalThis.fetch = async () => new Response(body, { status, headers });
        };

        try {
            respond(404, JSON.stringify({ error: 'round_not_found', message: 'This round does not exist any more.' }), { 'Content-Type': 'application/json' });
            const round = await officialResultsRequest('/x');
            assert.deepEqual(round, { kind: 'client', status: 404, data: { error: 'round_not_found', message: 'This round does not exist any more.' } });
            assert.equal(isGone(round), true);

            respond(404, JSON.stringify({ error: 'competition_not_found', message: 'This event does not exist any more.' }), { 'Content-Type': 'application/json' });
            assert.equal(isGone(await officialResultsRequest('/x')), true);

            // Our other refusals are no "gone"
            respond(404, JSON.stringify({ error: 'entry_not_found' }), { 'Content-Type': 'application/json' });
            assert.equal(isGone(await officialResultsRequest('/x')), false);
            respond(400, JSON.stringify({ error: 'round_not_found' }), { 'Content-Type': 'application/json' });
            assert.equal(isGone(await officialResultsRequest('/x')), false);
            // An HTML 404 (not our endpoint's answer) is retried as before
            respond(404, '<html>Not found</html>', { 'Content-Type': 'text/html' });
            const html = await officialResultsRequest('/x');
            assert.equal(html.kind, 'server');
            assert.equal(isGone(html), false);

            respond(503, '', { 'Retry-After': '7' });
            assert.deepEqual(await officialResultsRequest('/x'), { kind: 'server', status: 503, retryAfter: 7000, busy: false });

            assert.equal(retryAfterMs('Wed, 21 Oct 2015 07:28:10 GMT', Date.parse('Wed, 21 Oct 2015 07:28:00 GMT')), 10000);
            assert.equal(retryAfterMs('soon'), null);
        } finally {
            globalThis.fetch = realFetch;
        }
    },

    async 'offline: retried after a growing pause'() {
        const { outbox, server, clock } = setup();
        server.answers.push({ kind: 'offline' }, { kind: 'offline' });
        await save(outbox, ANNA, { seconds: 100 });

        await outbox.flush();
        assert.equal(outbox.status().offline, true);
        assert.equal(server.requests.length, 1);

        clock.now += 500;
        await outbox.flush();
        assert.equal(server.requests.length, 1, 'still pausing');

        clock.now += 600;
        await outbox.flush();
        assert.equal(server.requests.length, 2);

        // Second failure: a longer pause (2 s)
        clock.now += 1500;
        await outbox.flush();
        assert.equal(server.requests.length, 2);

        clock.now += 600;
        await outbox.flush();
        assert.equal(server.requests.length, 3);
        assert.equal(outbox.hasUnsent(), false);
        assert.equal(outbox.status().offline, false);
    },

    async 'a server error isolates the batch; a failing change does not hold the others'() {
        const { outbox, server, clock } = setup();
        await save(outbox, ANNA, { seconds: 100 });
        await save(outbox, BEN, { seconds: 200 });
        await save(outbox, CARA, { seconds: 300 });
        server.answers.push({ kind: 'server', status: 500 }, { kind: 'server', status: 500 });

        await outbox.flush();

        // The batch of three, then one by one: Anna fails again, Ben and Cara go through
        assert.deepEqual(server.requests.map((request) => request.changes.length), [3, 1, 1, 1]);
        assert.deepEqual(outbox.all().map((item) => item.entryRef), [ANNA]);
        assert.equal(outbox.all()[0].attempts, 1);

        // Anna pauses (5 s), then goes once the server is fine
        await outbox.flush();
        assert.equal(server.requests.length, 4);
        clock.now += 5000;
        await outbox.flush();
        assert.equal(server.requests.length, 5);
        assert.equal(outbox.hasUnsent(), false);
    },

    async 'a change of the same field waits behind a pausing one; other entries go on'() {
        const { outbox, server, clock } = setup();
        await save(outbox, ANNA, { seconds: 100 });
        server.answers.push({ kind: 'server', status: 503 });
        await outbox.flush();
        assert.equal(outbox.all()[0].attempts, 1);

        await save(outbox, ANNA, { seconds: 110 });
        await save(outbox, BEN, { seconds: 200 });
        await outbox.flush();

        assert.deepEqual(server.requests[1].changes.map((change) => change.entry), [BEN]);
        assert.equal(outbox.all().length, 2);

        clock.now += 5000;
        await outbox.flush();
        assert.deepEqual(server.requests[2].changes.map((change) => change.to), [{ seconds: 100 }, { seconds: 110 }]);
        assert.equal(outbox.hasUnsent(), false);
    },

    async 'a change failing again and again counts as stuck'() {
        const { outbox, server, clock } = setup();
        await save(outbox, ANNA, { seconds: 100 });

        for (let attempt = 0; attempt < STUCK_AFTER_ATTEMPTS; attempt++) {
            server.answers.push({ kind: 'server', status: 500 });
            await outbox.flush();
            clock.now += 120000;
        }

        assert.equal(outbox.status().stuck, 1);
        assert.equal(outbox.status().pending, 1);
    },

    async 'a whole set the server cannot read is split up, the unreadable change refused alone'() {
        const { outbox, server } = setup();
        await save(outbox, ANNA, { seconds: 100 });
        await save(outbox, BEN, { seconds: 200 });
        server.answers.push({ kind: 'client', status: 400, data: { error: 'invalid_changes' } }, 'decide', { kind: 'client', status: 400, data: { error: 'invalid_changes', message: 'Bad' } });

        await outbox.flush();

        assert.deepEqual(server.requests.map((request) => request.changes.length), [2, 1, 1]);
        assert.equal(outbox.all().length, 1);
        assert.equal(outbox.all()[0].entryRef, BEN);
        assert.equal(outbox.all()[0].state, 'rejected');
        assert.equal(outbox.all()[0].message, 'Bad');
    },

    async 'undo of a waiting change just drops it'() {
        const { outbox, server } = setup();
        server.answers.push({ kind: 'offline' });
        const item = await save(outbox, ANNA, { seconds: 100 });
        await outbox.flush();

        const opposite = await outbox.undo(item);

        assert.equal(opposite, null);
        assert.equal(outbox.hasUnsent(), false);
    },

    async 'undo of a sent change saves the opposite one'() {
        const { outbox, server } = setup();
        const item = await save(outbox, ANNA, { seconds: 100 }, { serverValue: { seconds: 90 } });
        await outbox.flush();

        const opposite = await outbox.undo(item);
        await outbox.flush();

        assert.deepEqual([opposite.from, opposite.to], [{ seconds: 100 }, { seconds: 90 }]);
        assert.deepEqual(server.requests[1].changes[0].to, { seconds: 90 });
    },

    async 'a new entrant is sent with its details until the server has it'() {
        const { outbox, server } = setup();
        const newEntry = { clientEntryId: '11111111-1111-4111-8111-111111111111', kind: 'person', name: 'Jo Doe' };
        const ref = `participant_round:${newEntry.clientEntryId}`;
        server.answers.push('decide', { kind: 'offline' });
        server.decide = (change) => (change.field === 'table_number' ? { status: 'applied', entry: ref } : { status: 'applied' });
        await save(outbox, ref, 12, { field: 'table_number', newEntry });
        // Sent with the first change, which creates the entrant
        await outbox.flush();
        assert.deepEqual(server.requests[0].changes[0].newEntry, newEntry);
        assert.equal(server.requests[0].changes[0].entry, undefined);

        await save(outbox, ref, { seconds: 3000 }, { newEntry });
        assert.ok(outbox.all()[0].newEntry !== null, 'saved before the device knew');
        await outbox.flush();
        // Offline - still with the details, harmless: the server finds the entrant by its id
        assert.deepEqual(server.requests[1].changes[0].newEntry, newEntry);
    },

    async 'the details are dropped once the entrant exists'() {
        const { outbox, server } = setup();
        const newEntry = { clientEntryId: '22222222-2222-4222-8222-222222222222', kind: 'team', name: null, members: ['A', 'B'] };
        const ref = `team:${newEntry.clientEntryId}`;
        server.answers.push({ kind: 'server', status: 500 });
        await save(outbox, ref, 3, { field: 'table_number', newEntry });
        await save(outbox, ref, { seconds: 3000 }, { newEntry });

        // Batch fails → isolated: the table goes first and creates the pair, the result follows by ref
        await outbox.flush();

        assert.deepEqual(server.requests[1].changes[0].newEntry, newEntry);
        assert.equal(server.requests[2].changes[0].newEntry, undefined);
        assert.equal(server.requests[2].changes[0].entry, ref);
    },

    async 'two tabs share one store - one sends what the other saved'() {
        const storage = createMemoryStorage();
        const shared = new Map();
        const locks = { request: async (name, options, task) => {
            if (shared.get(name)) {
                return task(null);
            }

            shared.set(name, true);

            try {
                return await task({ name });
            } finally {
                shared.delete(name);
            }
        } };
        const lockA = createTabLock('outbox', { locks });
        const lockB = createTabLock('outbox', { locks });
        const tabA = setup({ storage, lock: lockA });
        const tabB = setup({ storage, lock: lockB });

        await save(tabB.outbox, BEN, { seconds: 200 });
        await tabA.outbox.flush();

        assert.equal(tabA.server.requests.length, 1);
        assert.equal(tabA.server.requests[0].changes[0].entry, BEN);
        await tabB.outbox.load();
        assert.equal(tabB.outbox.hasUnsent(), false);
    },

    async 'a tab without the lock sends nothing'() {
        const busy = async () => false;
        const { outbox, server } = setup({ lock: busy });
        await save(outbox, ANNA, { seconds: 100 });

        await outbox.flush();

        assert.equal(server.requests.length, 0);
        assert.equal(outbox.hasUnsent(), true);
    },

    async 'the localStorage lease stands in for Web Locks'() {
        const values = new Map();
        const storage = { getItem: (key) => values.get(key) ?? null, setItem: (key, value) => values.set(key, value), removeItem: (key) => values.delete(key) };
        const lockA = createTabLock('outbox', { locks: null, storage, now: () => 1000 });
        const lockB = createTabLock('outbox', { locks: null, storage, now: () => 1000 });
        let inner = null;

        const outer = await lockA(async () => {
            inner = await lockB(async () => {});
        });

        assert.equal(outer, true);
        assert.equal(inner, false);
        assert.equal(await lockB(async () => {}), true, 'released afterwards');
    },

    async 'another account\'s changes on the device are never sent'() {
        const storage = createMemoryStorage();
        const other = setup({ storage, userId: 'referee-2' });
        await save(other.outbox, ANNA, { seconds: 100 });

        const mine = setup({ storage, userId: 'referee-1' });
        await mine.outbox.load();
        await mine.outbox.flush();

        assert.equal(mine.server.requests.length, 0);
        assert.equal(await mine.outbox.foreignCount(), 1);
    },

    async 'a store that refuses writes still sends from memory'() {
        const memory = createMemoryStorage();
        const storage = { ...memory, put: async () => { throw new Error('QuotaExceededError'); } };
        const { outbox, server } = setup({ storage });

        const { persisted } = outbox.enqueue({ roundId: ROUND_A, entryRef: ANNA, field: 'result', serverValue: null, to: { seconds: 100 } });
        await assert.rejects(persisted);
        await outbox.flush();

        assert.equal(server.requests.length, 1);
        assert.equal(outbox.hasUnsent(), false);
        assert.equal(outbox.storageFailed, true);
    },

    async 'search: an exact table first, then tables starting with it, then names'() {
        const entries = [
            entry(ANNA, 'Anna Fast', 12),
            entry(BEN, 'Ben 12 Steady', 3),
            entry(CARA, 'Cara Tied', 120),
            entry('participant_round:4', 'Dan', 1),
        ];
        const found = searchEntries(buildSearchIndex(entries), '12');

        assert.equal(found.exactTable.ref, ANNA);
        assert.deepEqual(found.matches.map((item) => item.ref), [ANNA, CARA, BEN]);
        assert.equal(entryForEnter(found).ref, ANNA);

        // Table numbers off: digits are a name
        const byName = searchEntries(buildSearchIndex(entries), '12', { tableNumbersOff: true });
        assert.equal(byName.exactTable, null);
        assert.deepEqual(byName.matches.map((item) => item.ref), [BEN]);
        assert.equal(entryForEnter(byName).ref, BEN);
    },

    async 'search: names without accents, members, codes'() {
        const entries = [
            entry(ANNA, 'Kateřina Šťastná', 1, { playerCode: 'kata' }),
            { ...entry('team:1', 'Puzzle Sharks', 2), kind: 'team', members: [{ name: 'Łukasz Nowak', playerCode: 'luk' }, { name: 'Jiří Malý' }] },
            entry(BEN, 'Katie Holmes', 3),
        ];
        const index = buildSearchIndex(entries);

        assert.deepEqual(searchEntries(index, 'stastna').matches.map((item) => item.ref), [ANNA]);
        assert.deepEqual(searchEntries(index, 'kat').matches.map((item) => item.ref), [ANNA, BEN]);
        assert.deepEqual(searchEntries(index, 'lukasz').matches.map((item) => item.ref), ['team:1']);
        assert.deepEqual(searchEntries(index, 'jiri mal').matches.map((item) => item.ref), ['team:1']);
        assert.deepEqual(searchEntries(index, '#LU').matches.map((item) => item.ref), ['team:1']);
        assert.deepEqual(searchEntries(index, '#kata').matches.map((item) => item.ref), [ANNA]);
        assert.equal(entryForEnter(searchEntries(index, 'kat')), null, 'two matches - Enter opens nothing');
        assert.deepEqual(searchEntries(index, '   ').matches, []);
        assert.equal(foldText('Straße Øresund'), 'strasse oresund');
    },

    async 'shown values: the device\'s latest change over the server'() {
        const anna = entry(ANNA, 'Anna', 1, { result: { seconds: 50 } });

        assert.deepEqual(shownValue(anna, 'result', []), { value: { seconds: 50 }, item: null });
        const items = [
            { entryRef: ANNA, field: 'result', seq: 1, to: { seconds: 60 } },
            { entryRef: ANNA, field: 'result', seq: 2, to: { didNotStart: true } },
            { entryRef: ANNA, field: 'table_number', seq: 3, to: 9 },
        ];
        assert.deepEqual(shownValue(anna, 'result', items).value, { didNotStart: true });
        assert.equal(shownValue(anna, 'table_number', items).value, 9);
        assert.ok(sameValue({ seconds: 5 }, { seconds: 5 }));
        assert.ok(!sameValue({ seconds: 5 }, { piecesPlaced: 5 }));
        assert.ok(sameValue(null, undefined));
    },

    async 'recent entries: newest first, one row per entry, unsent ones included'() {
        const entries = [
            entry(ANNA, 'Anna', 1, { enteredAt: '2026-10-07T10:00:00+00:00' }),
            entry(BEN, 'Ben', 2, { enteredAt: '2026-10-07T10:05:00+00:00' }),
            entry(CARA, 'Cara', 3),
        ];
        const items = [{ entryRef: ANNA, field: 'result', seq: 1, createdAt: Date.parse('2026-10-07T10:10:00+00:00') }];

        assert.deepEqual(recentEntries(entries, items).map((row) => row.entry.ref), [ANNA, BEN]);
    },

    async 'results read as the organisers write them'() {
        const texts = { noResult: '–', piecesPlaced: '%placed% pcs', piecesPlacedOf: '%placed% / %pieces% pcs', didNotStart: 'Did not start' };

        assert.equal(describeResult({ seconds: 5025 }, 1000, texts), '1:23:45');
        assert.equal(describeResult({ piecesPlaced: 479 }, 500, texts), '479 / 500 pcs');
        assert.equal(describeResult({ piecesPlaced: 479 }, null, texts), '479 pcs');
        assert.equal(describeResult({ didNotStart: true }, 1000, texts), 'Did not start');
        assert.equal(describeResult(null, 1000, texts), '–');
    },

    async 'the time field: digits right-aligned, colons as typed, nothing carried'() {
        assert.deepEqual(parseResultTime('5812'), { seconds: 3492 });
        assert.deepEqual(parseResultTime('12345'), { seconds: 5025 });
        assert.deepEqual(parseResultTime('1:23:45'), { seconds: 5025 });
        assert.deepEqual(parseResultTime('58:12'), { seconds: 3492 });
        assert.deepEqual(parseResultTime('1:60:00'), { error: 'invalid' });
        assert.deepEqual(parseResultTime('6000'), { error: 'invalid' });
        assert.deepEqual(parseResultTime(''), { empty: true });
        assert.equal(formatResultTime(3492), '0:58:12');
    },

    async 'name tag QR: the event\'s ids from the URL, anything else is no name tag'() {
        const competition = '018d0020-0000-0000-0000-000000000001';
        const participant = '018d0020-0000-0000-0000-000000000201';

        assert.deepEqual(parseNameTagUrl(`https://myspeedpuzzling.com/en/live/${competition}/p/${participant}`), { competitionId: competition, participantId: participant });
        assert.deepEqual(parseNameTagUrl(`http://localhost:8080/cs/live/${competition.toUpperCase()}/p/${participant}?x=1`), { competitionId: competition, participantId: participant });
        assert.equal(parseNameTagUrl(`https://myspeedpuzzling.com/en/puzzle/${competition}`), null);
        assert.equal(parseNameTagUrl('4005555011897'), null);
        assert.equal(parseNameTagUrl(`javascript:alert(1)//live/${competition}/p/${participant}`), null);
        assert.equal(parseNameTagUrl(''), null);
    },

    async 'a name tag opens the person - or their pair/team'() {
        const team = { ...entry('team:9', 'Sharks', 4), kind: 'team', participantId: null, members: [{ participantId: 'AAA', name: 'A' }, { participantId: 'bbb', name: 'B' }] };
        const anna = { ...entry(ANNA, 'Anna', 1), participantId: 'ccc' };

        assert.equal(entryOfParticipant([anna, team], 'aaa').ref, 'team:9');
        assert.equal(entryOfParticipant([anna, team], 'CCC').ref, ANNA);
        assert.equal(entryOfParticipant([anna, team], 'ddd'), null);
    },

    async 'a referee\'s live update never takes away what the referee\'s own state showed - nor adds anything'() {
        // Gina is private: on this referee's state only when she lets them see her (allow list)
        const revealed = { ...entry(ANNA, 'Gina Quick', 3), participantId: 'g', playerId: 'p2', playerCode: 'player2', playerName: 'Jane Smith' };
        const withheld = { ...entry(ANNA, 'Gina Quick', 3), participantId: 'g', playerId: null, playerCode: null, playerName: null, playerWithheld: true, result: { seconds: 60 } };

        assert.deepEqual(
            [keepWithheldPlayers(revealed, withheld).playerCode, keepWithheldPlayers(revealed, withheld).playerId, keepWithheldPlayers(revealed, withheld).result],
            ['player2', 'p2', { seconds: 60 }],
        );
        // The state did not show her - the update does not either
        const hidden = { ...withheld, result: null };
        assert.equal(keepWithheldPlayers(hidden, withheld).playerCode, null);
        assert.equal(keepWithheldPlayers(undefined, withheld).playerCode, null);
        // Nothing withheld: the update as it is
        const publicPlayer = { ...revealed, playerCode: 'player9' };
        assert.equal(keepWithheldPlayers(revealed, publicPlayer).playerCode, 'player9');

        const team = (members) => ({ ...entry('team:1', 'Edge Hunters', 2), kind: 'team', participantId: null, members });
        const merged = keepWithheldPlayers(
            team([{ participantId: 'g', name: 'Gina Quick', playerId: 'p2', playerCode: 'player2', playerName: 'Jane Smith' }, { participantId: 'h', name: 'Hugo', playerId: null, playerCode: null, playerName: null }]),
            team([{ participantId: 'g', name: 'Gina Quick', playerId: null, playerCode: null, playerName: null, playerWithheld: true }, { participantId: 'h', name: 'Hugo', playerId: null, playerCode: null, playerName: null, playerWithheld: true }]),
        );
        assert.deepEqual(merged.members.map((member) => member.playerCode), ['player2', null]);
    },

    async 'the event link keeps a round this device picked while it still runs'() {
        const now = 1_800_000_000_000;
        const rounds = [
            { id: 'a', stopwatch: { status: 'running' } },
            { id: 'b', stopwatch: { status: null } },
            { id: 'c', stopwatch: { status: 'stopped' } },
        ];

        assert.equal(preferredRound('a', null, rounds, now), 'a');
        assert.equal(preferredRound('a', { roundId: 'b', at: now - 3600000 }, rounds, now), 'b');
        assert.equal(preferredRound('a', { roundId: 'b', at: now - 13 * 3600000 }, rounds, now), 'a', 'picked too long ago');
        assert.equal(preferredRound('a', { roundId: 'c', at: now - 60000 }, rounds, now), 'a', 'that round is over');
        assert.equal(preferredRound('a', { roundId: 'gone', at: now }, rounds, now), 'a');
    },
};

function entry(ref, name, tableNumber, extra = {}) {
    return {
        ref,
        kind: 'person',
        name,
        displayName: name,
        members: [],
        playerCode: null,
        playerName: null,
        tableNumber,
        result: null,
        enteredAt: null,
        ...extra,
    };
}

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
