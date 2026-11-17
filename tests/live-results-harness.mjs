// Runs the browser modules of the live result entry (assets/official_results_outbox.js, official_results_live.js,
// official_results_scan.js, official_results_time.js, live_entry_page.js) through their scenarios and prints {scenario: "ok" | error} as
// JSON - tests/LiveResultsScriptsTest.php asserts every one is "ok". The outbox runs on an in-memory store with a
// fake clock and a fake server.

import assert from 'node:assert/strict';
import { createMemoryStorage, createTabLock, Outbox, STUCK_AFTER_ATTEMPTS } from '../assets/official_results_outbox.js';
import { isGone, officialResultsRequest, retryAfterMs } from '../assets/official_results_api.js';
import {
    buildSearchIndex,
    confirmTapAllowed,
    CONFIRM_GUARD_MS,
    describeResult,
    entriesWithWaitingTables,
    entryForEnter,
    entryOfParticipant,
    findRows,
    foldText,
    keepWithheldPlayers,
    outboxByEntry,
    preferredRound,
    recentEntries,
    sameValue,
    searchEntries,
    shownValue,
    uniqueTable,
} from '../assets/official_results_live.js';
import { parseNameTagUrl } from '../assets/official_results_scan.js';
import {
    COMPETITION_PLACEHOLDER,
    fillLink,
    landingOf,
    linkNavigation,
    pageSettings,
    resolveLanding,
    roundOptions,
    stateUrl,
    UUID_PLACEHOLDER,
    urlWithoutLanding,
} from '../assets/live_entry_page.js';
import { formatResultTime, parseResultTime } from '../assets/official_results_time.js';
import { choiceOf, describeChoice, missingChoice, pickFormat, piecesLimit, puzzleChips, recordFor, saveFields, togglePick, unfinishedResult } from '../assets/live_entry_puzzles.js';
import { normaliseDraft } from '../assets/live_entry_draft.js';
import { ageOf, applyOutcome, confirmFields, decisionOnDevice, mergeProposals, proposalOfEntry, proposalPanel, proposalValue, proposerName, rejectionChange, waitingProposals } from '../assets/live_entry_proposals.js';
import { validate } from '../assets/official_results_puzzles.js';

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

    async 'the event link keeps a round this device picked while it counts down to a scheduled start'() {
        const now = 1_800_000_000_000;
        const rounds = [
            { id: 'a', stopwatch: { status: 'running' } },
            { id: 'b', stopwatch: { status: 'scheduled', startedAt: new Date(now + 60000).toISOString() } },
        ];

        assert.equal(preferredRound('a', { roundId: 'b', at: now - 60000 }, rounds, now), 'b', 'not started yet - not over either');
    },

    // ---- The page filled from the state (live_entry_page.js, competition toolkit C1) ----

    async 'the page asks for the state with who reads it, and the entries can be left out'() {
        const template = `/en/official-results/rounds/${UUID_PLACEHOLDER}`;

        assert.equal(stateUrl(template, ROUND_A), `/en/official-results/rounds/${ROUND_A}?viewer=1`);
        assert.equal(stateUrl(template, ROUND_A, { entries: false }), `/en/official-results/rounds/${ROUND_A}?viewer=1&entries=0`);
        assert.equal(stateUrl(template, ROUND_A, { viewer: false }), `/en/official-results/rounds/${ROUND_A}`, 'the existing consumers\' URL');
        assert.equal(stateUrl(`${template}?x=1`, ROUND_A, { viewer: true }), `/en/official-results/rounds/${ROUND_A}?x=1&viewer=1`);
    },

    async 'nothing of a round is shown before its state answers'() {
        const settings = pageSettings(null);

        assert.equal(settings.usesTables, false, 'no table bits before the round is known');
        assert.equal(settings.organiser, false, 'no organiser tools before the reader is known');
        assert.equal(settings.canSetTables, false);
        assert.equal(settings.playerId, null, 'no outbox owner');
        assert.equal(settings.roundName, '');
    },

    async 'an organiser of an in-person solo round gets the tables and the organiser tools'() {
        const settings = pageSettings({
            competition: { id: 'c1', isOnline: false },
            round: { id: ROUND_A, name: 'Group A', category: 'solo', tableNumbersOff: false },
            viewer: { playerId: 'p1', organiser: true },
        });

        assert.deepEqual(settings, { usesTables: true, solo: true, members: 0, organiser: true, canSetTables: true, playerId: 'p1', competitionId: 'c1', roundName: 'Group A' });
    },

    async 'a referee never gets the organiser tools nor a table field'() {
        const settings = pageSettings({
            competition: { id: 'c1', isOnline: false },
            round: { id: ROUND_A, name: 'Pairs', category: 'duo', tableNumbersOff: false },
            viewer: { playerId: 'p2', organiser: false },
        });

        assert.equal(settings.organiser, false);
        assert.equal(settings.usesTables, true, 'tables are shown on rows and found by number');
        assert.equal(settings.canSetTables, false, 'a referee\'s table numbers are refused by the server');
        assert.equal(settings.solo, false);
        assert.equal(settings.members, 2, 'a pair starts with two member fields');
        assert.equal(settings.playerId, 'p2');
    },

    async 'online events and rounds without table numbers find by name only, a team starts with three members'() {
        const online = pageSettings({ competition: { id: 'c', isOnline: true }, round: { category: 'team', tableNumbersOff: false }, viewer: { playerId: 'p', organiser: true } });
        const off = pageSettings({ competition: { id: 'c', isOnline: false }, round: { category: 'solo', tableNumbersOff: true }, viewer: { playerId: 'p', organiser: true } });

        assert.equal(online.usesTables, false);
        assert.equal(online.canSetTables, false);
        assert.equal(online.members, 3);
        assert.equal(off.usesTables, false);
        assert.equal(off.canSetTables, false);
    },

    async 'a state without a viewer (another consumer\'s answer) never makes anybody an organiser'() {
        const settings = pageSettings({ competition: { id: 'c', isOnline: false }, round: { category: 'solo', tableNumbersOff: false } });

        assert.equal(settings.organiser, false);
        assert.equal(settings.playerId, null);
        assert.equal(pageSettings({ round: { category: 'solo' }, viewer: { playerId: '', organiser: 'yes' } }).organiser, false, 'only a real true');
    },

    async 'the round picker lists every round from the state, this one selected, a running one named'() {
        const options = roundOptions([
            { id: ROUND_A, name: 'Group A', stopwatch: { status: 'running' } },
            { id: ROUND_B, name: 'Group B', stopwatch: { status: null } },
            { id: 'r3', name: 'Final' },
        ], ROUND_B, 'Running');

        assert.deepEqual(options, [
            { value: ROUND_A, label: 'Group A · Running', selected: false },
            { value: ROUND_B, label: 'Group B', selected: true },
            { value: 'r3', label: 'Final', selected: false },
        ]);
        assert.deepEqual(roundOptions(undefined, ROUND_A, 'Running'), []);
    },

    async 'the template\'s links get the round and the event'() {
        assert.equal(fillLink(`/en/manage-event-rounds/${COMPETITION_PLACEHOLDER}`, ROUND_A, 'c1'), '/en/manage-event-rounds/c1');
        assert.equal(fillLink(`/en/round-seating/${UUID_PLACEHOLDER}`, ROUND_A, 'c1'), `/en/round-seating/${ROUND_A}`);
        assert.equal(fillLink(`/cs/live/${COMPETITION_PLACEHOLDER}/p/${UUID_PLACEHOLDER}`, 'person', 'c1'), '/cs/live/c1/p/person');
    },

    async 'landing parameters: one at a time, anything malformed is no landing'() {
        assert.equal(landingOf({}), null);
        assert.equal(landingOf({ entrant: '', table: '', entry: '' }), null);
        assert.deepEqual(landingOf({ entrant: 'ABC-1' }), { kind: 'entrant', participantId: 'abc-1' });
        assert.deepEqual(landingOf({ table: '12' }), { kind: 'table', number: 12 });
        assert.deepEqual(landingOf({ table: 7 }), { kind: 'table', number: 7 });
        assert.equal(landingOf({ table: '0' }), null);
        assert.equal(landingOf({ table: '12a' }), null);
        assert.equal(landingOf({ table: '10000' }), null);
        assert.deepEqual(landingOf({ entry: BEN.toUpperCase() }), { kind: 'entry', ref: BEN });
        assert.equal(landingOf({ entry: 'person:018d0020-0000-0000-0000-000000000302' }), null);
        assert.deepEqual(landingOf({ entrant: 'p', table: '3', entry: BEN }), { kind: 'entrant', participantId: 'p' }, 'the entrant first');
        assert.deepEqual(landingOf({ table: '3', entry: BEN }), { kind: 'entry', ref: BEN });
    },

    async 'a landing opens the entry once the state is there'() {
        const entries = [
            entry(ANNA, 'Anna Fast', 1, { participantId: 'P-ANNA' }),
            entry(BEN, 'Ben Steady', 12, { participantId: 'p-ben' }),
            { ...entry('team:t1', 'Sharks', 5), kind: 'team', members: [{ name: 'Cara', participantId: 'p-cara' }] },
        ];

        assert.deepEqual(resolveLanding(landingOf({ table: '12' }), entries, null, 'Group A'), { open: BEN });
        assert.deepEqual(resolveLanding(landingOf({ entry: BEN }), entries, null, 'Group A'), { open: BEN });
        assert.deepEqual(resolveLanding(landingOf({ entrant: 'p-anna' }), entries, null, 'Group A'), { open: ANNA }, 'case-insensitive ids');
        assert.deepEqual(resolveLanding(landingOf({ entrant: 'p-cara' }), entries, null, 'Group A'), { open: 'team:t1' }, 'a member opens the pair/team');
        assert.equal(resolveLanding(null, entries, null, 'Group A'), null);
    },

    async 'a landing nobody of the round answers says why, with the round\'s name'() {
        const entries = [entry(ANNA, 'Anna Fast', 1, { participantId: 'p-anna' })];

        assert.deepEqual(resolveLanding(landingOf({ table: '9' }), entries, null, 'Group A'), { notice: 'tableEmpty', parameters: { '%number%': 9, '%round%': 'Group A' } });
        assert.deepEqual(resolveLanding(landingOf({ entry: CARA }), entries, null, 'Group A'), { notice: 'entryNotInRound', parameters: { '%round%': 'Group A' } });
        assert.deepEqual(resolveLanding(landingOf({ table: '1' }), [], null, 'Group A'), { notice: 'tableEmpty', parameters: { '%number%': 1, '%round%': 'Group A' } }, 'an empty round');
    },

    async 'an entrant of another round is named from the event\'s people, asked for only then'() {
        const entries = [entry(ANNA, 'Anna Fast', 1, { participantId: 'p-anna' })];
        const landing = landingOf({ entrant: 'p-gina' });

        assert.deepEqual(resolveLanding(landing, entries, null, 'Group A'), { needPeople: true }, 'not loaded yet - ask for them');
        assert.deepEqual(
            resolveLanding(landing, entries, [{ participantId: 'P-GINA', name: 'Gina Quick' }], 'Group A'),
            { notice: 'entrantNotInRound', parameters: { '%name%': 'Gina Quick', '%round%': 'Group A' } },
        );
        // Not going (waitlist) or the people could not be loaded: the name tag's text without a name
        assert.deepEqual(resolveLanding(landing, entries, [], 'Group A'), { notice: 'scanNotInRound', parameters: { '%round%': 'Group A' } });
    },

    // ---- Links without Turbo (live_entry_page.js linkNavigation(), competition toolkit C2) ----

    async 'a link to another live entry page is within the tool, any other page leaves it'() {
        const here = 'https://x.test/en/live-results/' + ROUND_A + '?table=3';
        const template = '/en/live-results/' + UUID_PLACEHOLDER;

        assert.equal(linkNavigation({ href: 'https://x.test/en/live-results/' + ROUND_B }, here, template), 'within', 'their round');
        assert.equal(linkNavigation({ href: '/en/live-results/' + ROUND_B + '?entrant=p' }, here, template), 'within', 'relative, with a landing');
        assert.equal(linkNavigation({ href: 'https://x.test/en/results-desk/' + ROUND_A }, here, template), 'leave', 'the results desk');
        assert.equal(linkNavigation({ href: 'https://x.test/en/manage/rounds' }, here, template), 'leave');
        assert.equal(linkNavigation({ href: 'https://other.test/en/live-results/' + ROUND_B }, here, template), 'leave', 'another site is never the tool');
        // Czech: the route has no locale prefix
        assert.equal(linkNavigation({ href: '/zadavani-vysledku/' + ROUND_B }, 'https://x.test/zadavani-vysledku/' + ROUND_A, '/zadavani-vysledku/' + UUID_PLACEHOLDER), 'within');
        assert.equal(linkNavigation({ href: '/zadavani-vysledku-udalosti/x' }, 'https://x.test/zadavani-vysledku/' + ROUND_A, '/zadavani-vysledku/' + UUID_PLACEHOLDER), 'leave');
        // A scanned tag's "Open their round": the frozen tag URLs (no locale, or with one) and the name tag are the tool too
        const competition = '0198a2b4-1c3d-7e5f-8a9b-0c1d2e3f4a5b';
        assert.equal(linkNavigation({ href: '/live/' + competition + '/t/12' }, here, template), 'within', 'table tag');
        assert.equal(linkNavigation({ href: 'https://x.test/live/' + competition + '/g/' + ROUND_B }, here, template), 'within', 'line-up tag');
        assert.equal(linkNavigation({ href: '/en/live/' + competition + '/e/' + ROUND_B }, here, template), 'within', 'entry tag with a locale');
        assert.equal(linkNavigation({ href: '/en/live/' + competition + '/p/' + ROUND_B }, here, template), 'within', 'name tag');
        assert.equal(linkNavigation({ href: '/zadavani-vysledku-udalosti/' + competition }, 'https://x.test/zadavani-vysledku/' + ROUND_A, '/zadavani-vysledku/' + UUID_PLACEHOLDER), 'within', 'the Czech event page');
        assert.equal(linkNavigation({ href: 'https://other.test/live/' + competition + '/t/12' }, here, template), 'leave', 'a tag URL of another site');
        assert.equal(linkNavigation({ href: '/live/' + competition + '/t/0' }, here, template), 'leave', 'not a tag path');
    },

    async 'a link that does not load another page here is left alone'() {
        const here = 'https://x.test/en/live-results/' + ROUND_A;
        const template = '/en/live-results/' + UUID_PLACEHOLDER;
        const desk = 'https://x.test/en/results-desk/' + ROUND_A;

        assert.equal(linkNavigation({ href: here + '#' }, here, template), 'stay', 'a link not filled in yet (href="#")');
        assert.equal(linkNavigation({ href: here + '#top' }, here, template), 'stay');
        assert.equal(linkNavigation({ href: desk, target: '_blank' }, here, template), 'stay', 'the sign-in link opens a tab');
        assert.equal(linkNavigation({ href: desk, target: '_self' }, here, template), 'leave');
        assert.equal(linkNavigation({ href: desk, modifier: true }, here, template), 'stay', 'cmd/ctrl-click');
        assert.equal(linkNavigation({ href: desk, button: 1 }, here, template), 'stay', 'middle click');
        assert.equal(linkNavigation({ href: desk, download: true }, here, template), 'stay');
        assert.equal(linkNavigation({ href: 'mailto:a@b.test' }, here, template), 'stay');
        assert.equal(linkNavigation({ href: 'http://[' }, here, template), 'stay', 'not a URL');
    },

    async 'the landing parameters are taken out of the URL, nothing else'() {
        assert.equal(urlWithoutLanding('https://x.test/en/live-results/r?entrant=a&auto=1&notice=tag_unknown&table=3&entry=team:b&keep=1'), 'https://x.test/en/live-results/r?keep=1');
        assert.equal(urlWithoutLanding('https://x.test/en/live-results/r?table=3'), 'https://x.test/en/live-results/r');
        assert.equal(urlWithoutLanding('https://x.test/en/live-results/r'), null, 'nothing to replace');
        assert.equal(urlWithoutLanding('https://x.test/en/live-results/r?keep=1'), null);
    },

    // ---- Competition toolkit C7: speed ----

    async 'uniqueTable: a table opens as soon as no other table number starts with what is typed'() {
        const tables = (numbers) => buildSearchIndex(numbers.map((number, i) => entry(`participant_round:t${i}`, `Player ${i}`, number)));
        const opens = (index, text, options) => uniqueTable(index, text, options)?.tableNumber ?? null;

        // 1-20
        const twenty = tables(Array.from({ length: 20 }, (_, i) => i + 1));
        assert.equal(opens(twenty, '3'), 3);
        assert.equal(opens(twenty, '1'), null, '1 waits for 1x');
        assert.equal(opens(twenty, '12'), 12);
        assert.equal(opens(twenty, '2'), null, '2 waits for 20');
        assert.equal(opens(twenty, '20'), 20);
        assert.equal(opens(twenty, '21'), null, 'nobody there');
        assert.equal(opens(twenty, ' 7 '), 7, 'spaces around');

        // 1-9: every digit opens
        const nine = tables(Array.from({ length: 9 }, (_, i) => i + 1));
        assert.deepEqual(['1', '5', '9'].map((text) => opens(nine, text)), [1, 5, 9]);

        // 1-120
        const many = tables(Array.from({ length: 120 }, (_, i) => i + 1));
        assert.equal(opens(many, '1'), null);
        assert.equal(opens(many, '11'), null, '110-119 start with it');
        assert.equal(opens(many, '110'), 110);
        assert.equal(opens(many, '12'), null, '120 starts with it');
        assert.equal(opens(many, '120'), 120);
        assert.equal(opens(many, '13'), 13);
        assert.equal(opens(many, '99'), 99);

        // Gaps: only 3 and 30
        const gaps = tables([3, 30]);
        assert.equal(opens(gaps, '3'), null, '30 starts with 3');
        assert.equal(opens(gaps, '30'), 30);
        assert.equal(opens(tables([3, 40]), '3'), 3);

        // Entries without a table count for nothing; no tables at all = nothing opens
        assert.equal(opens(tables([null, 5, null]), '5'), 5);
        assert.equal(opens(tables([null, null]), '1'), null);
        assert.equal(opens(tables([]), '1'), null);

        // Table numbers off, names, codes, a leading zero, two entries at one table (ambiguous): nothing opens
        assert.equal(opens(twenty, '3', { tableNumbersOff: true }), null);
        assert.equal(opens(twenty, 'pla'), null);
        assert.equal(opens(twenty, '#3'), null);
        assert.equal(opens(twenty, '03'), null);
        assert.equal(opens(twenty, ''), null);
        assert.equal(opens(tables([4, 4, 7]), '4'), null);
        assert.equal(opens(tables([4, 4, 7]), '7'), 7);
    },

    async 'the confirm step ignores a tap that started within 350 ms of it showing up'() {
        assert.equal(CONFIRM_GUARD_MS, 350);
        assert.equal(confirmTapAllowed(1000, 1000), false, 'the second tap of a double tap');
        assert.equal(confirmTapAllowed(1000, 1349), false);
        assert.equal(confirmTapAllowed(1000, 1350), true);
        assert.equal(confirmTapAllowed(1000, 4000), true);
        assert.equal(confirmTapAllowed(1000, 900), false, 'a tap that began before the step showed');
        assert.equal(confirmTapAllowed(undefined, 1000), true, 'never shown by this page (nothing to guard)');
    },

    async 'a group is stored in one write and sent in one request'() {
        const memory = createMemoryStorage();
        const writes = [];
        const storage = {
            ...memory,
            put: async (item) => { writes.push([item.id]); await memory.put(item); },
            putMany: async (items) => { writes.push(items.map((item) => item.id)); await memory.putMany(items); },
        };
        const { outbox, server } = setup({ storage });

        const { items, groupId, persisted } = outbox.enqueueGroup([
            { roundId: ROUND_A, entryRef: ANNA, field: 'puzzles', serverValue: null, to: [{ roundPuzzleId: 'p1', done: true }] },
            { roundId: ROUND_A, entryRef: ANNA, field: 'result', serverValue: null, to: { seconds: 2700 } },
        ]);
        await persisted;

        assert.equal(items.length, 2);
        assert.ok(items.every((item) => item.groupId === groupId));
        assert.deepEqual(writes, [items.map((item) => item.id)], 'one write for the whole group');
        assert.ok((await memory.getAll()).every((item) => item.groupId === groupId), 'the group survives a reload');

        await outbox.flush();

        assert.equal(server.requests.length, 1);
        assert.deepEqual(server.requests[0].changes.map((change) => change.field), ['puzzles', 'result']);
        assert.ok(server.requests[0].changes.every((change) => !('groupId' in change)), 'the group is the device\'s - the wire format is unchanged');
        assert.equal(outbox.hasUnsent(), false);

        // One change is no group
        assert.equal(outbox.enqueueGroup([{ roundId: ROUND_A, entryRef: BEN, field: 'result', to: { seconds: 1 } }]).groupId, null);
    },

    async 'a group is never split by the batch size (100)'() {
        const fill = (outbox, count) => {
            for (let i = 0; i < count; i += 1) {
                outbox.enqueue({ roundId: ROUND_A, entryRef: `participant_round:filler-${i}`, field: 'result', to: { seconds: 100 + i } });
            }
        };
        const group = (outbox, ref) => outbox.enqueueGroup([
            { roundId: ROUND_A, entryRef: ref, field: 'puzzles', to: [{ roundPuzzleId: 'p1', done: true }] },
            { roundId: ROUND_A, entryRef: ref, field: 'result', to: { seconds: 2700 } },
        ]);
        const sizes = (server) => server.requests.map((request) => request.changes.length);
        const together = (server) => server.requests.filter((request) => request.changes.some((change) => change.entry === ANNA));

        // 99 changes before the group: the group does not fit into the first request - it goes whole in the second
        {
            const { outbox, server } = setup();
            fill(outbox, 99);
            await group(outbox, ANNA).persisted;
            await outbox.flush();
            assert.deepEqual(sizes(server), [99, 2]);
            assert.equal(together(server).length, 1);
            assert.equal(together(server)[0].changes.length, 2);
        }

        // 98 before it: exactly 100 in one request
        {
            const { outbox, server } = setup();
            fill(outbox, 98);
            await group(outbox, ANNA).persisted;
            fill(outbox, 5);
            await outbox.flush();
            assert.deepEqual(sizes(server), [100, 5]);
        }

        // Nothing behind the group overtakes it when it waits for the next request
        {
            const { outbox, server } = setup();
            fill(outbox, 99);
            await group(outbox, ANNA).persisted;
            outbox.enqueue({ roundId: ROUND_A, entryRef: BEN, field: 'result', to: { seconds: 5 } });
            await outbox.flush();
            assert.deepEqual(sizes(server), [99, 3]);
            assert.deepEqual(server.requests[1].changes.map((change) => change.entry), [ANNA, ANNA, BEN]);
        }

        // A group larger than a batch goes alone, whole
        {
            const { outbox, server } = setup({ batchSize: 2 });
            fill(outbox, 1);
            outbox.enqueueGroup([
                { roundId: ROUND_A, entryRef: ANNA, field: 'puzzles', to: [] },
                { roundId: ROUND_A, entryRef: ANNA, field: 'result', to: { seconds: 1 } },
                { roundId: ROUND_A, entryRef: ANNA, field: 'qualified', to: true },
            ]);
            await outbox.flush();
            assert.deepEqual(sizes(server), [1, 3]);
        }
    },

    async 'a group waits as a whole behind a pausing change of one of its fields'() {
        const { outbox, server, clock } = setup();
        await save(outbox, ANNA, { seconds: 100 });
        server.answers.push({ kind: 'server', status: 503 });
        await outbox.flush();
        assert.equal(outbox.all()[0].attempts, 1, 'Anna\'s result pauses');

        await outbox.enqueueGroup([
            { roundId: ROUND_A, entryRef: ANNA, field: 'puzzles', to: [{ roundPuzzleId: 'p1', done: true }] },
            { roundId: ROUND_A, entryRef: ANNA, field: 'result', serverValue: null, to: { seconds: 110 } },
        ]).persisted;
        await save(outbox, BEN, { seconds: 200 });
        await outbox.flush();

        assert.deepEqual(server.requests[1].changes.map((change) => [change.entry, change.field]), [[BEN, 'result']], 'the puzzles change does not go without its result');

        clock.now += 5000;
        await outbox.flush();
        assert.deepEqual(server.requests[2].changes.map((change) => change.field), ['result', 'puzzles', 'result']);
        assert.equal(outbox.hasUnsent(), false);
    },

    async 'a failing set is isolated a group at a time - never a member alone'() {
        const { outbox, server, clock } = setup();
        await save(outbox, BEN, { seconds: 200 });
        await outbox.enqueueGroup([
            { roundId: ROUND_A, entryRef: ANNA, field: 'puzzles', to: [{ roundPuzzleId: 'p1', done: true }] },
            { roundId: ROUND_A, entryRef: ANNA, field: 'result', to: { seconds: 110 } },
        ]).persisted;
        await save(outbox, CARA, { seconds: 300 });
        // The set fails, Ben alone goes (a success ends isolating), the rest fails again, the group alone fails,
        // Cara alone goes
        server.answers.push({ kind: 'server', status: 500 }, 'decide', { kind: 'server', status: 500 }, { kind: 'server', status: 500 });

        await outbox.flush();

        assert.deepEqual(server.requests.map((request) => request.changes.length), [4, 1, 3, 2, 1]);
        assert.deepEqual(outbox.all().map((item) => [item.entryRef, item.field, item.attempts]), [[ANNA, 'puzzles', 1], [ANNA, 'result', 1]], 'the group pauses as a whole');

        clock.now += 5000;
        await outbox.flush();
        assert.deepEqual(server.requests[5].changes.map((change) => change.field), ['puzzles', 'result']);
        assert.equal(outbox.hasUnsent(), false);

        // A group the server cannot read is refused as a whole
        const second = setup();
        await second.outbox.enqueueGroup([
            { roundId: ROUND_A, entryRef: ANNA, field: 'puzzles', to: [] },
            { roundId: ROUND_A, entryRef: ANNA, field: 'result', to: { seconds: 1 } },
        ]).persisted;
        second.server.answers.push({ kind: 'client', status: 400, data: { error: 'invalid_change', message: 'Could not be read' } });
        await second.outbox.flush();
        assert.deepEqual(second.outbox.all().map((item) => item.state), ['rejected', 'rejected']);
        assert.equal(second.server.requests.length, 1);
    },

    async 'a group in conflict: keep mine sends both again as one group, take theirs drops both, undo takes both back'() {
        const conflicted = async () => {
            const context = setup();
            context.server.decide = (change) => ({ status: 'conflict', current: change.field === 'result' ? { seconds: 999 } : [], enteredBy: { playerId: 'p', name: 'Eva' }, enteredAt: '2026-11-17T10:00:00+00:00' });
            await context.outbox.enqueueGroup([
                { roundId: ROUND_A, entryRef: ANNA, field: 'puzzles', to: [{ roundPuzzleId: 'p1', done: true }] },
                { roundId: ROUND_A, entryRef: ANNA, field: 'result', to: { seconds: 110 } },
            ]).persisted;
            await context.outbox.flush();
            context.server.decide = () => ({ status: 'applied' });

            return context;
        };

        {
            const { outbox, server } = await conflicted();
            assert.deepEqual(outbox.all().map((item) => item.state), ['conflict', 'conflict']);
            const oldGroup = outbox.all()[0].groupId;
            const replacements = await outbox.keepMine(outbox.all()[1].id);
            assert.equal(replacements.length, 2);
            assert.equal(new Set(outbox.all().map((item) => item.groupId)).size, 1);
            assert.notEqual(outbox.all()[0].groupId, oldGroup);
            assert.deepEqual(outbox.all().map((item) => item.from), [[], { seconds: 999 }], 'from the other device\'s values');
            await outbox.flush();
            assert.equal(server.requests.length, 2);
            assert.deepEqual(server.requests[1].changes.map((change) => change.field), ['puzzles', 'result']);
            assert.equal(outbox.hasUnsent(), false);
        }

        {
            const { outbox } = await conflicted();
            await outbox.takeTheirs(outbox.all()[0].id);
            assert.equal(outbox.hasUnsent(), false);
        }

        // Undo of a waiting group drops both; of a sent group saves both opposites as a group
        {
            const { outbox, server } = setup();
            const { items, persisted } = outbox.enqueueGroup([
                { roundId: ROUND_A, entryRef: ANNA, field: 'puzzles', to: [{ roundPuzzleId: 'p1', done: true }] },
                { roundId: ROUND_A, entryRef: ANNA, field: 'result', to: { seconds: 110 } },
            ]);
            await persisted;
            assert.equal(await outbox.undo(items[1]), null);
            assert.equal(outbox.hasUnsent(), false);

            const second = outbox.enqueueGroup([
                { roundId: ROUND_A, entryRef: BEN, field: 'puzzles', to: [{ roundPuzzleId: 'p1', done: true }] },
                { roundId: ROUND_A, entryRef: BEN, field: 'result', to: { seconds: 120 } },
            ]);
            await second.persisted;
            await outbox.flush();
            const opposites = await outbox.undo(second.items);
            assert.equal(opposites.length, 2);
            assert.ok(opposites[0].groupId && opposites[0].groupId === opposites[1].groupId);
            assert.deepEqual(opposites.map((item) => item.to), [null, null]);
            await outbox.flush();
            assert.deepEqual(server.requests.at(-1).changes.map((change) => [change.field, change.to]), [['puzzles', null], ['result', null]]);
        }
    },

    async 'back online: what waited only for the connection goes at once - set-apart changes stay apart'() {
        const { outbox, server, clock } = setup();
        // Round B's event: no rights any more
        server.send = async (roundId, changes) => {
            server.requests.push({ roundId, changes });

            if (server.answers.length > 0) {
                return server.answers.shift();
            }

            if (roundId === ROUND_B) {
                return { kind: 'forbidden', status: 403, data: { error: 'forbidden' } };
            }

            return { kind: 'ok', status: 200, data: { outcomes: changes.map((change) => ({ clientChangeId: change.clientChangeId, status: change.entry === CARA ? 'rejected' : 'applied', reason: 'invalid_time' })), entries: [] } };
        };
        await save(outbox, BEN, { seconds: 50 }, { roundId: ROUND_B });
        await save(outbox, CARA, { seconds: 60 });
        await outbox.flush();
        assert.deepEqual(outbox.all().map((item) => item.state).sort(), ['forbidden', 'rejected']);
        const before = server.requests.length;

        // Offline for a while: the pause grows to 30 s
        server.answers.push(...Array.from({ length: 6 }, () => ({ kind: 'offline' })));
        await save(outbox, ANNA, { seconds: 100 });

        for (let i = 0; i < 6; i += 1) {
            await outbox.flush();
            clock.now = outbox.retryAt;
        }

        clock.now -= 29000;
        assert.equal(outbox.status().offline, true);
        assert.ok(outbox.retryAt - clock.now > 20000, 'a long offline pause is running');
        const offlineRequests = server.requests.length;
        await outbox.flush();
        assert.equal(server.requests.length, offlineRequests, 'the pause holds a plain flush');

        await outbox.connectionBack();

        assert.equal(server.requests.length, offlineRequests + 1, 'sent at once');
        assert.deepEqual(server.requests.at(-1).changes.map((change) => change.entry), [ANNA], 'only the change that waited for the connection');
        assert.equal(outbox.status().offline, false);
        assert.deepEqual(outbox.all().map((item) => item.state).sort(), ['forbidden', 'rejected'], 'refused and forbidden stay apart');
        assert.ok(server.requests.length - before >= 7);

        // A busy server's pause is not a connection problem: connectionBack does not cut it short
        const busy = setup();
        busy.server.answers.push({ kind: 'server', status: 429, busy: true, retryAfter: 30000 });
        await save(busy.outbox, ANNA, { seconds: 1 });
        await busy.outbox.flush();
        await busy.outbox.connectionBack();
        assert.equal(busy.server.requests.length, 1);

        // A change pausing after a server error keeps its own pause too
        const failing = setup();
        failing.server.answers.push({ kind: 'server', status: 500 }, { kind: 'offline' });
        await save(failing.outbox, ANNA, { seconds: 1 });
        await failing.outbox.flush();
        await save(failing.outbox, BEN, { seconds: 2 });
        await failing.outbox.flush();
        assert.equal(failing.outbox.status().offline, true);
        await failing.outbox.connectionBack();
        assert.deepEqual(failing.server.requests.at(-1).changes.map((change) => change.entry), [BEN]);
        assert.deepEqual(failing.outbox.all().map((item) => item.entryRef), [ANNA]);
    },

    async 'find rows: the result shown, the sync mark, the exact table'() {
        const entries = [
            entry(ANNA, 'Anna', 12, { result: { seconds: 100 } }),
            entry(BEN, 'Ben', 120),
            entry(CARA, 'Cara', 3),
        ];
        const items = [
            { entryRef: BEN, field: 'result', to: { seconds: 200 }, state: 'pending', seq: 1, roundId: ROUND_A, createdAt: 1 },
            { entryRef: CARA, field: 'result', to: { seconds: 300 }, state: 'rejected', seq: 2, roundId: ROUND_A, createdAt: 2 },
        ];
        const index = buildSearchIndex(entries);
        const { typed, rows } = findRows(index, '12', items);

        assert.equal(typed, true);
        assert.deepEqual(rows.map((row) => [row.entry.ref, row.exact, row.sync, row.result.value]), [
            [ANNA, true, 'saved', { seconds: 100 }],
            [BEN, false, 'waiting', { seconds: 200 }],
        ]);
        assert.deepEqual(findRows(index, 'cara', items).rows.map((row) => row.sync), ['problem']);

        const recent = findRows(index, '', items, { entries, roundId: ROUND_A });
        assert.equal(recent.typed, false);
        assert.deepEqual(recent.rows.map((row) => row.entry.ref), [CARA, BEN], 'this device\'s unsent ones, newest first');
        assert.equal(findRows(index, '', items, { entries, roundId: ROUND_B }).rows.length, 0, 'another round\'s changes, no server results entered: nothing recent');

        // Waiting table numbers on top, one map lookup per entry
        const view = entriesWithWaitingTables(entries, [{ entryRef: CARA, field: 'table_number', to: 30, state: 'pending', seq: 1 }, { entryRef: BEN, field: 'table_number', to: 7, state: 'rejected', seq: 2 }]);
        assert.equal(view.byRef.get(CARA).tableNumber, 30);
        assert.equal(view.byRef.get(BEN).tableNumber, 120, 'a refused table number is no table');
        assert.equal(view.byRef.size, 3);
        assert.equal(entriesWithWaitingTables(entries, []).list, entries, 'nothing waiting: the same list');
    },

    async 'perf budget: 300 entries and 300 unsent changes - search and rows under 5 ms'() {
        const entries = Array.from({ length: 300 }, (_, i) => entry(
            `participant_round:018d0020-0000-0000-0000-${String(i).padStart(12, '0')}`,
            `Kateřina Nováková ${i}`,
            i + 1,
            {
                displayName: i % 3 === 0 ? `Puzzle Sharks ${i}` : `Kateřina Nováková ${i}`,
                kind: i % 3 === 0 ? 'team' : 'person',
                members: i % 3 === 0 ? [{ name: `Jana Dvořák ${i}`, playerCode: `jd${i}` }, { name: `Petr Šťastný ${i}`, playerCode: null }] : [],
                playerCode: `kn${i}`,
                result: i % 2 === 0 ? { seconds: 2000 + i } : null,
                enteredAt: i % 2 === 0 ? new Date(1_800_000_000_000 + i * 1000).toISOString() : null,
            },
        ));
        const items = Array.from({ length: 300 }, (_, i) => ({
            id: `c${i}`,
            entryRef: entries[(i * 7) % 300].ref,
            field: i % 10 === 0 ? 'table_number' : 'result',
            to: i % 10 === 0 ? 400 + i : { seconds: 3000 + i },
            state: i % 25 === 0 ? 'conflict' : 'pending',
            seq: i,
            roundId: ROUND_A,
            createdAt: 1_800_000_100_000 + i,
        }));
        const median = (run) => {
            const times = [];

            for (let i = 0; i < 31; i += 1) {
                const start = performance.now();
                run();
                times.push(performance.now() - start);
            }

            return times.sort((a, b) => a - b)[15];
        };

        // Warm up (JIT) as a page does over its first keystrokes
        let view;
        let index;

        for (let i = 0; i < 20; i += 1) {
            view = entriesWithWaitingTables(entries, items);
            index = buildSearchIndex(view.list);
            findRows(index, 'kat', items);
        }

        // Per change of the entries or the outbox
        const rebuild = median(() => {
            view = entriesWithWaitingTables(entries, items);
            index = buildSearchIndex(view.list);
        });
        assert.ok(rebuild < 5, `entries view + search index: ${rebuild.toFixed(2)} ms`);

        // Per keystroke: every kind of query, and the recent list
        for (const query of ['1', '12', '123', 'kat', 'katerina nov', 'shark', '#kn1', 'zzz', '']) {
            const took = median(() => {
                uniqueTable(index, query);
                findRows(index, query, items, { entries: view.list, roundId: ROUND_A });
            });
            assert.ok(took < 5, `"${query}": ${took.toFixed(2)} ms`);
        }

        // An entry's lookup is one map read
        const lookup = median(() => {
            for (const item of items) {
                view.byRef.get(item.entryRef);
            }
        });
        assert.ok(lookup < 1, `300 lookups: ${lookup.toFixed(3)} ms`);
        assert.equal(outboxByEntry(items).get(entries[0].ref).length, items.filter((item) => item.entryRef === entries[0].ref).length);
    },
    // ---- Puzzle choice on pick rounds (competition toolkit C10) ----

    'puzzle chips: labels always, names and pictures only of a puzzle that is out - a name sent for a hidden slot stays unshown'() {
        const format = pickRound(1);
        format.puzzles[1] = { ...format.puzzles[1], hidden: true, name: 'Leaked name', imageUrl: 'https://img.example/leak.jpg' };
        format.puzzles[2] = { ...format.puzzles[2], hidden: false, name: 'Lighthouse', imageUrl: 'javascript:alert(1)' };
        const chips = puzzleChips(format, [P_B]);

        assert.deepEqual(chips.map((chip) => chip.label), ['A', 'B', 'C']);
        assert.equal(chips[0].name, 'Harbour');
        assert.equal(chips[0].imageUrl, 'https://img.example/a.jpg');
        assert.equal(chips[1].name, null, 'hidden: never the name, whatever came');
        assert.equal(chips[1].imageUrl, null);
        assert.equal(chips[1].hidden, true);
        assert.equal(chips[1].order, 1);
        assert.equal(chips[2].imageUrl, null, 'only https or own paths as a picture');
        assert.equal(pickFormat({ puzzleFormat: null }), null);
        assert.equal(pickFormat({}), null);
        assert.equal(describeChoice(format, [{ roundPuzzleId: P_C }, { roundPuzzleId: P_A }]), 'C → A');
    },

    'puzzle taps: one of N picks (tap again none); K of N in solving order, tap again removes, one more replaces the last'() {
        assert.deepEqual(togglePick([], P_A, 1), [P_A]);
        assert.deepEqual(togglePick([P_A], P_B, 1), [P_B]);
        assert.deepEqual(togglePick([P_B], P_B, 1), []);

        let choice = togglePick([], P_C, 2);
        choice = togglePick(choice, P_A, 2);
        assert.deepEqual(choice, [P_C, P_A], 'the tap order is the solving order');
        assert.deepEqual(togglePick(choice, P_B, 2), [P_C, P_B], 'a third tap replaces the last');
        assert.deepEqual(togglePick(choice, P_C, 2), [P_A], 'tap again removes - the rest moves up');
        assert.deepEqual(togglePick([], P_A.toUpperCase(), 2), [P_A], 'ids compared lower case');
    },

    'puzzle choice is preselected from what is known: the server, else this device\'s waiting change'() {
        const format = pickRound(1);
        const anna = entry(ANNA, 'Anna', 1, { puzzles: [{ roundPuzzleId: P_B, done: false }] });

        assert.deepEqual(choiceOf(format, shownValue(anna, 'puzzles', []).value), [P_B]);

        const waiting = [{ entryRef: ANNA, field: 'puzzles', seq: 1, state: 'pending', to: [{ roundPuzzleId: P_C, done: true }] }];
        assert.deepEqual(choiceOf(format, shownValue(anna, 'puzzles', waiting).value), [P_C]);
        assert.deepEqual(choiceOf(format, [{ roundPuzzleId: 'not-of-this-round', done: true }]), []);
    },

    async 'Finished now on a pick round saves the result and the record as one group - never split, even one change per batch'() {
        const format = pickRound(1);
        const anna = entry(ANNA, 'Anna', 1, { puzzles: [{ roundPuzzleId: P_B, done: false }] });
        const { fields, record } = saveFields(format, [P_B], { seconds: 2710 }, anna, []);

        assert.deepEqual(fields.map((field) => field.field), ['puzzles', 'result']);
        assert.deepEqual(record, [{ roundPuzzleId: P_B, done: true }]);
        assert.equal(validate(record, format, { seconds: 2710 }), null, 'what the server takes (rule 6)');

        const { outbox, server } = setup({ batchSize: 1 });
        outbox.enqueue({ roundId: ROUND_A, entryRef: BEN, field: 'result', to: { seconds: 99 } });
        const { persisted } = outbox.enqueueGroup(fields.map(({ field, to }) => ({ roundId: ROUND_A, entryRef: ANNA, field, serverValue: field === 'puzzles' ? anna.puzzles : null, to })));
        await persisted;
        await outbox.flush();

        const sentTogether = server.requests.find((request) => request.changes.some((change) => change.entry === ANNA));
        assert.deepEqual(sentTogether.changes.map((change) => change.field), ['puzzles', 'result'], 'both in one request');
        assert.deepEqual(sentTogether.changes[0].from, [{ roundPuzzleId: P_B, done: false }]);
    },

    async 'a refused group: both changes come back refused and show their reasons; Undo takes both back'() {
        const { outbox, server } = setup();
        server.decide = (change) => (change.field === 'puzzles'
            ? { status: 'rejected', reason: 'puzzles_mismatch', message: 'The result does not match the puzzles' }
            : { status: 'rejected', reason: 'group_refused', message: 'Saved together with another change' });
        const { items, persisted } = outbox.enqueueGroup([
            { roundId: ROUND_A, entryRef: ANNA, field: 'puzzles', to: [{ roundPuzzleId: P_A, done: true }] },
            { roundId: ROUND_A, entryRef: ANNA, field: 'result', to: { seconds: 100 } },
        ]);
        await persisted;
        await outbox.flush();

        assert.deepEqual(outbox.all().map((item) => [item.field, item.state, item.reason]), [['puzzles', 'rejected', 'puzzles_mismatch'], ['result', 'rejected', 'group_refused']]);

        await outbox.undo(items[0]);
        assert.equal(outbox.hasUnsent(), false, 'Undo of one change of the group takes the group back');
    },

    async 'Undo of a pick round Save already sent takes back the result and the record, as one group'() {
        const { outbox, server } = setup();
        const { items, persisted } = outbox.enqueueGroup([
            { roundId: ROUND_A, entryRef: ANNA, field: 'puzzles', serverValue: null, to: [{ roundPuzzleId: P_A, done: false, piecesPlaced: 640 }] },
            { roundId: ROUND_A, entryRef: ANNA, field: 'result', serverValue: { piecesPlaced: 850 }, to: { piecesPlaced: 640 } },
        ]);
        await persisted;
        await outbox.flush();
        assert.equal(outbox.hasUnsent(), false);

        // The page hands Undo every change of the Save (the sent ones are no longer on the device to be found)
        await outbox.undo(items);
        await outbox.flush();

        const back = server.requests[1].changes;
        assert.deepEqual(back.map((change) => [change.field, change.to]), [['puzzles', null], ['result', { piecesPlaced: 850 }]]);
    },

    'unfinished on a K-of-N round: the pieces typed are the last puzzle\'s, the ones before count in full (shared total())'() {
        const format = pickRound(2);
        const choice = [P_A, P_C];

        assert.equal(piecesLimit(format, choice), 999, 'the last puzzle (C, 1000) - 1');
        assert.equal(piecesLimit(format, []), 2 * 1000 - 1, 'nothing chosen: unfinishedMaximum()');

        const result = unfinishedResult(format, choice, 312);
        assert.deepEqual(result, { piecesPlaced: 812 });

        const { record, refusal } = recordFor(format, choice, result);
        assert.equal(refusal, null);
        assert.deepEqual(record, [{ roundPuzzleId: P_A, done: true }, { roundPuzzleId: P_C, done: false, piecesPlaced: 312 }]);
        assert.equal(validate(record, format, result), null);
        assert.deepEqual(unfinishedResult(format, [], 300), { piecesPlaced: 300 });
    },

    'a finished result without all K chosen goes without a record (Puzzle missing); did not start drops a known record'() {
        const format = pickRound(2);
        const anna = entry(ANNA, 'Anna', 1, { puzzles: [{ roundPuzzleId: P_A, done: false }] });
        const finished = saveFields(format, [P_A], { seconds: 3000 }, anna, []);

        assert.equal(finished.record, null);
        assert.deepEqual(finished.fields, [{ field: 'puzzles', to: null }, { field: 'result', to: { seconds: 3000 } }], 'the incomplete choice would refuse the time');

        assert.deepEqual(saveFields(format, [P_A], { didNotStart: true }, anna, []).fields, [{ field: 'puzzles', to: null }, { field: 'result', to: { didNotStart: true } }]);

        const ben = entry(BEN, 'Ben', 2);
        assert.deepEqual(saveFields(format, [], { seconds: 3000 }, ben, []).fields, [{ field: 'result', to: { seconds: 3000 } }], 'a result alone is allowed');

        // The choice alone at the table: chosen, not done
        assert.deepEqual(saveFields(format, [P_C, P_B], null, ben, []).fields, [{ field: 'puzzles', to: [{ roundPuzzleId: P_C, done: false }, { roundPuzzleId: P_B, done: false }] }]);
        // Nothing changed: nothing to send
        assert.deepEqual(saveFields(format, [P_A], null, anna, []).fields, []);
    },

    'a known split from the desk is kept when it still fits, dropped when it does not'() {
        const format = pickRound(2);
        const previous = [{ roundPuzzleId: P_A, done: true, atSeconds: 1500 }, { roundPuzzleId: P_C, done: false }];

        assert.deepEqual(recordFor(format, [P_A, P_C], { seconds: 3000 }, previous).record, [{ roundPuzzleId: P_A, done: true, atSeconds: 1500 }, { roundPuzzleId: P_C, done: true }]);
        assert.deepEqual(recordFor(format, [P_A, P_C], { seconds: 1200 }, previous).record, [{ roundPuzzleId: P_A, done: true }, { roundPuzzleId: P_C, done: true }]);
    },

    'a total round sends the result alone, exactly as before'() {
        const anna = entry(ANNA, 'Anna', 1);

        assert.deepEqual(saveFields(null, [], { seconds: 100 }, anna, []), { fields: [{ field: 'result', to: { seconds: 100 } }], record: undefined, refusal: null });
        assert.deepEqual(saveFields(null, [], null, anna, []).fields, []);
        assert.equal(sameValue([{ roundPuzzleId: P_A, done: true }], null), false, 'a record is a value of its own');
        assert.equal(sameValue([], null), true);
    },

    'Puzzle missing: entries with a time or pieces but no puzzle chosen'() {
        const format = pickRound(1);
        const entries = [
            entry(ANNA, 'Anna', 1, { result: { seconds: 100 }, puzzles: null }),
            entry(BEN, 'Ben', 2, { result: { seconds: 200 }, puzzles: [{ roundPuzzleId: P_A, done: true }] }),
            entry(CARA, 'Cara', 3, { result: { didNotStart: true }, puzzles: null }),
            entry('participant_round:dan', 'Dan', 4, { result: null, puzzles: null }),
        ];

        assert.deepEqual(missingChoice(entries, format, outboxByEntry([])).map((item) => item.ref), [ANNA]);

        const waiting = [{ entryRef: ANNA, field: 'puzzles', seq: 1, state: 'pending', to: [{ roundPuzzleId: P_B, done: true }] }];
        assert.deepEqual(missingChoice(entries, format, outboxByEntry(waiting)), [], 'this device\'s waiting choice counts');
        assert.deepEqual(missingChoice(entries, null, outboxByEntry([])), [], 'never on a total round');
    },

    'the draft keeps the puzzle choice across a reload'() {
        const draft = normaliseDraft({ playerId: 'p', roundId: ROUND_A, ref: ANNA, view: 'entry', choice: [P_C, 7, P_A], savedAt: 1 });
        assert.deepEqual(draft.choice, [P_C, P_A]);
        assert.equal(normaliseDraft({ playerId: 'p', roundId: ROUND_A, ref: ANNA, view: 'entry', savedAt: 1 }).choice, null);
    },

    // ---- Time proposals (competition toolkit C9) ----

    async 'a confirmation carries its proposal on the wire, a Keep mine too, an undo does not'() {
        const { outbox, server } = setup();
        const { items, persisted } = outbox.enqueueGroup([{ roundId: ROUND_A, entryRef: ANNA, field: 'result', serverValue: null, to: { seconds: 2712 }, proposalId: PROP_1 }]);
        await persisted;
        assert.equal(items[0].proposalId, PROP_1);
        server.decide = () => ({ status: 'conflict', current: { seconds: 2800 } });
        await outbox.flush();
        assert.equal(server.requests[0].changes[0].proposalId, PROP_1);
        assert.equal(server.requests[0].changes[0].field, 'result');

        server.decide = () => ({ status: 'applied', proposal: { id: PROP_1, status: 'confirmed' } });
        await outbox.keepMine(items[0].id);
        await outbox.flush();
        assert.equal(server.requests[1].changes[0].proposalId, PROP_1, 'Keep mine resends the confirmation');
        assert.deepEqual(server.requests[1].changes[0].from, { seconds: 2800 });

        // Sent already: the opposite change is a plain result change
        const sent = items[0];
        server.decide = () => ({ status: 'applied' });
        outbox.inFlight.add(sent.id);
        outbox.items.push({ ...sent, state: 'pending' });
        const opposite = await outbox.undo(sent);
        outbox.inFlight.delete(sent.id);
        assert.equal(opposite.proposalId, undefined);
        assert.equal(outbox.wire(opposite).proposalId, undefined);
        assert.ok(!('proposalId' in outbox.wire({ id: 'x', field: 'table_number', entryRef: ANNA, from: null, to: 3 })), 'other changes keep their shape');
    },

    async 'a rejection names its proposal, is never chained and has no opposite'() {
        const { outbox, server } = setup();
        const proposal = { id: PROP_1, entry: ANNA };
        const change = rejectionChange(proposal, 'not_finished');
        const first = outbox.enqueueGroup([{ roundId: ROUND_A, entryRef: ANNA, field: 'proposal', serverValue: change.from, to: change.to }]);
        await first.persisted;
        const second = outbox.enqueueGroup([{ roundId: ROUND_A, entryRef: ANNA, field: 'proposal', serverValue: { id: PROP_2 }, to: rejectionChange({ id: PROP_2 }, 'nonsense').to }]);
        await second.persisted;
        server.decide = (sentChange) => ({ status: 'applied', proposal: { id: sentChange.from.id, status: 'rejected' } });
        const annotations = [];
        outbox.onProposals = (list, roundId) => annotations.push([roundId, list]);
        await outbox.flush();

        assert.deepEqual(server.requests[0].changes.map((sent) => [sent.field, sent.entry, sent.from, sent.to]), [
            ['proposal', ANNA, { id: PROP_1 }, { status: 'rejected', reason: 'not_finished' }],
            ['proposal', ANNA, { id: PROP_2 }, { status: 'rejected', reason: 'other' }],
        ]);
        assert.ok(server.requests[0].changes.every((sent) => !('proposalId' in sent)));
        assert.deepEqual(annotations, [[ROUND_A, [{ id: PROP_1, status: 'rejected' }, { id: PROP_2, status: 'rejected' }]]]);

        // Sent: nothing to undo
        assert.equal(await outbox.undo({ ...first.items[0] }), null);
        assert.equal(outbox.hasUnsent(), false);
    },

    async 'a confirmation made offline goes once the device is online again'() {
        const { outbox, server, clock } = setup();
        server.answers.push({ kind: 'offline' });
        const fields = confirmFields(null, [], { seconds: 2713 }, entry(ANNA, 'Anna', 12), [], PROP_1).fields;
        const { persisted } = outbox.enqueueGroup(fields.map((field) => ({ roundId: ROUND_A, entryRef: ANNA, serverValue: null, ...field })));
        await persisted;
        await outbox.flush();
        assert.equal(outbox.status().offline, true);
        assert.equal(outbox.all().length, 1, 'kept on the device');

        const proposals = mergeProposals(new Map(), [proposalOf(PROP_1, ANNA)]);
        assert.equal(proposalPanel(proposalOfEntry(proposals, ANNA), entry(ANNA, 'Anna', 12), outbox.all()).state, 'sending');
        assert.deepEqual(waitingProposals(proposals, () => entry(ANNA, 'Anna', 12), outbox.all()), [], 'decided here: not waiting any more');

        let annotated = proposals;
        outbox.onProposals = (list) => { for (const item of list) { annotated = applyOutcome(annotated, item).proposals; } };
        server.decide = (change) => ({ status: 'applied', proposal: { id: change.proposalId, status: 'confirmed' } });
        clock.now += 500;
        await outbox.connectionBack();

        assert.equal(server.requests.length, 2);
        assert.equal(server.requests[1].changes[0].proposalId, PROP_1);
        assert.equal(outbox.hasUnsent(), false);
        assert.equal(annotated.get(PROP_1).status, 'confirmed');
        assert.equal(proposalPanel(proposalOfEntry(annotated, ANNA), entry(ANNA, 'Anna', 12), []).state, 'decided');
    },

    'proposals merge by id and a decided one never turns pending again'() {
        let proposals = mergeProposals(new Map(), [proposalOf(PROP_1, ANNA), { id: 'broken' }, proposalOf(PROP_2, BEN.toUpperCase())]);
        assert.deepEqual([...proposals.keys()], [PROP_1, PROP_2]);
        assert.equal(proposals.get(PROP_2).entry, BEN, 'refs compared lower-cased');

        // Mercure: the competitor withdrew
        proposals = mergeProposals(proposals, [proposalOf(PROP_1, ANNA, { status: 'withdrawn', decided: { at: new Date(T_STATE - 5000).toISOString(), seconds: null, reason: null } })]);
        assert.equal(proposals.get(PROP_1).status, 'withdrawn');
        // An older copy (a state read before) says pending: decided stays decided
        proposals = mergeProposals(proposals, [proposalOf(PROP_1, ANNA)]);
        assert.equal(proposals.get(PROP_1).status, 'withdrawn');

        // A state answer drops what it does not list - unless it came after the state was read
        const late = proposalOf(PROP_3, CARA, { receivedAtMs: T_STATE + 1000 });
        proposals = mergeProposals(proposals, [late]);
        proposals = mergeProposals(proposals, [proposalOf(PROP_2, BEN)], { stateAtMs: T_STATE });
        assert.deepEqual([...proposals.keys()].sort(), [PROP_2, PROP_3].sort());
        // A snapshot's state (no server time) drops nothing
        assert.equal(mergeProposals(proposals, [], { stateAtMs: NaN }).size, 2);
    },

    'the server\'s word on a proposal is kept at once, an old copy asks for the state'() {
        const proposals = mergeProposals(new Map(), [proposalOf(PROP_1, ANNA)]);
        const confirmed = applyOutcome(proposals, { id: PROP_1.toUpperCase(), status: 'confirmed' }, '2026-11-17T10:00:00+00:00');
        assert.equal(confirmed.stale, false);
        assert.equal(confirmed.proposals.get(PROP_1).status, 'confirmed');
        assert.equal(proposals.get(PROP_1).status, 'pending', 'no mutation');
        assert.equal(applyOutcome(proposals, { id: PROP_1, status: 'not_pending' }).stale, true);
        assert.equal(applyOutcome(proposals, { id: PROP_9, status: 'unknown' }).stale, true);
        assert.equal(applyOutcome(proposals, { id: PROP_1, status: 'pending' }).proposals.get(PROP_1).status, 'pending');
        // A confirmation undone afterwards (toolkit H3): the state says why
        assert.equal(applyOutcome(confirmed.proposals, { id: PROP_1, status: 'superseded' }).stale, true);
    },

    'the card panel: confirm, differs, matches, sending, decided - late defaults to the receipt'() {
        const anna = entry(ANNA, 'Anna', 12);
        const pending = mergeProposals(new Map(), [proposalOf(PROP_1, ANNA)]).get(PROP_1);
        assert.deepEqual(proposalPanel(pending, anna, []), { state: 'confirm', value: { seconds: 2712 }, saved: null });
        assert.deepEqual(proposalValue(pending), { seconds: 2712 });

        const late = mergeProposals(new Map(), [proposalOf(PROP_1, ANNA, { late: true, lateByMs: 12000, receiptSeconds: 2724 })]).get(PROP_1);
        assert.deepEqual(proposalValue(late), { seconds: 2724 }, 'late: the receipt time');
        assert.deepEqual(proposalPanel(late, anna, []).value, { seconds: 2724 });

        assert.equal(proposalPanel(pending, entry(ANNA, 'Anna', 12, { result: { seconds: 2800 } }), []).state, 'differs');
        assert.deepEqual(proposalPanel(pending, entry(ANNA, 'Anna', 12, { result: { seconds: 2800 } }), []).saved, { seconds: 2800 });
        assert.equal(proposalPanel(pending, entry(ANNA, 'Anna', 12, { result: { seconds: 2712 } }), []).state, 'matches');
        // This device's waiting result counts as saved
        assert.equal(proposalPanel(pending, anna, [{ entryRef: ANNA, field: 'result', seq: 1, state: 'pending', to: { seconds: 99 } }]).state, 'differs');
        // Decided on this device: waiting / its problem shown instead
        assert.deepEqual(proposalPanel(pending, anna, [{ entryRef: ANNA, field: 'proposal', seq: 1, state: 'pending', from: { id: PROP_1 }, to: {} }]), { state: 'sending', value: { seconds: 2712 }, rejecting: true });
        assert.equal(proposalPanel(pending, anna, [{ entryRef: ANNA, field: 'result', seq: 1, state: 'conflict', proposalId: PROP_1, to: { seconds: 2712 } }]), null);
        assert.equal(proposalPanel(null, anna, []), null);
    },

    'the proposer is named only on a pair/team card - a solo card would repeat its own name'() {
        const pending = mergeProposals(new Map(), [proposalOf(PROP_1, ANNA)]).get(PROP_1);
        assert.equal(proposerName(pending, entry(ANNA, 'Anna', 12)), null, 'solo: no "by Anna" under Anna');
        assert.equal(proposerName(pending, entry(ANNA, 'Team A', 12, { kind: 'team' })), 'Jana Nováková');
        assert.equal(proposerName({ ...pending, proposedBy: null }, entry(ANNA, 'Team A', 12, { kind: 'team' })), null);
        assert.equal(proposerName(null, entry(ANNA, 'Team A', 12, { kind: 'team' })), null);
    },

    'a proposal superseded or withdrawn while its card is open changes the panel in place'() {
        const anna = entry(ANNA, 'Anna', 12);
        let proposals = mergeProposals(new Map(), [proposalOf(PROP_1, ANNA)]);
        assert.equal(proposalPanel(proposalOfEntry(proposals, ANNA), anna, []).state, 'confirm');

        proposals = mergeProposals(proposals, [proposalOf(PROP_1, ANNA, { status: 'superseded', decided: { at: '2026-11-17T10:00:00+00:00', byName: 'Eva', seconds: null, reason: 'result_recorded' } })]);
        assert.equal(proposalPanel(proposalOfEntry(proposals, ANNA), anna, []).state, 'decided');

        // The competitor proposes again: the new pending one is the card's
        proposals = mergeProposals(proposals, [proposalOf(PROP_2, ANNA, { proposedSeconds: 2750, receiptSeconds: 2751 })]);
        assert.equal(proposalOfEntry(proposals, ANNA).id, PROP_2);
        assert.deepEqual(proposalPanel(proposalOfEntry(proposals, ANNA), anna, []).value, { seconds: 2750 });
        assert.equal(decisionOnDevice(PROP_2, []), null);
    },

    'proposals waiting: pending, known entries, not decided here, oldest first'() {
        const entries = new Map([[ANNA, entry(ANNA, 'Anna', 12)], [BEN, entry(BEN, 'Ben', 3)]]);
        const proposals = mergeProposals(new Map(), [
            proposalOf(PROP_1, ANNA, { receivedAtMs: T_STATE + 50 }),
            proposalOf(PROP_2, BEN, { receivedAtMs: T_STATE + 10 }),
            proposalOf(PROP_3, CARA, { receivedAtMs: T_STATE }),
            proposalOf(PROP_9, ANNA, { status: 'withdrawn' }),
        ]);
        const rows = waitingProposals(proposals, (ref) => entries.get(ref) ?? null, []);
        assert.deepEqual(rows.map((row) => [row.proposal.id, row.entry.ref]), [[PROP_2, BEN], [PROP_1, ANNA]]);
        assert.deepEqual(waitingProposals(proposals, (ref) => entries.get(ref) ?? null, [{ entryRef: BEN, field: 'result', proposalId: PROP_2, state: 'pending' }]).map((row) => row.proposal.id), [PROP_1]);
        assert.deepEqual(ageOf(1000, 41000), { unit: 'seconds', count: 40 });
        assert.deepEqual(ageOf(0, 150000), { unit: 'minutes', count: 2 });
    },

    'a pick round confirms with the puzzle choice - one group, never without it'() {
        const format = pickRound(1);
        const anna = entry(ANNA, 'Anna', 12);
        assert.equal(confirmFields(format, [], { seconds: 2712 }, anna, [], PROP_1).needsChoice, true);
        assert.deepEqual(confirmFields(format, [], { seconds: 2712 }, anna, [], PROP_1).fields, []);

        const { fields, needsChoice } = confirmFields(format, [P_B], { seconds: 2712 }, anna, [], PROP_1.toUpperCase());
        assert.equal(needsChoice, false);
        assert.deepEqual(fields, [
            { field: 'puzzles', to: [{ roundPuzzleId: P_B, done: true }] },
            { field: 'result', to: { seconds: 2712 }, proposalId: PROP_1 },
        ]);

        // A total round: the result alone - also when the same time is saved (it still confirms)
        assert.deepEqual(confirmFields(null, [], { seconds: 2712 }, entry(ANNA, 'Anna', 12, { result: { seconds: 2712 } }), [], PROP_1).fields, [{ field: 'result', to: { seconds: 2712 }, proposalId: PROP_1 }]);
    },

    async 'a pick round\'s confirmation is never split from its puzzles'() {
        const { outbox, server } = setup({ batchSize: 2 });
        await save(outbox, BEN, { seconds: 1 });
        const { fields } = confirmFields(pickRound(1), [P_A], { seconds: 2712 }, entry(ANNA, 'Anna', 12), [], PROP_1);
        const { persisted, groupId } = outbox.enqueueGroup(fields.map((field) => ({ roundId: ROUND_A, entryRef: ANNA, serverValue: null, ...field })));
        await persisted;
        assert.notEqual(groupId, null);
        await outbox.flush();

        assert.equal(server.requests.length, 2, 'the group waits for the next request rather than split');
        assert.deepEqual(server.requests[1].changes.map((change) => [change.field, change.proposalId ?? null]), [['puzzles', null], ['result', PROP_1]]);
    },

    'the draft keeps the proposal a Change edits'() {
        assert.equal(normaliseDraft({ playerId: 'p', roundId: ROUND_A, ref: ANNA, view: 'entry', proposalId: PROP_1, savedAt: 1 }).proposalId, PROP_1);
        assert.equal(normaliseDraft({ playerId: 'p', roundId: ROUND_A, ref: ANNA, view: 'entry', savedAt: 1 }).proposalId, null);
    },
};

const P_A = '018d0020-0000-0000-0000-00000000a001';
const PROP_1 = '019a0000-0000-7000-8000-0000000000f1';
const PROP_2 = '019a0000-0000-7000-8000-0000000000f2';
const PROP_3 = '019a0000-0000-7000-8000-0000000000f3';
const PROP_9 = '019a0000-0000-7000-8000-0000000000f9';
const T_STATE = 1_763_390_000_000;

function proposalOf(id, ref, extra = {}) {
    return {
        id,
        roundId: ROUND_A,
        entry: ref,
        tableNumber: 12,
        status: 'pending',
        proposedSeconds: 2712,
        receiptSeconds: 2713,
        tappedAtMs: T_STATE - 2000,
        receivedAtMs: T_STATE - 1000,
        lateByMs: 1000,
        late: false,
        clockChanged: false,
        proposedBy: { participantId: 'x', name: 'Jana Nováková', linkedAt: null },
        decided: null,
        ...extra,
    };
}
const P_B = '018d0020-0000-0000-0000-00000000b002';
const P_C = '018d0020-0000-0000-0000-00000000c003';

function pickRound(toSolve) {
    return {
        mode: 'pick',
        toSolve,
        puzzles: [
            { roundPuzzleId: P_A, position: 1, label: 'A', hidden: false, puzzleId: 'x', name: 'Harbour', imageUrl: 'https://img.example/a.jpg', piecesCount: 500 },
            { roundPuzzleId: P_B, position: 2, label: 'B', hidden: true, puzzleId: null, name: null, imageUrl: null, piecesCount: 500 },
            { roundPuzzleId: P_C, position: 3, label: 'C', hidden: true, puzzleId: null, name: null, imageUrl: null, piecesCount: 1000 },
        ],
    };
}

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
