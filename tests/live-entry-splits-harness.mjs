// Runs assets/live_entry_splits.js (real events W5 - the judge's "Finished now" per puzzle on a pick round) through its
// scenarios and prints {scenario: "ok" | error} as JSON - tests/LiveEntrySplitsScriptsTest.php asserts every one is "ok".
// The outbox part runs the real official_results_outbox.js on an in-memory store with a fake server.

import assert from 'node:assert/strict';
import { candidates, finishChoice, finishFields, splitFormat, splitRows, splitStep, withFinishSplit } from '../assets/live_entry_splits.js';
import { saveFields } from '../assets/live_entry_puzzles.js';
import { validate } from '../assets/official_results_puzzles.js';
import { createMemoryStorage, Outbox } from '../assets/official_results_outbox.js';
import { shownValue, withShownFrom } from '../assets/official_results_live.js';

const P_A = '018d0020-0000-0000-0000-00000000a001';
const P_B = '018d0020-0000-0000-0000-00000000b002';
const P_C = '018d0020-0000-0000-0000-00000000c003';
const ROUND = '018d0020-0000-0000-0000-000000000101';
const ANNA = 'participant_round:018d0020-0000-0000-0000-000000000301';

function format(toSolve, count = 2) {
    return {
        mode: 'pick',
        toSolve,
        puzzles: [
            { roundPuzzleId: P_A, position: 1, label: 'Challenge 1', hidden: false, piecesCount: 500 },
            { roundPuzzleId: P_B, position: 2, label: 'Challenge 2', hidden: true, piecesCount: 1000 },
            { roundPuzzleId: P_C, position: 3, label: 'C', hidden: true, piecesCount: 500 },
        ].slice(0, count),
    };
}

function entry(extra = {}) {
    return { ref: ANNA, kind: 'person', name: 'Anna', displayName: 'Anna', members: [], tableNumber: 7, result: null, puzzles: null, ...extra };
}

function setup() {
    const server = {
        requests: [],
        answers: [],
        async send(roundId, changes) {
            server.requests.push(JSON.parse(JSON.stringify(changes)));

            if (server.answers.length > 0) {
                return server.answers.shift();
            }

            return { kind: 'ok', status: 200, data: { dryRun: false, outcomes: changes.map((change) => ({ clientChangeId: change.clientChangeId, entry: change.entry ?? null, field: change.field, status: 'applied' })), entries: [] } };
        },
    };
    let ids = 0;
    const clock = { now: 1_800_000_000_000 };
    const outbox = new Outbox({
        storage: createMemoryStorage(),
        userId: 'referee-1',
        send: (roundId, changes) => server.send(roundId, changes),
        now: () => clock.now,
        newId: () => `00000000-0000-4000-8000-${String(++ids).padStart(12, '0')}`,
    });

    return { outbox, server, clock };
}

// The controller's enqueueGroup() in miniature
function enqueue(outbox, fields) {
    return outbox.enqueueGroup(fields.map(({ field, to, from }) => ({ roundId: ROUND, entryRef: ANNA, field, serverValue: from ?? null, to })));
}

const scenarios = {
    'only pick rounds where an entry solves 2 or more puzzles'() {
        assert.equal(splitFormat({ puzzleFormat: null }), null, 'a total round: nothing changes');
        assert.equal(splitFormat({}), null);
        assert.equal(splitFormat({ puzzleFormat: format(1) }), null, 'Pick 1: the round\'s Finished now is the puzzle\'s time');
        assert.equal(splitFormat({ puzzleFormat: format(2) }).toSolve, 2);
    },

    'rows: nothing before the first tap unless the stopwatch runs; candidates only without a result'() {
        const solveAll = format(2);
        assert.deepEqual(splitRows(solveAll, null, [], null, false), { show: false, rows: [] }, 'stopwatch not running, nothing recorded: the card as it was');

        const running = splitRows(solveAll, null, [], null, true);
        assert.equal(running.show, true);
        assert.deepEqual(running.rows.map((row) => [row.label, row.tap, row.edit, row.done]), [['Challenge 1', true, true, false], ['Challenge 2', true, true, false]]);

        // A result already there (typed, or the round's Finished now): no taps
        assert.deepEqual(splitRows(solveAll, null, [], { seconds: 3000 }, true), { show: false, rows: [] });

        const record = [{ roundPuzzleId: P_B, done: true, atSeconds: 1500 }, { roundPuzzleId: P_A, done: true, atSeconds: 3000 }];
        const finished = splitRows(solveAll, record, [P_B, P_A], { seconds: 3000 }, false);
        assert.deepEqual(finished.rows.map((row) => [row.label, row.order, row.at, row.own, row.edit, row.tap]), [['Challenge 2', 1, 1500, 1500, true, false], ['Challenge 1', 2, 3000, 1500, false, false]], 'the last split of a finished entry is its result - not edited here');
    },

    'candidates: once K are chosen only those, else every puzzle not finished (the chosen first)'() {
        const pick2of3 = format(2, 3);
        assert.deepEqual(candidates(pick2of3, null, []), [P_A, P_B, P_C]);
        assert.deepEqual(candidates(pick2of3, null, [P_C]), [P_C, P_A, P_B]);
        assert.deepEqual(candidates(pick2of3, null, [P_C, P_A]), [P_C, P_A]);
        assert.deepEqual(candidates(pick2of3, [{ roundPuzzleId: P_B, done: true, atSeconds: 900 }], []), [P_A, P_C]);
        assert.deepEqual(candidates(pick2of3, [{ roundPuzzleId: P_B, done: true, atSeconds: 900 }, { roundPuzzleId: P_C, done: false }], [P_B, P_C]), [P_C]);
        assert.deepEqual(candidates(format(2), [{ roundPuzzleId: P_A, done: true }, { roundPuzzleId: P_B, done: true }], []), []);
    },

    'a tap that is not the K-th: the record alone, the next chosen stays chosen'() {
        const solveAll = format(2);
        const first = splitStep(solveAll, null, [], P_B, 1500, null);
        assert.equal(first.refusal, null);
        assert.equal(first.finishes, false);
        assert.deepEqual(first.record, [{ roundPuzzleId: P_B, done: true, atSeconds: 1500 }]);
        assert.equal(validate(first.record, solveAll, null), null, 'the server takes it (no result yet)');

        // Chips chosen before: both stay, the tapped one first
        const chosen = splitStep(solveAll, [{ roundPuzzleId: P_A, done: false }, { roundPuzzleId: P_B, done: false }], [P_A, P_B], P_B, 1500, null);
        assert.deepEqual(chosen.record, [{ roundPuzzleId: P_B, done: true, atSeconds: 1500 }, { roundPuzzleId: P_A, done: false }]);
        assert.deepEqual(chosen.choice, [P_B, P_A]);
    },

    'the K-th tap finishes: the choice to confirm, the result goes along with the last split'() {
        const solveAll = format(2);
        const known = [{ roundPuzzleId: P_B, done: true, atSeconds: 1500 }];
        const last = splitStep(solveAll, known, [P_B], P_A, 3123, null);
        assert.equal(last.finishes, true);
        assert.deepEqual(last.choice, [P_B, P_A]);

        // The confirm step's Save: saveFields() + finishFields() = puzzles (both splits) + result, one group
        const anna = entry({ puzzles: known });
        const save = saveFields(solveAll, last.choice, { seconds: 3123 }, anna, []);
        const { fields, record } = finishFields(solveAll, save.fields, save.record, { seconds: 3123 }, known);
        assert.deepEqual(fields, [
            { field: 'puzzles', to: [{ roundPuzzleId: P_B, done: true, atSeconds: 1500 }, { roundPuzzleId: P_A, done: true, atSeconds: 3123 }] },
            { field: 'result', to: { seconds: 3123 } },
        ]);
        assert.equal(validate(record, solveAll, { seconds: 3123 }), null);
    },

    'refusals before anything is sent: out of order, out of range, a result already there'() {
        const solveAll = format(2);
        const known = [{ roundPuzzleId: P_B, done: true, atSeconds: 1500 }];
        assert.equal(splitStep(solveAll, known, [P_B], P_A, 1500, null).refusal, 'splits_out_of_order', 'the same second as the puzzle before');
        assert.equal(splitStep(solveAll, known, [P_B], P_A, 1200, null).refusal, 'splits_out_of_order');
        assert.equal(splitStep(solveAll, null, [], P_A, 0, null).refusal, 'invalid_split');
        assert.equal(splitStep(solveAll, null, [], P_A, 86400, null).refusal, 'invalid_split');
        assert.equal(splitStep(solveAll, null, [], P_A, 900, { seconds: 3000 }).refusal, 'puzzles_mismatch', 'a result there: the time field, not a tap');
        assert.equal(splitStep(format(2, 3), [{ roundPuzzleId: P_A, done: false }, { roundPuzzleId: P_B, done: false }], [P_A, P_B], P_C, 900, null).refusal, 'puzzles_mismatch', 'Pick 2 of 3 with 2 chosen: not a third');
    },

    'a typed correction of a split: the record alone; never the last split of a finished entry'() {
        const solveAll = format(2);
        const record = [{ roundPuzzleId: P_B, done: true, atSeconds: 1500 }, { roundPuzzleId: P_A, done: true, atSeconds: 3000 }];
        const corrected = splitStep(solveAll, record, [P_B, P_A], P_B, 1450, { seconds: 3000 });
        assert.equal(corrected.refusal, null);
        assert.equal(corrected.finishes, false);
        assert.deepEqual(corrected.record, [{ roundPuzzleId: P_B, done: true, atSeconds: 1450 }, { roundPuzzleId: P_A, done: true, atSeconds: 3000 }]);
        assert.equal(splitStep(solveAll, record, [P_B, P_A], P_B, 3000, { seconds: 3000 }).refusal, 'splits_out_of_order');
        assert.equal(splitStep(solveAll, record, [P_B, P_A], P_A, 3100, { seconds: 3000 }).refusal, 'puzzles_mismatch');
        // Before the result: the first split moves
        assert.deepEqual(splitStep(solveAll, [record[0]], [P_B], P_B, 1600, null).record, [{ roundPuzzleId: P_B, done: true, atSeconds: 1600 }]);
    },

    'the round\'s own Finished now after puzzle 1 was tapped finishes with the one left'() {
        const solveAll = format(2);
        assert.deepEqual(finishChoice(solveAll, [{ roundPuzzleId: P_B, done: true, atSeconds: 1500 }], [P_B]), [P_B, P_A]);
        // Untouched cards: Finished now does what it always did
        assert.equal(finishChoice(solveAll, null, []), null);
        assert.equal(finishChoice(solveAll, [{ roundPuzzleId: P_B, done: true }], [P_B]), null, 'no split recorded: not the per-puzzle flow');
        assert.equal(finishChoice(format(2, 3), [{ roundPuzzleId: P_B, done: true, atSeconds: 900 }], [P_B]), null, 'Pick 2 of 3: which one is left is not known');
        assert.equal(finishChoice(null, [], []), null);
    },

    'the last split is filled only when every earlier one is there; untouched saves stay byte-identical'() {
        const solveAll = format(2);
        const plain = [{ roundPuzzleId: P_A, done: true }, { roundPuzzleId: P_B, done: true }];
        assert.equal(withFinishSplit(solveAll, plain, { seconds: 3000 }), plain, 'no splits: the same array');
        const desk = [{ roundPuzzleId: P_A, done: true, atSeconds: 1500 }, { roundPuzzleId: P_B, done: true }];
        assert.deepEqual(withFinishSplit(solveAll, desk, { seconds: 3000 }), [desk[0], { roundPuzzleId: P_B, done: true, atSeconds: 3000 }]);
        assert.equal(withFinishSplit(solveAll, desk, { seconds: 1400 }), desk, 'a time before the earlier split: as it was (recordFor drops it)');
        assert.equal(withFinishSplit(solveAll, desk, { piecesPlaced: 900 }), desk);
        assert.equal(withFinishSplit(null, desk, { seconds: 3000 }), desk);
        assert.equal(withFinishSplit(solveAll, null, { seconds: 3000 }), null);

        const fields = [{ field: 'result', to: { seconds: 3000 } }];
        assert.equal(finishFields(solveAll, fields, plain, { seconds: 3000 }, null).fields, fields, 'nothing to fill: the fields as saveFields() made them');
        // The record shown is the record without the last split: the change is added
        assert.deepEqual(finishFields(solveAll, fields, desk, { seconds: 3000 }, desk).fields.map((field) => field.field), ['puzzles', 'result']);
    },

    async 'offline tap on puzzle 1, online tap on puzzle 2: chained from, one group, exactly once'() {
        const solveAll = format(2);
        const { outbox, server, clock } = setup();
        const anna = entry();

        // Puzzle 1 offline: stays on the device
        server.answers.push({ kind: 'offline' });
        const first = splitStep(solveAll, null, [], P_A, 1500, null);
        const firstItems = enqueue(outbox, withShownFrom([{ field: 'puzzles', to: first.record }], { result: null, puzzles: null, missing_pieces: null })).items;
        await outbox.flush();
        assert.equal(outbox.all().length, 1);

        // The card builds on the waiting record (shownValue), the K-th tap finishes
        const items = outbox.all();
        const known = shownValue(anna, 'puzzles', items).value;
        assert.deepEqual(known, first.record);
        const last = splitStep(solveAll, known, choiceOfRecord(known), P_B, 3100, shownValue(anna, 'result', items).value);
        assert.equal(last.finishes, true);
        const save = saveFields(solveAll, last.choice, { seconds: 3100 }, anna, items);
        const { fields } = finishFields(solveAll, save.fields, save.record, { seconds: 3100 }, known);
        const group = enqueue(outbox, withShownFrom(fields, { result: null, puzzles: null, missing_pieces: null }));
        assert.equal(group.items.length, 2);
        assert.ok(group.groupId !== null && group.items.every((item) => item.groupId === group.groupId));
        // The group's puzzles change chains from the waiting tap, not from what the server held
        assert.deepEqual(group.items.find((item) => item.field === 'puzzles').from, first.record);

        // Back online after the pause
        clock.now += 5000;
        await group.persisted;
        await outbox.flush();
        assert.equal(outbox.all().length, 0);
        const sent = server.requests.slice(1).flat();
        assert.deepEqual(sent.map((change) => change.field), ['puzzles', 'puzzles', 'result'], 'each change once, in order');
        assert.equal(new Set(sent.map((change) => change.clientChangeId)).size, 3);
        assert.deepEqual(sent[1].to, [{ roundPuzzleId: P_A, done: true, atSeconds: 1500 }, { roundPuzzleId: P_B, done: true, atSeconds: 3100 }]);
        assert.ok(firstItems.length === 1);
    },

    async 'undo of the finishing tap takes back the whole group; the first tap stays'() {
        const solveAll = format(2);
        const { outbox, server } = setup();
        const known = [{ roundPuzzleId: P_A, done: true, atSeconds: 1500 }];
        const anna = entry({ puzzles: known });
        const save = saveFields(solveAll, [P_A, P_B], { seconds: 3100 }, anna, []);
        const { fields } = finishFields(solveAll, save.fields, save.record, { seconds: 3100 }, known);
        const group = enqueue(outbox, withShownFrom(fields, { result: null, puzzles: known, missing_pieces: null }));
        await group.persisted;
        await outbox.flush();
        assert.equal(server.requests.length, 1);

        const opposite = await outbox.undo(group.items);
        assert.deepEqual(opposite.map((item) => [item.field, item.to]), [['puzzles', known], ['result', null]], 'back to puzzle 1 finished, no result');
        assert.ok(opposite.every((item) => item.groupId === opposite[0].groupId));
        await outbox.flush();
        assert.equal(server.requests[1].length, 2, 'the undo goes as one request');
    },
};

function choiceOfRecord(record) {
    return (record ?? []).map((item) => item.roundPuzzleId);
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
