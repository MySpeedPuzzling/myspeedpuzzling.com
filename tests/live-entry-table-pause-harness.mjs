// Runs assets/live_entry_table_pause.js (a typed table name opens after a pause) and the solve-all order of
// assets/live_entry_puzzles.js (spws-demo-ux-leftovers.md §2 + §3) through their scenarios and prints
// {scenario: "ok" | error} as JSON - tests/LiveEntryTablePauseScriptsTest.php asserts every one is "ok".

import assert from 'node:assert/strict';
import { pausedTable, pauseStillValid, TABLE_PAUSE_MS } from '../assets/live_entry_table_pause.js';
import { buildSearchIndex, uniqueTable } from '../assets/official_results_live.js';
import { tableLabels } from '../assets/table_labels.js';
import { saveFields, solveAllOrder, solvesAll } from '../assets/live_entry_puzzles.js';

const HALL = ['A1', 'A2', 'A3', 'A4', 'A5', 'A6', 'A7', 'A8', 'A9', 'A10', 'A11', 'A12', 'B1', 'B2'];

function seated(list, seats = list.map((_, index) => index + 1)) {
    const lookup = tableLabels(list);
    const entries = seats.map((tableNumber, index) => ({ ref: `p:${index + 1}`, name: `Entrant ${index + 1}`, members: [], tableNumber }));

    return { lookup, index: buildSearchIndex(entries, lookup) };
}

const opened = ({ lookup, index }, text) => {
    const entry = pausedTable(index, text, { labels: lookup });

    return entry === null ? null : lookup.labelOf(entry.tableNumber);
};

const P1 = '018d0020-0000-0000-0000-00000000a001';
const P2 = '018d0020-0000-0000-0000-00000000b002';
const P3 = '018d0020-0000-0000-0000-00000000c003';

function format(toSolve, puzzles = [[P1, 1], [P2, 2]]) {
    return { mode: 'pick', toSolve, puzzles: puzzles.map(([roundPuzzleId, position]) => ({ roundPuzzleId, position, label: `Challenge ${position}` })) };
}

const scenarios = {
    'a table name others continue opens after the pause, not at once'() {
        const round = seated(HALL);
        // C7: `a1` waits while A10, A11, A12 continue it
        assert.equal(uniqueTable(round.index, 'a1', { labels: round.lookup }), null);
        assert.equal(opened(round, 'a1'), 'A1');
        assert.equal(opened(round, ' A1 '), 'A1', 'case and spaces fold');
        assert.equal(opened(round, 'a10'), 'A10');
        assert.ok(TABLE_PAUSE_MS >= 600 && TABLE_PAUSE_MS <= 800);
    },
    'no pause-open for what names no table exactly'() {
        const round = seated(HALL);
        assert.equal(opened(round, 'a'), null, 'no digit - a row or a name');
        assert.equal(opened(round, 'a13'), null, 'no such table');
        assert.equal(opened(round, 'anna'), null);
        assert.equal(opened(round, ''), null);
    },
    'two entries at one table open nothing'() {
        const round = seated(HALL, [1, 1, 2]);
        assert.equal(opened(round, 'a1'), null);
        assert.equal(opened(round, 'a2'), 'A2');
    },
    'a table without an entry opens nothing'() {
        const round = seated(HALL, [2, 3]);
        assert.equal(opened(round, 'a1'), null);
    },
    'rounds of plain numbers keep C7 - no pause-open'() {
        const entries = [1, 2, 10, 11].map((tableNumber, index) => ({ ref: `p:${index}`, name: `E${index}`, members: [], tableNumber }));
        const index = buildSearchIndex(entries, null);
        assert.equal(pausedTable(index, '1', { labels: null }), null);
        assert.equal(uniqueTable(index, '1'), null, 'C7 waits for 1x as before');
        assert.equal(uniqueTable(index, '2')?.tableNumber, 2);
    },
    'table numbers switched off: nothing opens'() {
        const round = seated(HALL);
        assert.equal(pausedTable(round.index, 'a1', { labels: round.lookup, tableNumbersOff: true }), null);
    },
    'a fired pause opens only for the same text on the find view'() {
        assert.equal(pauseStillValid('a1', 'a1', 'find'), true);
        assert.equal(pauseStillValid('a1', 'a10', 'find'), false, 'typed on');
        assert.equal(pauseStillValid('a1', 'a1', 'entry'), false, 'an entry opened meanwhile');
        assert.equal(pauseStillValid('', '', 'find'), false);
        assert.equal(pauseStillValid('a1', 'a1', 'find', false), false, 'the box lost the focus (scanner, another field)');
    },
    'a name ending in a separator is still being typed'() {
        const round = seated(['A1-1', 'A1-2', 'A1', 'B1']);
        assert.equal(opened(round, 'a1-'), null);
        assert.equal(opened(round, 'a1-1'), 'A1-1');
        assert.equal(opened(round, 'a1'), 'A1');
    },
    'solve all: the choice completed in the round order'() {
        const solveAll = format(2);
        assert.equal(solvesAll(solveAll), true);
        assert.deepEqual(solveAllOrder(solveAll, []), [P1, P2], 'nothing tapped: Challenge 1 then 2');
        assert.deepEqual(solveAllOrder(solveAll, [P1]), [P1, P2], 'Challenge 1 in: Challenge 2 next');
        assert.deepEqual(solveAllOrder(solveAll, [P2]), [P2, P1], 'tapped otherwise: kept, the rest after');
        assert.deepEqual(solveAllOrder(solveAll, [P2, P1]), [P2, P1]);
        // Position decides, not the list order
        assert.deepEqual(solveAllOrder(format(3, [[P3, 3], [P1, 1], [P2, 2]]), []), [P1, P2, P3]);
        // Upper-case ids from a record fold like choiceOf()
        assert.deepEqual(solveAllOrder(solveAll, [P1.toUpperCase()]), [P1, P2]);
    },
    'a finished block with its splits: the same order is no change (no "Save order" for nothing)'() {
        const solveAll = format(2);
        const record = [{ roundPuzzleId: P1, done: true, atSeconds: 1233 }, { roundPuzzleId: P2, done: true, atSeconds: 2564 }];
        const entry = { ref: 'team:1', result: { seconds: 2564 }, puzzles: record };
        assert.deepEqual(saveFields(solveAll, [P1, P2], { seconds: 2564 }, entry, []).fields, []);
        // Another order, or another time, is still a change
        assert.equal(saveFields(solveAll, [P2, P1], { seconds: 2564 }, entry, []).fields.some((field) => field.field === 'puzzles'), true);
        assert.deepEqual(saveFields(solveAll, [P1, P2], { seconds: 2600 }, entry, []).fields.map((field) => field.field), ['puzzles', 'result']);
    },
    'other rounds keep their choice'() {
        assert.equal(solvesAll(format(1)), false, 'pick 1 of 2');
        assert.deepEqual(solveAllOrder(format(1), []), []);
        assert.deepEqual(solveAllOrder(format(2, [[P1, 1], [P2, 2], [P3, 3]]), [P1]), [P1], 'pick 2 of 3');
        assert.deepEqual(solveAllOrder(format(1, [[P1, 1]]), []), [], 'one puzzle');
        assert.deepEqual(solveAllOrder(null, []), []);
        assert.deepEqual(solveAllOrder(format(2), ['not-of-the-round']), ['not-of-the-round']);
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
