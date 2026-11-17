// sheet_puzzles.js (competition toolkit A4) - on a `pick` round a result edit takes the per-puzzle record along in one
// group (never a `puzzles_mismatch` dead end), the "Puzzles" column, live updates carrying `puzzles`; total rounds
// unchanged.

import assert from 'node:assert/strict';
import { officialEdit, officialEdits } from '../../assets/participants_sheet/sheet_results.js';
import { recordCellText, serverRecord, withPickRecords } from '../../assets/participants_sheet/sheet_puzzles.js';
import { SheetModel } from '../../assets/participants_sheet/sheet_model.js';
import { PendingChanges } from '../../assets/official_results_pending_changes.js';
import { ROUND_PAIRS, ROUND_SOLO, ROUND_TEAMS, smallState } from '../participants-sheet-core/fixture.mjs';

const A = 'rp-a';
const B = 'rp-b';
const C = 'rp-c';
const FORMAT = {
    mode: 'pick',
    toSolve: 2,
    puzzles: [
        { roundPuzzleId: A, position: 1, label: 'A', hidden: false, name: 'Lighthouse', piecesCount: 500 },
        { roundPuzzleId: B, position: 2, label: 'B', hidden: true, name: null, piecesCount: 500 },
        { roundPuzzleId: C, position: 3, label: 'C', hidden: true, name: null, piecesCount: 500 },
    ],
};
const RECORD = [{ roundPuzzleId: B, done: true, atSeconds: 2470 }, { roundPuzzleId: A, done: false, piecesPlaced: 312 }];

/** The small event with its Teams round as pick 2 of 3; Flat holds an unfinished result with its record. */
function pickState() {
    const state = smallState();
    state.rounds = state.rounds.map((round) => (round.id === ROUND_TEAMS ? { ...round, piecesCount: null, puzzleFormat: FORMAT } : round));
    state.teams = state.teams.map((team) => {
        if (team.roundId !== ROUND_TEAMS) {
            return team;
        }

        return team.id === 't-flat' ? { ...team, result: { piecesPlaced: 812 }, puzzles: RECORD } : { ...team, puzzles: null };
    });

    return state;
}

function pendingOf() {
    const queues = new Map();

    return (roundId) => {
        if (!queues.has(roundId)) {
            queues.set(roundId, new PendingChanges());
        }

        return queues.get(roundId);
    };
}

export default function (test) {
    test('a total round: the action comes back as it was (same object), nothing grouped', () => {
        const model = new SheetModel(smallState(), { now: () => 0 });
        const action = officialEdit(ROUND_PAIRS, 'team:t-corners', 'result', null, { seconds: 4000 });
        const outcome = withPickRecords(action, model, pendingOf());

        assert.equal(outcome.action, action);
        assert.deepEqual(outcome.cleared, []);
        assert.equal(serverRecord(model, 'team:t-corners'), null);
        assert.equal(recordCellText(model, ROUND_PAIRS, 'team:t-corners', null, { pieces: '%placed% pcs' }), '');
    });

    test('a pick round entry without a record: the result goes alone, as today', () => {
        const model = new SheetModel(pickState(), { now: () => 0 });
        const action = officialEdit(ROUND_TEAMS, 'team:t-edge', 'result', null, { seconds: 4000 });

        assert.equal(withPickRecords(action, model, pendingOf()).action, action);
    });

    test('a time typed over an unfinished record: the chosen puzzles kept, all finished, one group, one undo', () => {
        const model = new SheetModel(pickState(), { now: () => 0 });
        const action = officialEdit(ROUND_TEAMS, 'team:t-flat', 'result', { piecesPlaced: 812 }, { seconds: 4000 });
        const { action: grouped, cleared } = withPickRecords(action, model, pendingOf());

        assert.deepEqual(cleared, []);
        assert.deepEqual(grouped.results, [
            { roundId: ROUND_TEAMS, ref: 'team:t-flat', field: 'result', from: { piecesPlaced: 812 }, to: { seconds: 4000 }, group: 'puzzles:team:t-flat' },
            { roundId: ROUND_TEAMS, ref: 'team:t-flat', field: 'puzzles', from: RECORD, to: [{ roundPuzzleId: B, done: true, atSeconds: 2470 }, { roundPuzzleId: A, done: true }], group: 'puzzles:team:t-flat' },
        ]);
        // The undo brings both back together
        assert.deepEqual(grouped.inverseResults, [
            { roundId: ROUND_TEAMS, ref: 'team:t-flat', field: 'result', from: { seconds: 4000 }, to: { piecesPlaced: 812 }, group: 'puzzles:team:t-flat' },
            { roundId: ROUND_TEAMS, ref: 'team:t-flat', field: 'puzzles', from: [{ roundPuzzleId: B, done: true, atSeconds: 2470 }, { roundPuzzleId: A, done: true }], to: RECORD, group: 'puzzles:team:t-flat' },
        ]);
        // An undo of it is not expanded again
        const undo = { ...grouped, results: grouped.inverseResults, inverseResults: grouped.results };
        assert.equal(withPickRecords(undo, model, pendingOf()).action, undo);
    });

    test('a split later than the typed time is dropped - the record still fits (no puzzles_mismatch)', () => {
        const model = new SheetModel(pickState(), { now: () => 0 });
        const { action } = withPickRecords(officialEdit(ROUND_TEAMS, 'team:t-flat', 'result', { piecesPlaced: 812 }, { seconds: 2000 }), model, pendingOf());

        assert.deepEqual(action.results[1].to, [{ roundPuzzleId: B, done: true }, { roundPuzzleId: A, done: true }]);
    });

    test('other pieces: the last chosen puzzle gets the rest, the first counts in full', () => {
        const model = new SheetModel(pickState(), { now: () => 0 });
        const { action } = withPickRecords(officialEdit(ROUND_TEAMS, 'team:t-flat', 'result', { piecesPlaced: 812 }, { piecesPlaced: 900 }), model, pendingOf());

        assert.deepEqual(action.results[1].to, [{ roundPuzzleId: B, done: true, atSeconds: 2470 }, { roundPuzzleId: A, done: false, piecesPlaced: 400 }]);
    });

    test('did not start, or pieces that cannot fit: the record is cleared and the organiser told', () => {
        const model = new SheetModel(pickState(), { now: () => 0 });
        const dns = withPickRecords(officialEdit(ROUND_TEAMS, 'team:t-flat', 'result', { piecesPlaced: 812 }, { didNotStart: true }), model, pendingOf());

        assert.deepEqual(dns.action.results[1].to, null);
        assert.deepEqual(dns.cleared, ['team:t-flat']);

        // 1,200 pieces: 500 for B, then 700 on a 500-piece puzzle - no record fits
        const tooMany = withPickRecords(officialEdit(ROUND_TEAMS, 'team:t-flat', 'result', { piecesPlaced: 812 }, { piecesPlaced: 1200 }), model, pendingOf());
        assert.equal(tooMany.action.results[1].to, null);
        assert.deepEqual(tooMany.cleared, ['team:t-flat']);
    });

    test('a result cleared keeps the record (no result yet = anything fits)', () => {
        const model = new SheetModel(pickState(), { now: () => 0 });
        const action = officialEdit(ROUND_TEAMS, 'team:t-flat', 'result', { piecesPlaced: 812 }, null);

        assert.equal(withPickRecords(action, model, pendingOf()).action, action);
    });

    test('the queue gets one group: never split, both sent in one request', () => {
        const model = new SheetModel(pickState(), { now: () => 0 });
        const pending = pendingOf();
        const { action } = withPickRecords(officialEdits([
            { roundId: ROUND_TEAMS, ref: 'team:t-edge', field: 'qualified', from: false, to: true },
            { roundId: ROUND_TEAMS, ref: 'team:t-flat', field: 'result', from: { piecesPlaced: 812 }, to: { seconds: 4000 } },
        ]), model, pending);

        for (const change of action.results) {
            pending(change.roundId).set(change.ref, change.field, change.to, change.from, change.group ?? null);
        }

        let counter = 0;
        const first = pending(ROUND_TEAMS).take(() => `c${++counter}`, 2);
        // The qualified mark fills the request partly - the group waits for the next one, whole
        assert.deepEqual(first.map((change) => change.field), ['qualified']);
        pending(ROUND_TEAMS).settle({ clientChangeId: first[0].clientChangeId, status: 'applied' });
        const second = pending(ROUND_TEAMS).take(() => `c${++counter}`, 2);
        assert.deepEqual(second.map((change) => change.field), ['result', 'puzzles']);
    });

    test('the Puzzles cell: labels in solving order, unsaved values shown', () => {
        const model = new SheetModel(pickState(), { now: () => 0 });
        const pending = new PendingChanges();

        assert.equal(recordCellText(model, ROUND_TEAMS, 'team:t-flat', pending, { pieces: '%placed% pcs' }), 'B ✓ 0:41:10 · A 312 pcs');
        assert.equal(recordCellText(model, ROUND_TEAMS, 'team:t-edge', pending, { pieces: '%placed% pcs' }), '');
        pending.set('team:t-flat', 'puzzles', [{ roundPuzzleId: C, done: false }], RECORD);
        assert.equal(recordCellText(model, ROUND_TEAMS, 'team:t-flat', pending, { pieces: '%placed% pcs' }), 'C');
    });

    test('live updates carry `puzzles` on pick rounds only; a solo pick round entry by its place id', () => {
        const state = pickState();
        state.rounds = state.rounds.map((round) => (round.id === ROUND_SOLO ? { ...round, puzzleFormat: FORMAT } : round));
        state.places = state.places.map((entry) => (entry.roundId === ROUND_SOLO ? { ...entry, puzzles: null } : entry));
        const model = new SheetModel(state, { now: () => 0 });

        model.mergeEntries([{ ref: 'team:t-edge', tableNumber: null, result: null, qualified: false, enteredAt: null, enteredBy: null, puzzles: [{ roundPuzzleId: A, done: false }] }]);
        assert.deepEqual(serverRecord(model, 'team:t-edge'), [{ roundPuzzleId: A, done: false }]);

        model.mergeEntries([{ ref: 'participant_round:e-pat-solo', tableNumber: null, result: null, qualified: false, enteredAt: null, enteredBy: null, puzzles: [{ roundPuzzleId: C, done: false }] }]);
        assert.deepEqual(serverRecord(model, 'participant_round:e-pat-solo'), [{ roundPuzzleId: C, done: false }]);

        // A total round's entry never gets the key
        model.mergeEntries([{ ref: 'team:t-corners', tableNumber: 2, result: { seconds: 3000 }, qualified: false, enteredAt: '2026-10-08T08:30:00+00:00', enteredBy: null }]);
        assert.equal('puzzles' in model.team('t-corners'), false);
    });
}
