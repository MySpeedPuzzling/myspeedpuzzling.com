// Undo / redo (assets/participants_sheet/sheet_undo.js): inverse groups with fresh ids, only what went through is
// undone, a refused undo is reported, a new edit forks the history.
import assert from 'node:assert/strict';
import { SheetModel } from '../../assets/participants_sheet/sheet_model.js';
import { addPerson, combine, deleteTeam, removePeople, resultsAction, setField, setInRound, wireChange } from '../../assets/participants_sheet/sheet_changes.js';
import { SheetUndo } from '../../assets/participants_sheet/sheet_undo.js';
import { ROUND_PAIRS, ROUND_SOLO, ids, smallState } from './fixture.mjs';

function setup() {
    const model = new SheetModel(smallState(), { now: () => 0 });
    const undo = new SheetUndo({ newId: ids('u') });
    const perform = (action) => {
        action.groups.forEach((group) => model.applyLocal(group.id, group.changes));

        return action;
    };

    return { model, undo, perform };
}

export default function (test) {
    test('undo sends the inverse as new groups; redo sends the step again; both are undoable steps', () => {
        const { model, undo, perform } = setup();
        const action = perform(setField(model, 'p-ana', 'name', 'Ana One', { newId: ids('g') }));
        undo.record(action);
        undo.outcome('g1', 'applied');
        assert.equal(undo.canUndo(), true);
        assert.equal(undo.canRedo(), false);

        const back = undo.undo(model);
        assert.equal(back.kind, 'undo');
        assert.equal(back.groups[0].id, 'u1');
        assert.deepEqual(back.groups[0].changes.map(wireChange), [{ op: 'field', participant: 'p-ana', field: 'name', from: 'Ana One', to: 'Ana Example' }]);
        perform(back);
        undo.done(back);
        assert.equal(model.person('p-ana').name, 'Ana Example');
        assert.equal(undo.canRedo(), true);

        const again = undo.redo(model);
        assert.equal(again.kind, 'redo');
        assert.deepEqual(again.groups[0].changes.map(wireChange), [{ op: 'field', participant: 'p-ana', field: 'name', from: 'Ana Example', to: 'Ana One' }]);
        perform(again);
        undo.done(again);
        assert.equal(model.person('p-ana').name, 'Ana One');
        assert.equal(undo.canUndo(), true);
    });

    test('a new person: undo removes them, redo restores them (never a second new person)', () => {
        const { model, undo, perform } = setup();
        const add = perform(addPerson(model, { name: 'Ny Person' }, { newId: ids('n') }));
        undo.record(add);
        const back = perform(undo.undo(model));
        undo.done(back);
        assert.deepEqual(back.groups[0].changes.map(wireChange), [{ op: 'remove', participant: add.personId }]);
        const again = undo.redo(model);
        assert.deepEqual(again.groups[0].changes.map(wireChange), [{ op: 'restore', participant: add.personId }]);
    });

    test('only what went through is undone; a step with nothing left is skipped', () => {
        const { model, undo, perform } = setup();
        const first = perform(setField(model, 'p-jo', 'country', 'cz'));
        undo.record(first);
        undo.outcome(first.groups[0].id, 'applied');
        const bulk = perform(setInRound(model, ['p-ana', 'p-jo', 'p-lee'], ROUND_SOLO, true));
        undo.record(bulk);
        undo.outcome(bulk.groups[0].id, 'applied');
        undo.outcome(bulk.groups[1].id, 'refused');
        undo.outcome(bulk.groups[2].id, 'unchanged');
        const back = undo.undo(model);
        assert.equal(back.groups.length, 1);
        assert.equal(back.groups[0].changes[0].participant, 'p-ana');

        const refused = perform(setField(model, 'p-lee', 'name', 'Lee Two'));
        undo.record(refused);
        undo.outcome(refused.groups[0].id, 'conflict');
        const skipped = undo.undo(model);
        assert.equal(skipped.groups[0].changes[0].participant, 'p-jo', 'the conflicting step had nothing to undo');
    });

    test('a group still on its way is undone too (the inverse follows it in the queue)', () => {
        const { model, undo, perform } = setup();
        const action = perform(setField(model, 'p-ana', 'name', 'Ana One'));
        undo.record(action);
        assert.equal(undo.undo(model).groups.length, 1);
    });

    test('an undo the server refused is reported as such', () => {
        const { model, undo, perform } = setup();
        const action = perform(setField(model, 'p-ana', 'name', 'Ana One'));
        undo.record(action);
        undo.outcome(action.groups[0].id, 'applied');
        const back = perform(undo.undo(model));
        undo.done(back);
        assert.equal(undo.outcome(back.groups[0].id, 'conflict'), 'undo');
        assert.equal(undo.outcome(action.groups[0].id, 'applied'), null);
        assert.equal(undo.outcome('unknown', 'conflict'), null);
    });

    test('a new edit clears the redo history; the stack is limited', () => {
        const { model, perform } = setup();
        const undo = new SheetUndo({ limit: 2, newId: ids('u') });
        for (const name of ['A1', 'A2', 'A3']) {
            undo.record(perform(setField(model, 'p-ana', 'name', name)));
        }
        assert.equal(undo.undoStack.length, 2);
        undo.done(perform(undo.undo(model)));
        assert.equal(undo.canRedo(), true);
        undo.record(perform(setField(model, 'p-jo', 'name', 'Jo Two')));
        assert.equal(undo.canRedo(), false);
        assert.equal(undo.peekUndo().label.key, 'field');
    });

    test('results changes are undone by the swapped changes', () => {
        const { model, undo } = setup();
        undo.record(resultsAction([{ roundId: ROUND_SOLO, ref: 'participant_round:e-pat-solo', field: 'result', from: null, to: { seconds: 61 } }]));
        const back = undo.undo(model);
        assert.deepEqual(back.results, [{ roundId: ROUND_SOLO, ref: 'participant_round:e-pat-solo', field: 'result', from: { seconds: 61 }, to: null }]);
        undo.done(back);
        assert.deepEqual(undo.redo(model).results[0].to, { seconds: 61 });
    });

    test('empty actions are not steps', () => {
        const { model, undo } = setup();
        undo.record(setField(model, 'p-ana', 'name', 'Ana Example'));
        assert.equal(undo.canUndo(), false);
        assert.equal(undo.undo(model), null);
    });

    test('M3: a step of combined results changes is undone as itself - never skipped for the step before it', () => {
        const { model, undo, perform } = setup();
        const rename = perform(setField(model, 'p-kim', 'name', 'Kim Renamed', { newId: ids('g') }));
        undo.record(rename);
        undo.outcome('g1', 'applied');
        const paste = combine({ key: 'paste' },
            resultsAction([{ roundId: ROUND_PAIRS, ref: 'team:t-corners', field: 'result', from: null, to: { seconds: 3000 } }]),
            resultsAction([{ roundId: ROUND_PAIRS, ref: 'team:t-corners2', field: 'result', from: null, to: { seconds: 3100 } }]));
        undo.record(paste);
        const back = undo.undo(model);
        assert.deepEqual(back.label, { key: 'paste' });
        assert.deepEqual(back.groups, []);
        assert.deepEqual(back.results.map((change) => [change.ref, change.from, change.to]), [
            ['team:t-corners2', { seconds: 3100 }, null],
            ['team:t-corners', { seconds: 3000 }, null],
        ]);
        undo.done(back);
        assert.equal(undo.peekUndo().label.field, 'name', 'the rename is the next step');
    });

    test('minor 6: undoing a step still on its way that then turns out not saved says so - not "somebody changed it"', () => {
        const { model, undo, perform } = setup();
        const action = perform(setField(model, 'p-ana', 'name', 'Ana One', { newId: ids('g') }));
        undo.record(action);
        const back = perform(undo.undo(model));
        undo.done(back);
        assert.equal(undo.outcome('g1', 'conflict'), null);
        assert.equal(undo.outcome(back.groups[0].id, 'conflict'), 'undo_unsaved');

        const other = setup();
        const saved = other.perform(setField(other.model, 'p-ana', 'name', 'Ana Two', { newId: ids('h') }));
        other.undo.record(saved);
        const undone = other.perform(other.undo.undo(other.model));
        other.undo.done(undone);
        other.undo.outcome('h1', 'applied');
        assert.equal(other.undo.outcome(undone.groups[0].id, 'conflict'), 'undo', 'saved, then changed by somebody: "Can\'t undo"');
    });

    test('minor 6: "Keep mine" is a step of its own - Ctrl+Z takes it back instead of skipping to an older step', () => {
        const { model, undo, perform } = setup();
        const older = perform(setField(model, 'p-jo', 'country', 'cz', { newId: ids('o') }));
        undo.record(older);
        undo.outcome('o1', 'applied');
        const mine = perform(setField(model, 'p-ana', 'name', 'Ana One', { newId: ids('m') }));
        undo.record(mine);
        undo.outcome('m1', 'conflict');
        // the controller performs queue.keepMineAction() like any action: shown, queued, recorded
        const keep = perform({ label: { key: 'field', field: 'name' }, groups: [{ id: 'k1', changes: [{ op: 'field', participant: 'p-ana', field: 'name', from: 'Ana Elsewhere', to: 'Ana One' }] }], inverse: [{ id: 'k1-inv', inverseOf: 'k1', changes: [{ op: 'field', participant: 'p-ana', field: 'name', from: 'Ana One', to: 'Ana Elsewhere' }] }], errors: [] });
        undo.record(keep);
        const back = undo.undo(model);
        assert.deepEqual(back.groups[0].changes.map(wireChange), [{ op: 'field', participant: 'p-ana', field: 'name', from: 'Ana One', to: 'Ana Elsewhere' }]);
    });

    test('minor 6: undoing a pair\'s deletion gives its table number back after creating it; members removed meanwhile are left out and named', () => {
        const { model, undo, perform } = setup();
        const remove = perform(deleteTeam(model, 't-corners', { newId: ids('d') }));
        undo.record(remove);
        undo.outcome('d1', 'applied');
        const back = undo.undo(model);
        assert.deepEqual(back.groups[0].changes.map(wireChange).map((change) => change.op), ['newTeam', 'place', 'place']);
        assert.deepEqual(back.results, [{ roundId: ROUND_PAIRS, ref: 'team:t-corners', field: 'table_number', from: null, to: 2, inverseOf: 'd1' }]);
        assert.deepEqual(back.skipped, []);

        // Pat removed from the event since the deletion: only Kim is put back, Pat is named
        const other = setup();
        const deletion = other.perform(deleteTeam(other.model, 't-corners', { newId: ids('e') }));
        other.undo.record(deletion);
        other.undo.outcome('e1', 'applied');
        const gone = other.perform(removePeople(other.model, ['p-pat'], { newId: ids('r') }));
        assert.equal(gone.groups.length, 1);
        const partial = other.undo.undo(other.model);
        assert.deepEqual(partial.groups[0].changes.map(wireChange), [
            { op: 'newTeam', id: 't-corners', round: ROUND_PAIRS, name: 'Corners' },
            { op: 'place', participant: 'p-kim', round: ROUND_PAIRS, from: 'in', to: 'team:t-corners' },
        ]);
        assert.deepEqual(partial.skipped.map((change) => change.participant), ['p-pat']);

        // The deletion never went through: nothing to undo - the table number is not "given back" either
        const third = setup();
        const refused = third.perform(deleteTeam(third.model, 't-corners', { newId: ids('f') }));
        third.undo.record(refused);
        third.undo.outcome('f1', 'refused');
        assert.equal(third.undo.undo(third.model), null);
    });
}
