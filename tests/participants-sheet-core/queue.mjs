// The save queue (assets/participants_sheet/sheet_save_queue.js): debounce, one request in flight, the version
// protocol (contract §3.2), retries and auth/forbidden/gone like the results desk, conflicts and refusals as problems.
import assert from 'node:assert/strict';
import { SheetModel } from '../../assets/participants_sheet/sheet_model.js';
import { setField, setInRound, newTeamRow, linkProfile, resultsAction } from '../../assets/participants_sheet/sheet_changes.js';
import { SheetSaveQueue, DEBOUNCE_MS, PREVIEW_TIMEOUT_MS } from '../../assets/participants_sheet/sheet_save_queue.js';
import { ROUND_PAIRS, ROUND_SOLO, appliedAnswer, clock, fakeServer, ids, smallState } from './fixture.mjs';

const URLS = {
    changes: '/en/participants-sheet-api/c1/changes',
    state: '/en/participants-sheet-api/c1/state',
    record: '/en/official-results/rounds/__ROUND__/changes',
    tables: '/en/official-results/rounds/__ROUND__/table-numbers',
};

function setup() {
    const time = clock();
    const server = fakeServer();
    const model = new SheetModel(smallState(), { now: time.now });
    const queue = new SheetSaveQueue({
        model,
        urls: URLS,
        csrfToken: 'csrf',
        texts: { genericError: 'Could not be saved.' },
        request: server.request,
        newId: ids('cs'),
        schedule: time.schedule,
        cancel: time.cancel,
        now: time.now,
    });
    const events = [];
    queue.subscribe((event) => events.push(event));

    /** An organiser's action: shown at once, queued. */
    const act = (action) => {
        action.groups.forEach((group) => model.applyLocal(group.id, group.changes));
        queue.enqueueGroups(action.groups);

        return action;
    };

    return { time, server, model, queue, events, act };
}

const conflictAnswer = (call, { current = null, versionBefore = 'v1', versionAfter = 'v1' } = {}) => ({
    kind: 'ok',
    status: 200,
    data: {
        dryRun: false,
        replayed: false,
        versionBefore,
        versionAfter,
        groups: call.body.groups.map((group) => ({
            id: group.id,
            status: 'conflict',
            changes: group.changes.map((change, index) => ({ index, status: index === 0 ? 'conflict' : 'skipped', reason: null, message: index === 0 ? 'Changed meanwhile.' : null, current: index === 0 ? current : null })),
            warnings: [],
            deletedTeams: [],
        })),
    },
});

export default function (test) {
    test('edits within the debounce go as one changeset, in order, with the CSRF header and a changeset id', async () => {
        const { time, server, queue, act, model } = setup();
        act(setField(model, 'p-ana', 'name', 'Ana One', { newId: ids('a') }));
        await time.advance(300);
        act(setField(model, 'p-jo', 'country', 'cz', { newId: ids('b') }));
        assert.equal(queue.status().state, 'saving');
        await time.advance(DEBOUNCE_MS - 1);
        assert.equal(server.calls.length, 0, 'the debounce restarts with every edit');
        await time.advance(1);
        assert.equal(server.calls.length, 1);
        const call = server.calls[0];
        assert.equal(call.url, URLS.changes);
        assert.equal(call.method, 'POST');
        assert.equal(call.csrfToken, 'csrf');
        assert.equal(call.body.dryRun, false);
        assert.equal(call.body.changesetId, 'cs1');
        assert.deepEqual(call.body.groups.map((group) => group.id), ['a1', 'b1']);
    });

    test('applied + versionBefore = the known version: the model is the server state, no fetch', async () => {
        const { time, server, queue, act, model } = setup();
        act(setField(model, 'p-ana', 'name', 'Ana One'));
        await time.advance(DEBOUNCE_MS);
        await server.reply(appliedAnswer(server.calls[0], { versionBefore: 'v1', versionAfter: 'v2' }));
        assert.equal(model.version, 'v2');
        assert.equal(model.hasPending(), false);
        assert.equal(model.person('p-ana').name, 'Ana One');
        assert.equal(model.marks.get('person:p-ana:name'), null, 'the saving marker is gone');
        assert.equal(queue.status().state, 'saved');
        await time.advance(5000);
        assert.equal(server.calls.length, 1, 'nothing else asked');
    });

    test('versionBefore differs (somebody else saved meanwhile): the state is fetched and merged', async () => {
        const { time, server, act, model, events } = setup();
        act(setField(model, 'p-ana', 'name', 'Ana One'));
        await time.advance(DEBOUNCE_MS);
        await server.reply(appliedAnswer(server.calls[0], { versionBefore: 'v-other', versionAfter: 'v3' }));
        await time.advance(0);
        assert.equal(server.calls.length, 2);
        assert.equal(server.calls[1].url, URLS.state);
        const state = smallState({ version: 'v3' });
        state.people.find((p) => p.id === 'p-ana').name = 'Ana One';
        state.people.find((p) => p.id === 'p-jo').name = 'Jo Changed Elsewhere';
        await server.reply({ kind: 'ok', status: 200, data: state });
        assert.equal(model.version, 'v3');
        assert.equal(model.person('p-jo').name, 'Jo Changed Elsewhere');
        assert.ok(events.some((event) => event.type === 'state' && event.kind === 'ok'));
    });

    test('one request in flight: what comes meanwhile goes in the next changeset, after the answer', async () => {
        const { time, server, act, model } = setup();
        act(setField(model, 'p-ana', 'name', 'Ana One'));
        await time.advance(DEBOUNCE_MS);
        act(setField(model, 'p-jo', 'name', 'Jo One'));
        await time.advance(5000);
        assert.equal(server.open().length, 1);
        await server.reply(appliedAnswer(server.calls[0], { versionBefore: 'v1', versionAfter: 'v2' }));
        await time.advance(DEBOUNCE_MS);
        assert.equal(server.calls.length, 2);
        assert.notEqual(server.calls[1].body.changesetId, server.calls[0].body.changesetId);
        assert.equal(server.calls[1].body.groups[0].changes[0].participant, 'p-jo');
        await server.reply(appliedAnswer(server.calls[1], { versionBefore: 'v2', versionAfter: 'v3' }));
        assert.equal(model.version, 'v3');
    });

    test('offline: kept with the same changeset id and retried with backoff; status "N waiting - offline"; online() at once', async () => {
        const { time, server, queue, act, model } = setup();
        act(setInRound(model, ['p-ana', 'p-jo'], ROUND_SOLO, true));
        await time.advance(DEBOUNCE_MS);
        await server.reply({ kind: 'offline' });
        assert.deepEqual(queue.status(), { state: 'offline', waiting: 2, attention: 0, offline: true });
        assert.equal(model.marks.get(`place:p-ana:${ROUND_SOLO}`).state, 'waiting');
        assert.equal(model.placeValue('p-ana', ROUND_SOLO), 'in', 'still shown');
        // a new edit while offline does not change the changeset that was sent
        act(setField(model, 'p-ana', 'name', 'Ana One'));
        await time.advance(1999);
        assert.equal(server.calls.length, 1);
        await time.advance(1);
        assert.equal(server.calls.length, 2);
        assert.equal(server.calls[1].body.changesetId, server.calls[0].body.changesetId);
        assert.deepEqual(server.calls[1].body.groups, server.calls[0].body.groups);
        await server.reply({ kind: 'server', status: 502, retryAfter: null, busy: false });
        assert.equal(queue.status().state, 'waiting');
        queue.online();
        await time.advance(0);
        assert.equal(server.calls.length, 3, 'back online = at once');
        await server.reply(appliedAnswer(server.calls[2]));
        assert.equal(model.marks.get(`place:p-ana:${ROUND_SOLO}`), null);
        await time.advance(DEBOUNCE_MS);
        assert.equal(server.calls[3].body.groups.length, 1, 'the later edit in its own changeset');
    });

    test('signed out: nothing is sent until "Try again"; forbidden: Reload the page; the event gone: stops', async () => {
        const { time, server, queue, act, model } = setup();
        act(setField(model, 'p-ana', 'name', 'Ana One'));
        await time.advance(DEBOUNCE_MS);
        await server.reply({ kind: 'auth', status: 401 });
        assert.equal(queue.status().state, 'auth');
        assert.equal(queue.hasUnsaved(), true);
        act(setField(model, 'p-jo', 'name', 'Jo One'));
        await time.advance(60000);
        assert.equal(server.calls.length, 1, 'nothing while signed out');
        const fetch = queue.refetch();
        await time.advance(0);
        assert.equal(await fetch, 'auth', 'a fetch answers at once instead of waiting');
        queue.retryNow();
        await time.advance(0);
        assert.equal(server.calls.length, 2);
        assert.equal(server.calls[1].body.changesetId, server.calls[0].body.changesetId);
        await server.reply({ kind: 'forbidden', status: 403, data: { error: 'forbidden' } });
        assert.equal(queue.status().state, 'forbidden');
        queue.retryNow();
        await time.advance(0);
        await server.reply({ kind: 'client', status: 404, data: { error: 'competition_not_found', message: 'Gone.' } });
        assert.equal(queue.status().state, 'gone');
        queue.retryNow();
        await time.advance(60000);
        assert.equal(server.calls.length, 3);
    });

    test('a conflict reverts the group, lists a problem with the server\'s message, and Keep mine resends over the current value', async () => {
        const { time, server, queue, act, model, events } = setup();
        act(setField(model, 'p-ana', 'name', 'Ana One'));
        await time.advance(DEBOUNCE_MS);
        await server.reply(conflictAnswer(server.calls[0], { current: 'Ana Elsewhere', versionBefore: 'v1' }));
        assert.equal(model.person('p-ana').name, 'Ana Example', 'reverted until the state comes');
        const [problem] = queue.problems();
        assert.equal(problem.status, 'conflict');
        assert.equal(problem.message, 'Changed meanwhile.');
        assert.equal(problem.current, 'Ana Elsewhere');
        assert.equal(problem.target.key, 'person:p-ana:name');
        assert.equal(model.marks.get('person:p-ana:name').state, 'conflict');
        assert.deepEqual(queue.status(), { state: 'attention', waiting: 0, attention: 1, offline: false });
        assert.equal(queue.hasUnsaved(), true, 'an undecided conflict counts as unsaved');
        assert.ok(events.some((event) => event.type === 'outcome' && event.outcome.status === 'conflict'));

        const group = queue.keepMine(problem.id);
        assert.deepEqual(group.changes[0], { op: 'field', participant: 'p-ana', field: 'name', from: 'Ana Elsewhere', to: 'Ana One' });
        assert.equal(model.person('p-ana').name, 'Ana One');
        assert.equal(queue.problems().length, 0);
        await time.advance(DEBOUNCE_MS);
        assert.equal(server.calls.at(-1).body.groups[0].changes[0].from, 'Ana Elsewhere');
    });

    test('a refusal lists the reason; OK (dismiss) clears it and the marker', async () => {
        const { time, server, queue, act, model } = setup();
        act(setInRound(model, ['p-pat'], ROUND_SOLO, false));
        await time.advance(DEBOUNCE_MS);
        const call = server.calls[0];
        await server.reply({
            kind: 'ok', status: 200,
            data: { replayed: false, versionBefore: 'v1', versionAfter: 'v1', groups: [{ id: call.body.groups[0].id, status: 'refused', changes: [{ index: 0, status: 'refused', reason: 'has_result_in_round', message: 'Pat has a result in this round.', current: 'in' }], warnings: [], deletedTeams: [] }] },
        });
        assert.equal(model.placeValue('p-pat', ROUND_SOLO), 'in');
        const [problem] = queue.problems();
        assert.equal(problem.reason, 'has_result_in_round');
        assert.equal(model.marks.get(`place:p-pat:${ROUND_SOLO}`).message, 'Pat has a result in this round.');
        queue.dismiss(problem.id);
        assert.equal(queue.problems().length, 0);
        assert.equal(model.marks.get(`place:p-pat:${ROUND_SOLO}`), null);
        assert.equal(queue.status().state, 'saved');
    });

    test('a changeset the server cannot read (400) refuses its groups with the translated message', async () => {
        const { time, server, queue, act, model } = setup();
        act(setField(model, 'p-ana', 'name', 'Ana One'));
        await time.advance(DEBOUNCE_MS);
        await server.reply({ kind: 'client', status: 400, data: { error: 'invalid_changes', reason: 'unknown_op', message: 'The changes could not be read.' } });
        assert.equal(queue.problems()[0].message, 'The changes could not be read.');
        assert.equal(model.person('p-ana').name, 'Ana Example');
    });

    test('a group the answer does not mention is not saved - shown as refused, never sent in a loop', async () => {
        const { time, server, queue, act, model } = setup();
        act(setField(model, 'p-ana', 'name', 'Ana One'));
        await time.advance(DEBOUNCE_MS);
        await server.reply({ kind: 'ok', status: 200, data: { replayed: false, versionBefore: 'v1', versionAfter: 'v1', groups: [] } });
        assert.equal(queue.problems()[0].message, 'Could not be saved.');
        await time.advance(60000);
        assert.equal(server.calls.length, 1);
    });

    test('a replayed answer (the receipt) settles the groups and fetches the state', async () => {
        const { time, server, act, model } = setup();
        act(setField(model, 'p-ana', 'name', 'Ana One'));
        await time.advance(DEBOUNCE_MS);
        const answer = appliedAnswer(server.calls[0]);
        answer.data.replayed = true;
        await server.reply(answer);
        await time.advance(0);
        assert.equal(model.hasPending(), false);
        assert.equal(server.calls[1].url, URLS.state);
    });

    test('new round entries (ids the browser cannot know) bring a state fetch once things are quiet', async () => {
        const { time, server, act, model } = setup();
        act(setInRound(model, ['p-ana'], ROUND_SOLO, true));
        await time.advance(DEBOUNCE_MS);
        await server.reply(appliedAnswer(server.calls[0]));
        assert.equal(model.version, 'v2');
        await time.advance(1499);
        assert.equal(server.calls.length, 1);
        await time.advance(1);
        assert.equal(server.calls[1].url, URLS.state);
    });

    test('teams the server deleted automatically leave the model', async () => {
        const { time, server, act, model } = setup();
        const create = act(newTeamRow(model, ROUND_PAIRS, { members: ['p-jo'] }, { newId: ids('t') }));
        await time.advance(DEBOUNCE_MS);
        await server.reply(appliedAnswer(server.calls[0]));
        act(setInRound(model, ['p-jo'], ROUND_PAIRS, false));
        await time.advance(DEBOUNCE_MS);
        const call = server.calls.find((candidate) => candidate.url === URLS.changes && candidate !== server.calls[0]);
        await server.reply(appliedAnswer(call, { versionBefore: 'v2', versionAfter: 'v3', deletedTeams: { [call.body.groups[0].id]: [create.teamId] } }));
        assert.equal(model.team(create.teamId), null);
    });

    test('warnings come back per group as an event', async () => {
        const { time, server, act, model, events } = setup();
        act(setField(model, 'p-ana', 'name', 'Kim Example'));
        await time.advance(DEBOUNCE_MS);
        const answer = appliedAnswer(server.calls[0]);
        answer.data.groups[0].warnings = [{ code: 'same_name_as_existing', message: 'Probably the same person.', participantId: 'p-ana', teamId: null, roundId: null }];
        await server.reply(answer);
        const warnings = events.find((event) => event.type === 'warnings');
        assert.equal(warnings.warnings[0].code, 'same_name_as_existing');
    });

    test('results of a round go to RecordRoundResults with its id; outcomes settle, conflicts become problems', async () => {
        const { time, server, queue, model } = setup();
        const pending = queue.results(ROUND_SOLO);
        pending.set('participant_round:e-pat-solo', 'result', { seconds: 61 }, null);
        pending.set('participant_round:e-kim-solo', 'qualified', true, false);
        queue.enqueueResults(ROUND_SOLO);
        assert.equal(model.marks.get('result:participant_round:e-pat-solo:result').state, 'saving');
        assert.equal(queue.status().waiting, 2);
        await time.advance(DEBOUNCE_MS);
        const call = server.calls[0];
        assert.equal(call.url, `/en/official-results/rounds/${ROUND_SOLO}/changes`);
        assert.deepEqual(call.body.changes.map((change) => [change.entry, change.field, change.from, change.to]), [
            ['participant_round:e-pat-solo', 'result', null, { seconds: 61 }],
            ['participant_round:e-kim-solo', 'qualified', false, true],
        ]);
        await server.reply({
            kind: 'ok', status: 200,
            data: {
                outcomes: [
                    { clientChangeId: call.body.changes[0].clientChangeId, status: 'applied', entry: 'participant_round:e-pat-solo', field: 'result', current: { seconds: 61 } },
                    { clientChangeId: call.body.changes[1].clientChangeId, status: 'conflict', entry: 'participant_round:e-kim-solo', field: 'qualified', current: true, enteredBy: null },
                ],
                entries: [{ ref: 'participant_round:e-pat-solo', tableNumber: null, result: { seconds: 61 }, qualified: false, enteredAt: '2026-10-08T09:01:00+00:00', enteredBy: { name: 'Me' } }],
            },
        });
        assert.deepEqual(model.place('p-pat', ROUND_SOLO).result, { seconds: 61 });
        assert.equal(model.marks.get('result:participant_round:e-pat-solo:result'), null);
        const [problem] = queue.problems();
        assert.equal(problem.kind, 'results');
        assert.equal(problem.status, 'conflict');
        queue.dismiss(problem.id);
        assert.equal(pending.get('participant_round:e-kim-solo', 'qualified'), null);
    });

    test('table numbers: one write, resolved with the entries; a refusal resolves with the problems and fetches the state', async () => {
        const { time, server, queue } = setup();
        const first = queue.enqueueTables(ROUND_SOLO, [{ entry: 'participant_round:e-pat-solo', from: null, number: 5 }]);
        await time.advance(0);
        assert.equal(server.calls[0].url, `/en/official-results/rounds/${ROUND_SOLO}/table-numbers`);
        await server.reply({ kind: 'ok', status: 200, data: { changed: 1, entries: [{ ref: 'participant_round:e-pat-solo', tableNumber: 5, result: null, qualified: false }] } });
        assert.equal((await first).kind, 'ok');

        const second = queue.enqueueTables(ROUND_SOLO, [{ entry: 'participant_round:e-pat-solo', from: 4, number: 6 }]);
        await time.advance(0);
        await server.reply({ kind: 'client', status: 422, data: { error: 'invalid_table_numbers', problems: [{ entry: 'participant_round:e-pat-solo', reason: 'changed_meanwhile', current: 5 }] } });
        const answer = await second;
        await time.advance(0);
        assert.equal(answer.kind, 'refused');
        assert.equal(answer.problems[0].reason, 'changed_meanwhile');
        assert.equal(server.calls[2].url, URLS.state);
    });

    test('a dry run goes after what was queued before it, without a changeset id, and answers as it is', async () => {
        const { time, server, queue, act, model } = setup();
        act(setField(model, 'p-ana', 'name', 'Ana One'));
        const preview = queue.preview([{ id: 'p1', changes: [{ op: 'remove', participant: 'p-jo' }] }]);
        await time.advance(0);
        assert.equal(server.calls[0].body.dryRun, false, 'the queued edit first, no debounce wait');
        await server.reply(appliedAnswer(server.calls[0]));
        await time.advance(0);
        assert.equal(server.calls[1].body.dryRun, true);
        assert.equal(server.calls[1].body.changesetId, undefined);
        await server.reply({ kind: 'ok', status: 200, data: { dryRun: true, groups: [{ id: 'p1', status: 'applied', changes: [], warnings: [], deletedTeams: [] }] } });
        assert.equal((await preview).data.dryRun, true);
    });

    test('fetches are coalesced and asked again when results arrived while one was on its way', async () => {
        const { time, server, queue, model } = setup();
        const one = queue.refetch();
        const two = queue.refetch();
        assert.equal(one, two);
        await time.advance(0);
        model.mergeEntries([{ ref: 'team:t-flat', tableNumber: 9, result: { seconds: 4000 }, qualified: true, enteredAt: '2026-10-08T09:30:00+00:00', enteredBy: { name: 'Live' } }]);
        await server.reply({ kind: 'ok', status: 200, data: smallState({ version: 'v1' }) });
        assert.equal(server.calls.length, 2, 'asked once more');
        await server.reply({ kind: 'ok', status: 200, data: smallState({ version: 'v1' }) });
        assert.equal(await one, 'ok');
        assert.deepEqual(model.team('t-flat').result, { seconds: 4000 }, 'the newer live result stays');
    });

    test('leaving with unsaved changes asks first (beforeunload + turbo:before-visit)', async () => {
        const { queue, act, model } = setup();
        const listeners = { window: new Map(), document: new Map() };
        const target = (map) => ({ addEventListener: (type, fn) => map.set(type, fn), removeEventListener: (type) => map.delete(type) });
        let asked = 0;
        const teardown = queue.installLeaveGuards({ window: target(listeners.window), document: target(listeners.document), confirm: () => { asked++; return false; }, message: 'Leave?' });

        const unload = { prevented: false, preventDefault() { this.prevented = true; } };
        listeners.window.get('beforeunload')(unload);
        assert.equal(unload.prevented, false, 'nothing unsaved - no question');

        act(setField(model, 'p-ana', 'name', 'Ana One'));
        listeners.window.get('beforeunload')(unload);
        assert.equal(unload.prevented, true);
        const visit = { prevented: false, preventDefault() { this.prevented = true; } };
        listeners.document.get('turbo:before-visit')(visit);
        assert.equal(asked, 1);
        assert.equal(visit.prevented, true);
        teardown();
        assert.equal(listeners.window.size + listeners.document.size, 0);
    });

    test('more than 1,000 groups go as several changesets within the limits', async () => {
        const { time, server, queue, model } = setup();
        const groups = Array.from({ length: 1500 }, (_, i) => ({ id: `g${i}`, changes: [{ op: 'field', participant: 'p-ana', field: 'note', from: null, to: `n${i}` }] }));
        queue.enqueueGroups(groups);
        await time.advance(DEBOUNCE_MS);
        assert.equal(server.calls[0].body.groups.length, 1000);
        await server.reply(appliedAnswer(server.calls[0]));
        await time.advance(0);
        assert.equal(server.calls[1].body.groups.length, 500);
        assert.ok(model);
    });

    test('destroy stops everything and resolves what waits', async () => {
        const { time, server, queue, act, model } = setup();
        act(setField(model, 'p-ana', 'name', 'Ana One'));
        const fetch = queue.refetch();
        queue.destroy();
        assert.equal(await fetch, 'closed');
        await time.advance(60000);
        assert.equal(server.calls.length <= 1, true);
    });

    test('minor 2: a result typed for a pair a later sheet group creates never overtakes that group', async () => {
        const { time, server, queue, act, model } = setup();
        const actAll = (action) => {
            act(action);
            const rounds = new Set();

            for (const change of action.results ?? []) {
                queue.results(change.roundId).set(change.ref, change.field, change.to, change.from);
                rounds.add(change.roundId);
            }

            rounds.forEach((roundId) => queue.enqueueResults(roundId));
        };
        actAll(resultsAction([{ roundId: ROUND_PAIRS, ref: 'team:t-corners', field: 'result', from: null, to: { seconds: 3000 } }]));
        actAll(newTeamRow(model, ROUND_PAIRS, { id: 't-new', name: 'Pinecones', members: ['p-jo', 'p-ana'] }, { newId: ids('g') }));
        actAll(resultsAction([{ roundId: ROUND_PAIRS, ref: 'team:t-new', field: 'result', from: null, to: { seconds: 3300 } }]));
        assert.deepEqual(queue.items.map((item) => item.kind), ['results', 'sheet', 'results']);

        await time.advance(DEBOUNCE_MS);
        assert.deepEqual(server.calls[0].body.changes.map((change) => change.entry), ['team:t-corners'], 'only what was queued before the pair is created');
        await server.reply({ kind: 'ok', status: 200, data: { outcomes: [{ clientChangeId: server.calls[0].body.changes[0].clientChangeId, status: 'applied' }], entries: [] } });
        await time.advance(0);
        assert.equal(server.calls[1].url, URLS.changes, 'then the group creating the pair');
        await server.reply(appliedAnswer(server.calls[1]));
        await time.advance(0);
        assert.deepEqual(server.calls[2].body.changes.map((change) => change.entry), ['team:t-new'], 'then its result');
    });

    test('minor 4: a view throwing while an answer settles never leaves the other groups pending', async () => {
        const { time, server, queue, act, model } = setup();
        act(setField(model, 'p-ana', 'name', 'Ana One', { newId: ids('a') }));
        act(setField(model, 'p-jo', 'name', 'Jo Two', { newId: ids('b') }));
        let armed = true;
        const original = console.error;
        const logged = [];
        console.error = (error) => logged.push(error.message);
        model.subscribe(() => {
            if (armed) {
                armed = false;
                throw new Error('a view bug');
            }
        });
        queue.subscribe(() => {
            throw new Error('a listener bug');
        });

        try {
            await time.advance(DEBOUNCE_MS);
            await server.reply(appliedAnswer(server.calls[0]));
            await time.advance(60000);
        } finally {
            console.error = original;
        }

        assert.deepEqual(model.pending, [], 'both groups folded in');
        assert.equal(model.version, 'v2');
        assert.equal(queue.items.length, 0);
        assert.equal(queue.status().state, 'saved');
        assert.ok(logged.includes('a view bug') && logged.includes('a listener bug'));
    });

    test('minor 8: a changeset the server refused as a whole (400) reverts every group and answers each as refused', async () => {
        const { time, server, queue, act, model, events } = setup();
        act(setInRound(model, ['p-ana', 'p-jo'], ROUND_SOLO, true));
        await time.advance(DEBOUNCE_MS);
        await server.reply({ kind: 'client', status: 400, data: { error: 'invalid_changes', reason: 'too_many_changes', message: 'Too many.' } });
        const outcomes = events.filter((event) => event.type === 'outcome');
        assert.equal(outcomes.length, 2);
        assert.ok(outcomes.every((event) => event.outcome.status === 'refused' && event.outcome.changes[0].reason === 'too_many_changes'));
        assert.deepEqual(model.pending, []);
        assert.equal(model.placeValue('p-ana', ROUND_SOLO), 'out');
        await time.advance(5000);
        assert.equal(server.calls.length, 1, 'no state fetch, nothing sent again');
    });

    test('nit: a dry run waiting behind a save that is being retried answers "could not check" after 15 s', async () => {
        const { time, server, queue, act, model } = setup();
        act(setField(model, 'p-ana', 'name', 'Ana One'));
        await time.advance(DEBOUNCE_MS);
        await server.reply({ kind: 'offline' });
        let answer = null;
        queue.preview([{ id: 'p1', changes: [{ op: 'field', participant: 'p-jo', field: 'name', from: 'Jo Do', to: 'Jo X' }] }]).then((value) => {
            answer = value;
        });
        await time.advance(PREVIEW_TIMEOUT_MS - 1);
        assert.equal(answer, null);
        await time.advance(1);
        assert.deepEqual(answer, { kind: 'offline' });
        assert.equal(queue.items.some((item) => item.kind === 'preview'), false, 'never sent later');
        queue.online();
        await time.advance(0);
        assert.ok(server.calls.every((call) => call.body?.dryRun !== true));
    });

    test('nit: "send now" (a dry run, a fetch) does not make later edits skip the debounce', async () => {
        const { time, server, queue, act, model } = setup();
        const fetched = queue.refetch();
        await time.advance(0);
        await server.reply({ kind: 'ok', status: 200, data: smallState() });
        assert.equal(await fetched, 'ok');
        act(setField(model, 'p-ana', 'name', 'Ana One'));
        await time.advance(DEBOUNCE_MS - 1);
        assert.equal(server.calls.length, 1, 'the edit waits for the debounce');
        await time.advance(1);
        assert.equal(server.calls.length, 2);
    });

    test('minor 3: problems while offline - the status says both', async () => {
        const { time, server, queue, act, model } = setup();
        act(setField(model, 'p-ana', 'name', 'Ana One'));
        await time.advance(DEBOUNCE_MS);
        await server.reply(conflictAnswer(server.calls[0], { current: 'Ana Elsewhere', versionBefore: 'v1' }));
        act(setField(model, 'p-jo', 'name', 'Jo Two'));
        await time.advance(DEBOUNCE_MS);
        await server.reply({ kind: 'offline' });
        assert.deepEqual(queue.status(), { state: 'attention', waiting: 1, attention: 1, offline: true });
    });

    test('our own saves\' versions are known (their echo is not another organiser); a linked profile fetches the state', async () => {
        const { time, server, queue, act, model } = setup();
        act(linkProfile(model, 'p-ana', { id: 'pl-ana', name: 'Ana E.' }));
        await time.advance(DEBOUNCE_MS);
        await server.reply(appliedAnswer(server.calls[0], { versionBefore: 'v1', versionAfter: 'v2' }));
        assert.equal(queue.isOwnVersion('v2'), true);
        assert.equal(queue.isOwnVersion('v9'), false);
        await time.advance(1500);
        assert.equal(server.calls[1].url, URLS.state, 'whose own times guard the rounds now, and how the profile may be shown');
    });

    test('Keep mine as an action: not performed by the queue, the caller makes it an undo step', async () => {
        const { time, server, queue, act, model } = setup();
        const action = act(setField(model, 'p-ana', 'name', 'Ana One'));
        action.groups[0].label = action.label;
        await time.advance(DEBOUNCE_MS);
        await server.reply(conflictAnswer(server.calls[0], { current: 'Ana Elsewhere', versionBefore: 'v1' }));
        model.replaceState(smallState({ version: 'v2', people: smallState().people.map((p) => (p.id === 'p-ana' ? { ...p, name: 'Ana Elsewhere' } : p)) }));
        const [problem] = queue.problems();
        const keep = queue.keepMineAction(problem.id);
        assert.deepEqual(keep.label, { key: 'field', field: 'name' });
        assert.deepEqual(keep.groups[0].changes, [{ op: 'field', participant: 'p-ana', field: 'name', from: 'Ana Elsewhere', to: 'Ana One' }]);
        assert.deepEqual(keep.inverse[0].changes, [{ op: 'field', participant: 'p-ana', field: 'name', from: 'Ana One', to: 'Ana Elsewhere' }]);
        assert.equal(model.person('p-ana').name, 'Ana Elsewhere', 'not applied by the queue');
        assert.equal(queue.problems().length, 0);
    });

    test('minor 5: a bulk action and its answer re-render the views once each (marks included)', async () => {
        const { time, server, queue, model } = setup();
        const deltas = [];
        model.subscribe((delta) => deltas.push(delta));
        const action = setInRound(model, ['p-ana', 'p-jo', 'p-lee', 'p-max'], ROUND_SOLO, true);
        model.batch(() => {
            model.applyLocalMany(action.groups);
            queue.enqueueGroups(action.groups);
        });
        assert.equal(deltas.length, 1);
        await time.advance(DEBOUNCE_MS);
        deltas.length = 0;
        await server.reply(appliedAnswer(server.calls[0]));
        assert.equal(deltas.length, 1, 'confirmed, markers cleared: one re-render');
    });

    test('a 409 changed_meanwhile (the event changed while the server planned) is kept and sent again - not a refusal', async () => {
        const { time, server, queue, act, model } = setup();
        act(setField(model, 'p-ana', 'name', 'Ana One'));
        await time.advance(DEBOUNCE_MS);
        await server.reply({ kind: 'client', status: 409, data: { error: 'changed_meanwhile', message: 'Something changed on the event meanwhile.' } });
        assert.deepEqual(queue.problems(), []);
        assert.equal(model.person('p-ana').name, 'Ana One', 'still shown');
        assert.equal(queue.status().state, 'waiting');
        await time.advance(2000);
        assert.equal(server.calls.length, 2);
        assert.equal(server.calls[1].body.changesetId, server.calls[0].body.changesetId);
        await server.reply(appliedAnswer(server.calls[1]));
        assert.equal(queue.status().state, 'saved');
    });
}
