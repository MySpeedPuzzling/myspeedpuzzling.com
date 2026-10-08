// sheet_results.js - the grammar of a result cell, table numbers, what a cell shows, ranks and the RecordRoundResults
// changes (with their exact inverse) an edit sends.

import assert from 'node:assert/strict';
import {
    enteredLabel,
    officialEdit,
    officialEdits,
    parseResultInput,
    parseTableNumber,
    parsedValue,
    resultEditText,
    resultKind,
    resultPreview,
    resultText,
    roundRanks,
    swapAsResults,
    swapAssignments,
    tableHolder,
    wordList,
} from '../../assets/participants_sheet/sheet_results.js';
import { SheetModel } from '../../assets/participants_sheet/sheet_model.js';
import { SheetSaveQueue, DEBOUNCE_MS } from '../../assets/participants_sheet/sheet_save_queue.js';
import { ROUND_TEAMS, clock, fakeServer, ids, smallState } from '../participants-sheet-core/fixture.mjs';

const PREVIEW = {
    finished: 'Finished in %time%',
    didNotFinish: "Didn't finish: %placed% pieces placed",
    didNotStart: 'Did not start',
    noResult: 'No result',
    invalid: 'invalid',
    outOfRange: 'out of range',
    piecesRange: 'pieces 1 to %max%',
};
const SHOWN = { piecesPlaced: '%placed% pcs', piecesPlacedOf: '%placed% / %pieces% pcs', didNotStart: 'Did not start' };

const result = (input, options = {}) => parsedValue(parseResultInput(input, options));

export default function (test) {
    test('times: h:mm:ss, mm:ss and digits right-aligned (parseResultTime)', () => {
        assert.deepEqual(result('1:23:45'), { seconds: 5025 });
        assert.deepEqual(result('58:12'), { seconds: 3492 });
        assert.deepEqual(result('83:45'), { seconds: 5025 });
        assert.deepEqual(result('12345'), { seconds: 5025 });
        assert.deepEqual(result('5812'), { seconds: 3492 });
        assert.deepEqual(result('45'), { seconds: 45 });
        assert.deepEqual(result(' 1.23.45 '), { seconds: 5025 });
    });

    test('pieces placed: 479p, 479 p, 479 pcs, 479 pieces, 479/500, case and a trailing dot do not matter', () => {
        for (const input of ['479p', '479 p', '479 pcs', '479 PCS', '479pcs.', '479 pieces', '479 piece', '479/500', '479 / 500', '479/500 pcs']) {
            assert.deepEqual(result(input, { piecesCount: 500 }), { piecesPlaced: 479 }, input);
        }
    });

    test('pieces placed are 1 .. pieces - 1 (a finished puzzle gets a time)', () => {
        assert.deepEqual(parseResultInput('500p', { piecesCount: 500 }), { kind: 'error', reason: 'pieces_range', max: 499 });
        assert.deepEqual(parseResultInput('0p', { piecesCount: 500 }), { kind: 'error', reason: 'pieces_range', max: 499 });
        assert.deepEqual(result('499p', { piecesCount: 500 }), { piecesPlaced: 499 });
        // No single puzzle: the typed total, else the server's own upper bound
        assert.deepEqual(parseResultInput('300/300'), { kind: 'error', reason: 'pieces_range', max: 299 });
        assert.deepEqual(result('299/300'), { piecesPlaced: 299 });
        assert.deepEqual(result('99999p'), { piecesPlaced: 99999 });
    });

    test('did not start: DNS, any dash, the words of the page language', () => {
        for (const input of ['DNS', 'dns', '-', '–', '—', 'did not start', 'Did not start.']) {
            assert.deepEqual(result(input), { didNotStart: true }, input);
        }

        assert.deepEqual(result('Nenastoupil', { didNotStartWords: wordList('nenastoupil, nestartoval') }), { didNotStart: true });
        assert.deepEqual(result('479 dílků', { piecesCount: 1000, piecesWords: wordList('dílků, dílky') }), { piecesPlaced: 479 });
    });

    test('empty = no result; anything else is an error with the reason', () => {
        assert.deepEqual(parseResultInput('  '), { kind: 'empty' });
        assert.equal(result(''), null);
        assert.deepEqual(parseResultInput('abc'), { kind: 'error', reason: 'invalid' });
        assert.deepEqual(parseResultInput('1:60:00'), { kind: 'error', reason: 'invalid' });
        assert.deepEqual(parseResultInput('479'), { kind: 'error', reason: 'invalid' });
        assert.deepEqual(parseResultInput('24:00:00'), { kind: 'error', reason: 'out_of_range' });
        assert.equal(parsedValue(parseResultInput('abc')), undefined);
    });

    test('the editor starts with text that means the same value again', () => {
        for (const value of [{ seconds: 5025 }, { seconds: 59 }, { piecesPlaced: 479 }, { didNotStart: true }, null]) {
            assert.deepEqual(result(resultEditText(value), { piecesCount: 500 }), value);
        }

        assert.equal(resultEditText({ seconds: 5025 }), '1:23:45');
        assert.equal(resultEditText({ piecesPlaced: 479 }), '479p');
    });

    test('what a cell shows and the line under the editor', () => {
        assert.equal(resultText({ seconds: 3492 }, 500, SHOWN), '0:58:12');
        assert.equal(resultText({ piecesPlaced: 479 }, 500, SHOWN), '479 / 500 pcs');
        assert.equal(resultText({ piecesPlaced: 479 }, null, SHOWN), '479 pcs');
        assert.equal(resultText({ didNotStart: true }, null, SHOWN), 'Did not start');
        assert.equal(resultText(null, 500, SHOWN), '');
        assert.equal(resultPreview(parseResultInput('1:23:45'), PREVIEW), 'Finished in 1:23:45');
        assert.equal(resultPreview(parseResultInput('479p', { piecesCount: 500 }), PREVIEW), "Didn't finish: 479 pieces placed");
        assert.equal(resultPreview(parseResultInput('DNS'), PREVIEW), 'Did not start');
        assert.equal(resultPreview(parseResultInput(''), PREVIEW), 'No result');
        assert.equal(resultPreview(parseResultInput('600p', { piecesCount: 500 }), PREVIEW), 'pieces 1 to 499');
        assert.equal(resultPreview(parseResultInput('x'), PREVIEW), 'invalid');
        assert.deepEqual(['finished', 'unfinished', 'dns', 'none'], [{ seconds: 1 }, { piecesPlaced: 1 }, { didNotStart: true }, null].map(resultKind));
    });

    test('table numbers: 1 .. 9999, empty = none', () => {
        assert.deepEqual(parseTableNumber('6'), { kind: 'number', value: 6 });
        assert.deepEqual(parseTableNumber(' #12 '), { kind: 'number', value: 12 });
        assert.deepEqual(parseTableNumber('9999'), { kind: 'number', value: 9999 });
        assert.deepEqual(parseTableNumber(''), { kind: 'empty' });

        for (const input of ['0', '10000', 'x', '1.5', '-3']) {
            assert.deepEqual(parseTableNumber(input), { kind: 'error', reason: 'invalid_table_number' }, input);
        }
    });

    test('"Table 6 is Ben\'s · Swap them": the holder, and one write with both entries\' from', () => {
        const entries = [{ ref: 'team:a', table: 3, displayName: 'Ann' }, { ref: 'team:b', table: 6, displayName: 'Ben' }, { ref: 'team:c', table: null, displayName: 'Cy' }];
        assert.equal(tableHolder(entries, 6, 'team:a').displayName, 'Ben');
        assert.equal(tableHolder(entries, 6, 'team:b'), null);
        assert.equal(tableHolder(entries, null, 'team:a'), null);

        const swap = swapAssignments(entries[0], 6, entries[1]);
        assert.deepEqual(swap, [{ entry: 'team:a', from: 3, number: 6 }, { entry: 'team:b', from: 6, number: 3 }]);
        // An entry without a table hands the holder none
        assert.deepEqual(swapAssignments(entries[2], 6, entries[1]), [{ entry: 'team:c', from: null, number: 6 }, { entry: 'team:b', from: 6, number: null }]);
        // Its undo as RecordRoundResults changes: the same two numbers back, three-way checked
        assert.deepEqual(swapAsResults('r1', swap), [
            { roundId: 'r1', ref: 'team:a', field: 'table_number', from: 3, to: 6 },
            { roundId: 'r1', ref: 'team:b', field: 'table_number', from: 6, to: 3 },
        ]);
    });

    test('an edit sends from = what the organiser saw, its undo the swapped change', () => {
        const action = officialEdit('r1', 'team:a', 'result', { seconds: 3600 }, { seconds: 3492 });
        assert.deepEqual(action.results, [{ roundId: 'r1', ref: 'team:a', field: 'result', from: { seconds: 3600 }, to: { seconds: 3492 } }]);
        assert.deepEqual(action.inverseResults, [{ roundId: 'r1', ref: 'team:a', field: 'result', from: { seconds: 3492 }, to: { seconds: 3600 } }]);
        assert.deepEqual(action.groups, []);
        assert.deepEqual(officialEdit('r1', 'team:a', 'qualified', false, true).inverseResults[0], { roundId: 'r1', ref: 'team:a', field: 'qualified', from: true, to: false });
        // Nothing changed - nothing sent
        assert.deepEqual(officialEdit('r1', 'team:a', 'result', { seconds: 1 }, { seconds: 1 }).results, []);

        const several = officialEdits([
            { roundId: 'r1', ref: 'team:a', field: 'result', from: null, to: { seconds: 1 } },
            { roundId: 'r1', ref: 'team:b', field: 'result', from: { seconds: 2 }, to: { seconds: 3 } },
        ], { key: 'paste' });
        assert.equal(several.label.key, 'paste');
        assert.deepEqual(several.inverseResults.map((change) => change.ref), ['team:b', 'team:a']);
    });

    test('ranks as the page shows them: ties share a rank, did not start and no result are unranked', () => {
        const ranks = roundRanks([
            { id: 'a', displayName: 'A', tableNumber: 1, result: { seconds: 100 } },
            { id: 'b', displayName: 'B', tableNumber: 2, result: { seconds: 90 } },
            { id: 'c', displayName: 'C', tableNumber: 3, result: { seconds: 100 } },
            { id: 'd', displayName: 'D', tableNumber: 4, result: { piecesPlaced: 400 } },
            { id: 'e', displayName: 'E', tableNumber: 5, result: { didNotStart: true } },
            { id: 'f', displayName: 'F', tableNumber: 6, result: null },
        ]);
        assert.deepEqual(Object.fromEntries(ranks), { b: 1, a: 2, c: 2, d: 4, e: null, f: null });
    });

    test('a qualified mark ticked and unticked before it was sent sends nothing and leaves no "saving" marker', async () => {
        const time = clock();
        const server = fakeServer();
        const model = new SheetModel(smallState(), { now: time.now });
        const queue = new SheetSaveQueue({
            model,
            urls: { changes: '/c', state: '/s', record: '/r/__ROUND__', tables: '/t/__ROUND__' },
            csrfToken: 'csrf',
            request: server.request,
            newId: ids('cs'),
            schedule: time.schedule,
            cancel: time.cancel,
            now: time.now,
        });
        // What the controller's act() does with an action's results
        const act = (action) => {
            for (const change of action.results) {
                queue.results(change.roundId).set(change.ref, change.field, change.to, change.from);
            }

            queue.enqueueResults(ROUND_TEAMS);
        };

        act(officialEdit(ROUND_TEAMS, 'team:t-edge', 'qualified', false, true));
        assert.equal(model.marks.get('result:team:t-edge:qualified')?.state, 'saving');
        act(officialEdit(ROUND_TEAMS, 'team:t-edge', 'qualified', true, false));
        assert.equal(model.marks.get('result:team:t-edge:qualified'), null);

        await time.advance(DEBOUNCE_MS + 10);
        assert.equal(server.open().length, 0);
        assert.equal(queue.status().state, 'saved');
    });

    test('"entered by Eva · 10:42" in the round\'s time zone', () => {
        assert.equal(enteredLabel('Eva', '2026-10-08T08:42:00+00:00', { locale: 'en-GB', timeZone: 'Europe/Prague', template: 'entered by %name% · %time%' }), 'entered by Eva · 10:42');
        assert.equal(enteredLabel(null, null), '');
    });
}
