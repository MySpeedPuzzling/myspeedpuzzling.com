// The results desk's per-puzzle editor of `pick` rounds (competition toolkit A4): official_results_desk_puzzles.js
// (the outcome the editor saves, the chips, the participants sheet's consistent record) and the groups of
// PendingChanges (a `result` + `puzzles` pair is never split, keep mine / try again / discard act on both), and the
// desk's `?entry=<ref>` deep link from the participants sheet's Puzzles popover (v2 C5).

import assert from 'node:assert/strict';
import { consistentRecord, deskEntryFromQuery, deskRecordChips, deskEntryRef, deskEntryUrl, editorOutcome, editorRows, ownTimesLine, puzzleOptions, recordChips, recordText, rowOwnTimes } from '../../assets/official_results_desk_puzzles.js';
import { PendingChanges } from '../../assets/official_results_pending_changes.js';

// Round puzzle ids are UUIDs, as on the server (official_results_puzzles.js refuses any other shape like the server)
const A = '018d0020-0000-0000-0000-00000000a00a';
const B = '018d0020-0000-0000-0000-00000000a00b';
const C = '018d0020-0000-0000-0000-00000000a00c';
const FORMAT = {
    mode: 'pick',
    toSolve: 2,
    puzzles: [
        { roundPuzzleId: A, position: 1, label: 'Blue box', hidden: false, name: 'Lighthouse', piecesCount: 500 },
        { roundPuzzleId: B, position: 2, label: 'B', hidden: true, name: null, piecesCount: 500 },
        // A name that came for a hidden puzzle anyway is never shown
        { roundPuzzleId: C, position: 3, label: 'C', hidden: true, name: 'Secret', piecesCount: 1000 },
    ],
};

const row = (id, done = false, at = null, pieces = null) => ({ id, done, at, pieces });

function ids() {
    let counter = 0;

    return () => `c${++counter}`;
}

function grouped(pending) {
    pending.set('team:x', 'result', { seconds: 4000 }, { piecesPlaced: 812 }, 'puzzles:team:x');
    pending.set('team:x', 'puzzles', [{ roundPuzzleId: B, done: true }, { roundPuzzleId: A, done: true, atSeconds: 4000 }], [{ roundPuzzleId: B, done: true }, { roundPuzzleId: A, done: false, piecesPlaced: 312 }], 'puzzles:team:x');
}

export default function (test) {
    test('own times (real events W4): split minus the previous split, the first its split, null without one', () => {
        assert.deepEqual(rowOwnTimes([row(A, true, 1500), row(B, true, 3900), row(C, true, 5000)]), [1500, 2400, 1100]);
        assert.deepEqual(rowOwnTimes([row(A, true, null), row(B, true, 3900)]), [null, null]);
        assert.deepEqual(rowOwnTimes([row(A, true, 1500), row(B, false, null, 300)]), [1500, null]);
        // The same rule as the chips of a stored record
        assert.deepEqual(recordChips(FORMAT, [{ roundPuzzleId: A, done: true, atSeconds: 1500 }, { roundPuzzleId: B, done: true, atSeconds: 3900 }]).map((chip) => chip.duration), [1500, 2400]);
    });

    test('the editor preview line of own times: K >= 2 only, by label, rows without a puzzle left out', () => {
        assert.equal(ownTimesLine(FORMAT, [row(A, true, 1500), row('', false), row(B, true, 3900)]), 'Blue box 0:25:00, B 0:40:00');
        assert.equal(ownTimesLine(FORMAT, [row(A, true, null), row(B, true, 3900)]), '');
        assert.equal(ownTimesLine({ ...FORMAT, toSolve: 1 }, [row(A, true, 1500)]), '');
        assert.equal(ownTimesLine(null, [row(A, true, 1500)]), '');
    });

    test('puzzle options: label always, the name only of a puzzle that is out', () => {
        assert.deepEqual(puzzleOptions(FORMAT), [
            { id: A, label: 'Blue box', name: 'Lighthouse' },
            { id: B, label: 'B', name: null },
            { id: C, label: 'C', name: null },
        ]);
    });

    test('finished: all K chosen, the last "finished at" is the time, earlier splits optional', () => {
        assert.deepEqual(editorOutcome(FORMAT, 'time', [row(B, false, 2470), row(A, false, 4000)]), {
            result: { seconds: 4000 },
            record: [{ roundPuzzleId: B, done: true, atSeconds: 2470 }, { roundPuzzleId: A, done: true, atSeconds: 4000 }],
            error: null,
            refusal: null,
        });
        assert.equal(editorOutcome(FORMAT, 'time', [row(B, true, 2470), row('', false)]).error, 'choose_all');
        assert.equal(editorOutcome(FORMAT, 'time', [row(B), row(A)]).error, 'time_missing');
        // The same puzzle twice - the server's own reason
        assert.equal(editorOutcome(FORMAT, 'time', [row(A, true, 100), row(A, true, 200)]).refusal, 'puzzle_listed_twice');
        // A split after the time
        assert.equal(editorOutcome(FORMAT, 'time', [row(B, true, 4500), row(A, true, 4000)]).refusal, 'splits_out_of_order');
    });

    test('didn\'t finish: the total is computed (a finished puzzle counts all its pieces)', () => {
        assert.deepEqual(editorOutcome(FORMAT, 'pieces', [row(B, true, 2470), row(A, false, null, 312)]), {
            result: { piecesPlaced: 812 },
            record: [{ roundPuzzleId: B, done: true, atSeconds: 2470 }, { roundPuzzleId: A, done: false, piecesPlaced: 312 }],
            error: null,
            refusal: null,
        });
        // Only the puzzle they were on
        assert.deepEqual(editorOutcome(FORMAT, 'pieces', [row(C, false, null, 640), row('')]).result, { piecesPlaced: 640 });
        assert.equal(editorOutcome(FORMAT, 'pieces', [row(''), row('')]).error, 'choose_one');
        assert.equal(editorOutcome(FORMAT, 'pieces', [row(A), row('')]).error, 'pieces_missing');
        // All finished is a time, not pieces
        assert.equal(editorOutcome(FORMAT, 'pieces', [row(B, true), row(A, true)]).refusal, 'puzzles_mismatch');
        // Pieces of a puzzle not finished stay below its count
        assert.equal(editorOutcome(FORMAT, 'pieces', [row(A, false, null, 500)]).refusal, 'invalid_pieces_placed');
    });

    test('no result yet: the choice alone (null when nothing chosen); did not start: no record', () => {
        assert.deepEqual(editorOutcome(FORMAT, 'none', [row(C), row('')]), { result: null, record: [{ roundPuzzleId: C, done: false }], error: null, refusal: null });
        assert.deepEqual(editorOutcome(FORMAT, 'none', [row(''), row('')]), { result: null, record: null, error: null, refusal: null });
        assert.deepEqual(editorOutcome(FORMAT, 'dns', [row(C), row(A)]), { result: { didNotStart: true }, record: null, error: null, refusal: null });
    });

    test('editor rows: K rows filled from the record', () => {
        assert.deepEqual(editorRows(FORMAT, null), [row(''), row('')]);
        assert.deepEqual(editorRows(FORMAT, [{ roundPuzzleId: B.toUpperCase(), done: true, atSeconds: 10 }]), [row(B, true, 10), row('')]);
    });

    test('chips and the one-line text: solving order, own time, pieces, hidden by label only', () => {
        const record = [{ roundPuzzleId: C, done: true, atSeconds: 2470 }, { roundPuzzleId: A, done: true, atSeconds: 4923 }];

        assert.deepEqual(recordChips(FORMAT, record).map((chip) => [chip.label, chip.name, chip.atSeconds, chip.duration]), [['C', null, 2470, 2470], ['Blue box', 'Lighthouse', 4923, 2453]]);
        assert.equal(recordText(FORMAT, record, { pieces: '%placed% pcs' }), 'C ✓ 0:41:10 · Blue box ✓ 1:22:03');
        assert.equal(recordText(FORMAT, [{ roundPuzzleId: B, done: false, piecesPlaced: 312 }], { pieces: '%placed% pcs' }), 'B 312 pcs');
        assert.equal(recordText(FORMAT, null, {}), '');
    });

    test('desk row chips (real events L1): own time only on K >= 2 and not the first, the whole text as the title', () => {
        const texts = { pieces: '%placed% pcs', own: 'own %time%' };
        const record = [{ roundPuzzleId: C, done: true, atSeconds: 602 }, { roundPuzzleId: A, done: true, atSeconds: 614 }];

        assert.deepEqual(deskRecordChips(FORMAT, record, texts).map((chip) => [chip.own, chip.title]), [
            [null, 'C ✓ 0:10:02'],
            [12, 'Blue box Lighthouse ✓ 0:10:14 (own 0:00:12)'],
        ]);
        assert.deepEqual(deskRecordChips({ ...FORMAT, toSolve: 1 }, record, texts).map((chip) => chip.own), [null, null]);
        assert.deepEqual(deskRecordChips(FORMAT, [{ roundPuzzleId: B, done: true, atSeconds: 600 }, { roundPuzzleId: A, done: false, piecesPlaced: 312 }], texts).map((chip) => [chip.own, chip.title]), [
            [null, 'B ✓ 0:10:00'],
            [null, 'Blue box Lighthouse 312 pcs'],
        ]);
        assert.deepEqual(deskRecordChips(FORMAT, null, texts), []);
    });

    test('the sheet\'s consistent record: kept choice, recomputed, cleared only when nothing fits', () => {
        const record = [{ roundPuzzleId: B, done: true, atSeconds: 2470 }, { roundPuzzleId: A, done: false, piecesPlaced: 312 }];

        assert.deepEqual(consistentRecord(FORMAT, { seconds: 4000 }, record), { changed: true, record: [{ roundPuzzleId: B, done: true, atSeconds: 2470 }, { roundPuzzleId: A, done: true }], cleared: false });
        assert.deepEqual(consistentRecord(FORMAT, { piecesPlaced: 812 }, record), { changed: false, record, cleared: false });
        assert.deepEqual(consistentRecord(FORMAT, null, record), { changed: false, record, cleared: false });
        assert.deepEqual(consistentRecord(FORMAT, { didNotStart: true }, record), { changed: true, record: null, cleared: true });
        // One puzzle chosen, a time typed: K = 2 are needed - cleared
        assert.deepEqual(consistentRecord(FORMAT, { seconds: 4000 }, [{ roundPuzzleId: C, done: false }]), { changed: true, record: null, cleared: true });
        assert.deepEqual(consistentRecord(null, { seconds: 4000 }, record), { changed: false, record, cleared: false });
        assert.deepEqual(consistentRecord(FORMAT, { seconds: 4000 }, null), { changed: false, record: null, cleared: false });
    });

    test('a group goes in one request: never split by the request limit', () => {
        const pending = new PendingChanges();
        const newId = ids();
        pending.set('team:y', 'qualified', true, false);
        grouped(pending);

        assert.deepEqual(pending.take(newId, 2).map((change) => change.field), ['qualified']);
        // A group bigger than the room left waits; alone in a request it goes whole
        assert.deepEqual(pending.take(newId, 1).map((change) => change.field), ['result', 'puzzles']);
    });

    test('a group waits while one of it is on its way, and goes whole again afterwards', () => {
        const pending = new PendingChanges();
        const newId = ids();
        grouped(pending);
        const sent = pending.take(newId);
        // The result changed again while both are on their way
        pending.set('team:x', 'result', { seconds: 4100 }, { piecesPlaced: 812 }, 'puzzles:team:x');
        assert.deepEqual(pending.take(newId), []);
        pending.settle({ clientChangeId: sent[0].clientChangeId, status: 'applied' });
        // The partner still on its way - nothing yet
        assert.deepEqual(pending.take(newId), []);
        pending.settle({ clientChangeId: sent[1].clientChangeId, status: 'applied' });
        assert.deepEqual(pending.take(newId).map((change) => [change.field, change.to]), [['result', { seconds: 4100 }]]);
    });

    test('conflict + group_refused: keep mine sends both again, discard drops both, try again retries both', () => {
        for (const [action, expected] of [['keepMine', ['result', 'puzzles']], ['discard', []], ['retry', null]]) {
            const pending = new PendingChanges();
            const newId = ids();
            grouped(pending);
            const sent = pending.take(newId);
            pending.settle({ clientChangeId: sent[0].clientChangeId, status: 'conflict', current: { seconds: 3900 } });
            pending.settle({ clientChangeId: sent[1].clientChangeId, status: 'rejected', reason: 'group_refused', message: 'refused together' });

            if (action === 'retry') {
                // Try again on the refused partner: both are refused/in conflict - the conflict waits for the organiser
                pending.retry('team:x', 'puzzles');
                assert.deepEqual(pending.take(newId), []);
                pending.keepMine('team:x', 'result');
                assert.deepEqual(pending.take(newId).map((change) => change.field), ['result', 'puzzles']);
                continue;
            }

            pending[action]('team:x', action === 'discard' ? 'puzzles' : 'result');

            if (action === 'discard') {
                assert.equal(pending.isEmpty(), true);
            }

            const taken = pending.take(newId);
            assert.deepEqual(taken.map((change) => change.field), expected);

            if (action === 'keepMine') {
                assert.deepEqual(taken[0].from, { seconds: 3900 });
            }
        }
    });

    test('a cell without a group is untouched by all of this', () => {
        const pending = new PendingChanges();
        const newId = ids();
        pending.set('a', 'result', { seconds: 1 }, null);
        pending.set('b', 'result', { seconds: 2 }, null);
        pending.set('c', 'result', { seconds: 3 }, null);
        assert.deepEqual(pending.take(newId, 2).map((change) => change.entry), ['a', 'b']);
        pending.discard('c', 'result');
        assert.equal(pending.list().length, 2);
    });

    test('?entry= deep link (v2 C5): a team or solo entry ref by its uuid, anything else ignored', () => {
        const uuid = '0195f3a2-7c1d-7e4a-9b2c-1d2e3f4a5b6c';

        assert.equal(deskEntryFromQuery(`?entry=team:${uuid}`), `team:${uuid}`);
        assert.equal(deskEntryFromQuery(`?x=1&entry=participant_round%3A${uuid.toUpperCase()}`), `participant_round:${uuid}`);

        for (const search of ['', '?', '?entry=', '?entry=team:t-flat', `?entry=person:${uuid}`, `?entry=team:${uuid}x`, `?entry=team:${uuid}%0A`, '?entry=<script>', `?other=team:${uuid}`]) {
            assert.equal(deskEntryFromQuery(search), null, search);
        }

        assert.equal(deskEntryFromQuery(null), null);
        assert.equal(deskEntryRef(42), null);
    });

    test('the sheet\'s link to the desk: ?entry= appended once, nothing for an unknown desk or a bad ref', () => {
        const uuid = '0195f3a2-7c1d-7e4a-9b2c-1d2e3f4a5b6c';

        assert.equal(deskEntryUrl('/en/manage-round-results/r1', `team:${uuid}`), `/en/manage-round-results/r1?entry=team%3A${uuid}`);
        assert.equal(deskEntryUrl('/en/manage-round-results/r1?a=b', `participant_round:${uuid}`), `/en/manage-round-results/r1?a=b&entry=participant_round%3A${uuid}`);
        // What it builds, the desk reads back
        assert.equal(deskEntryFromQuery(new URL(deskEntryUrl('/x', `team:${uuid}`), 'https://example.test').search), `team:${uuid}`);
        assert.equal(deskEntryUrl('', `team:${uuid}`), null);
        assert.equal(deskEntryUrl(null, `team:${uuid}`), null);
        assert.equal(deskEntryUrl('/x', 'team:t-flat'), null);
    });
}
