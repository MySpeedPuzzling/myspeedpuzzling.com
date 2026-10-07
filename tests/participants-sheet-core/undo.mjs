// Undo / redo (assets/participants_sheet/sheet_undo.js): inverse groups with fresh ids, only what went through is
// undone, a refused undo is reported, a new edit forks the history.
import assert from 'node:assert/strict';
import { SheetModel } from '../../assets/participants_sheet/sheet_model.js';
import { addPerson, resultsAction, setField, setInRound, wireChange } from '../../assets/participants_sheet/sheet_changes.js';
import { SheetUndo } from '../../assets/participants_sheet/sheet_undo.js';
import { ROUND_SOLO, ids, smallState } from './fixture.mjs';

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
}
