// The keyboard model of the grid (assets/participants_sheet/grid_keys.js) - the stage 0b spike's tests (APG grid +
// Sheets/Excel conventions, IME, non-Latin layouts).
import assert from 'node:assert/strict';
import { nextAction, isPrintable, tabTarget } from '../../assets/participants_sheet/grid_keys.js';

export default function (test) {
    // round tab: # (table) | Team name | Member 1 | Member 2 | + | Size
    const columns = [{ kind: 'text' }, { kind: 'text' }, { kind: 'list' }, { kind: 'list' }, { kind: 'action' }, { kind: 'readonly' }];
    const base = { mode: 'nav', row: 5, col: 2, rowCount: 100, colCount: 6, columns, pageSize: 20 };
    const at = (row, col, extra = {}) => ({ ...base, row, col, ...extra });
    const editing = (row, col, editKind = 'type') => ({ ...base, row, col, mode: 'edit', editKind });
    const key = (k, extra = {}) => ({ key: k, code: '', shiftKey: false, ctrlKey: false, metaKey: false, altKey: false, isComposing: false, keyCode: 0, ...extra });
    const pick = (answer, ...fields) => Object.fromEntries(fields.map((field) => [field, answer[field]]));

    test('arrows move and clamp at the edges', () => {
        assert.deepEqual(pick(nextAction(at(5, 2), key('ArrowUp')), 'action', 'row', 'col'), { action: 'move', row: 4, col: 2 });
        assert.deepEqual(pick(nextAction(at(5, 2), key('ArrowDown')), 'row', 'col'), { row: 6, col: 2 });
        assert.deepEqual(pick(nextAction(at(5, 2), key('ArrowLeft')), 'row', 'col'), { row: 5, col: 1 });
        assert.deepEqual(pick(nextAction(at(5, 2), key('ArrowRight')), 'row', 'col'), { row: 5, col: 3 });
        assert.deepEqual(pick(nextAction(at(0, 0), key('ArrowUp')), 'row', 'col'), { row: 0, col: 0 });
        assert.deepEqual(pick(nextAction(at(0, 0), key('ArrowLeft')), 'row', 'col'), { row: 0, col: 0 });
        assert.deepEqual(pick(nextAction(at(99, 5), key('ArrowDown')), 'row', 'col'), { row: 99, col: 5 });
        assert.deepEqual(pick(nextAction(at(99, 5), key('ArrowRight')), 'row', 'col'), { row: 99, col: 5 });
        assert.equal(nextAction(at(5, 2), key('ArrowUp')).preventDefault, true);
    });

    test('Ctrl/Cmd+arrows jump to the edge', () => {
        assert.deepEqual(pick(nextAction(at(5, 2), key('ArrowDown', { ctrlKey: true })), 'row', 'col'), { row: 99, col: 2 });
        assert.deepEqual(pick(nextAction(at(5, 2), key('ArrowUp', { metaKey: true })), 'row', 'col'), { row: 0, col: 2 });
        assert.deepEqual(pick(nextAction(at(5, 2), key('ArrowRight', { metaKey: true })), 'row', 'col'), { row: 5, col: 5 });
    });

    test('Home/End, Ctrl+Home/End, PgUp/PgDn (page = 20)', () => {
        assert.deepEqual(pick(nextAction(at(5, 2), key('Home')), 'row', 'col'), { row: 5, col: 0 });
        assert.deepEqual(pick(nextAction(at(5, 2), key('End')), 'row', 'col'), { row: 5, col: 5 });
        assert.deepEqual(pick(nextAction(at(5, 2), key('Home', { ctrlKey: true })), 'row', 'col'), { row: 0, col: 0 });
        assert.deepEqual(pick(nextAction(at(5, 2), key('End', { metaKey: true })), 'row', 'col'), { row: 99, col: 5 });
        assert.deepEqual(pick(nextAction(at(5, 2), key('PageDown')), 'row', 'col'), { row: 25, col: 2 });
        assert.deepEqual(pick(nextAction(at(5, 2), key('PageUp')), 'row', 'col'), { row: 0, col: 2 });
        assert.deepEqual(pick(nextAction(at(90, 2), key('PageDown')), 'row', 'col'), { row: 99, col: 2 });
    });

    test('Shift + navigation extends the range', () => {
        assert.deepEqual(pick(nextAction(at(5, 2), key('ArrowDown', { shiftKey: true })), 'action', 'row', 'col'), { action: 'extend', row: 6, col: 2 });
        assert.deepEqual(pick(nextAction(at(5, 2), key('End', { shiftKey: true, ctrlKey: true })), 'action', 'row', 'col'), { action: 'extend', row: 99, col: 5 });
        assert.equal(nextAction(at(5, 2), key('PageDown', { shiftKey: true })).action, 'extend');
    });

    test('Tab / Shift+Tab move and wrap rows; Tab leaves only from the very last cell', () => {
        assert.deepEqual(pick(nextAction(at(5, 2), key('Tab')), 'action', 'row', 'col'), { action: 'move', row: 5, col: 3 });
        assert.deepEqual(pick(nextAction(at(5, 5), key('Tab')), 'action', 'row', 'col'), { action: 'move', row: 6, col: 0 });
        assert.deepEqual(pick(nextAction(at(5, 0), key('Tab', { shiftKey: true })), 'action', 'row', 'col'), { action: 'move', row: 4, col: 5 });
        assert.deepEqual(nextAction(at(99, 5), key('Tab')), { action: 'leave', direction: 'forward', preventDefault: false });
        assert.deepEqual(nextAction(at(0, 0), key('Tab', { shiftKey: true })), { action: 'leave', direction: 'backward', preventDefault: false });
    });

    test('Tab visits only tabColumns when given (team row wraps to the next row\'s first member)', () => {
        const state = at(5, 3, { tabColumns: [1, 2, 3] });
        assert.deepEqual(tabTarget(state, 1), { row: 6, col: 1 });
        assert.deepEqual(tabTarget(at(5, 0, { tabColumns: [1, 2, 3] }), 1), { row: 5, col: 1 });
        assert.deepEqual(tabTarget(at(5, 1, { tabColumns: [1, 2, 3] }), -1), { row: 4, col: 3 });
        assert.equal(tabTarget(at(99, 3, { tabColumns: [1, 2, 3] }), 1), null);
    });

    test('typing a printable character starts an edit replacing the content, the browser types it', () => {
        for (const k of ['a', 'Z', '7', 'é', 'ř', '@', '🧩', 'あ']) {
            assert.deepEqual(nextAction(at(5, 2), key(k)), { action: 'startEdit', replace: true, preventDefault: false }, k);
        }
    });

    test('AltGr (Ctrl+Alt on Windows) and Option (macOS) characters are printable', () => {
        assert.equal(isPrintable(key('@', { ctrlKey: true, altKey: true })), true);
        assert.equal(isPrintable(key('€', { altKey: true })), true);
        assert.equal(isPrintable(key('a', { ctrlKey: true })), false);
        assert.equal(isPrintable(key('a', { metaKey: true })), false);
        assert.equal(isPrintable(key('Shift')), false);
        assert.equal(isPrintable(key('F5')), false);
    });

    test('typing on a read-only cell is refused (announced), not an edit', () => {
        assert.equal(nextAction(at(5, 5), key('a')).action, 'readonly');
        assert.equal(nextAction(at(5, 5), key('Enter')).action, 'readonly');
        assert.equal(nextAction(at(5, 5), key('F2')).action, 'readonly');
    });

    test('Enter / F2 start an edit keeping the content', () => {
        assert.deepEqual(nextAction(at(5, 2), key('Enter')), { action: 'startEdit', replace: false, preventDefault: true });
        assert.deepEqual(nextAction(at(5, 2), key('F2')), { action: 'startEdit', replace: false, preventDefault: true });
    });

    test('Enter / Space on the + column activates it', () => {
        assert.equal(nextAction(at(5, 4), key('Enter')).action, 'activate');
        assert.equal(nextAction(at(5, 4), key(' ')).action, 'activate');
    });

    test('Space toggles a checkbox cell, opens the panel where configured, otherwise types a space', () => {
        const people = { ...base, colCount: 4, columns: [{ kind: 'text', space: 'panel' }, { kind: 'list' }, { kind: 'checkbox' }, { kind: 'checkbox' }] };
        assert.equal(nextAction({ ...people, col: 2 }, key(' ')).action, 'toggle');
        assert.equal(nextAction({ ...people, col: 2 }, key('Enter')).action, 'toggle');
        assert.equal(nextAction({ ...people, col: 0 }, key(' ')).action, 'openPanel');
        assert.deepEqual(nextAction({ ...people, col: 1 }, key(' ')), { action: 'startEdit', replace: true, preventDefault: false });
    });

    test('Shift+Space selects the row, Ctrl+Space the column, Ctrl+A everything', () => {
        assert.equal(nextAction(at(5, 2), key(' ', { shiftKey: true })).action, 'selectRow');
        assert.equal(nextAction(at(5, 2), key(' ', { ctrlKey: true })).action, 'selectColumn');
        assert.equal(nextAction(at(5, 2), key('a', { metaKey: true })).action, 'selectAll');
    });

    test('clipboard shortcuts are left to the browser (native copy/cut/paste events)', () => {
        assert.deepEqual(nextAction(at(5, 2), key('c', { ctrlKey: true })), { action: 'copy', preventDefault: false });
        assert.deepEqual(nextAction(at(5, 2), key('x', { metaKey: true })), { action: 'cut', preventDefault: false });
        assert.deepEqual(nextAction(at(5, 2), key('v', { ctrlKey: true })), { action: 'paste', preventDefault: false });
        assert.equal(nextAction(at(5, 2), key('V', { ctrlKey: true, shiftKey: true })).action, 'paste');
    });

    test('fill down, fill selection, clear, undo, redo', () => {
        assert.equal(nextAction(at(5, 2), key('d', { ctrlKey: true })).action, 'fillDown');
        assert.equal(nextAction(at(5, 2), key('Enter', { ctrlKey: true })).action, 'fillSelection');
        assert.equal(nextAction(at(5, 2), key('Delete')).action, 'clear');
        assert.equal(nextAction(at(5, 2), key('Backspace')).action, 'clear');
        assert.equal(nextAction(at(5, 2), key('z', { ctrlKey: true })).action, 'undo');
        assert.equal(nextAction(at(5, 2), key('Z', { ctrlKey: true, shiftKey: true })).action, 'redo');
        assert.equal(nextAction(at(5, 2), key('z', { metaKey: true, shiftKey: true })).action, 'redo');
        assert.equal(nextAction(at(5, 2), key('y', { ctrlKey: true })).action, 'redo');
    });

    test('shortcut letters on a non-Latin layout come from code; a Czech QWERTZ key wins over its position', () => {
        assert.equal(nextAction(at(5, 2), key('я', { code: 'KeyZ', ctrlKey: true })).action, 'undo');
        assert.equal(nextAction(at(5, 2), key('с', { code: 'KeyC', metaKey: true })).action, 'copy');
        // Czech QWERTZ: the key labelled Z sits where QWERTY has Y
        assert.equal(nextAction(at(5, 2), key('z', { code: 'KeyY', ctrlKey: true })).action, 'undo');
    });

    test('Alt+ArrowDown opens the list (navigation and edit)', () => {
        assert.equal(nextAction(at(5, 2), key('ArrowDown', { altKey: true })).action, 'openList');
        assert.equal(nextAction(editing(5, 2), key('ArrowDown', { altKey: true })).action, 'openList');
        assert.equal(nextAction(at(5, 5), key('ArrowDown', { altKey: true })).action, 'none');
    });

    test('Enter in edit commits and moves down, Shift+Enter up, clamped', () => {
        assert.deepEqual(nextAction(editing(5, 2), key('Enter')), { action: 'commit', move: { row: 6, col: 2 }, fill: false, preventDefault: true });
        assert.deepEqual(nextAction(editing(5, 2), key('Enter', { shiftKey: true })).move, { row: 4, col: 2 });
        assert.deepEqual(nextAction(editing(99, 2), key('Enter')).move, { row: 99, col: 2 });
        assert.deepEqual(nextAction(editing(0, 2), key('Enter', { shiftKey: true })).move, { row: 0, col: 2 });
    });

    test('Ctrl+Enter in edit commits into the whole selection', () => {
        assert.deepEqual(nextAction(editing(5, 2), key('Enter', { ctrlKey: true })), { action: 'commit', move: null, fill: true, preventDefault: true });
    });

    test('Tab in edit commits and moves right (wrapping), stays on the very last cell', () => {
        assert.deepEqual(nextAction(editing(5, 2), key('Tab')).move, { row: 5, col: 3 });
        assert.deepEqual(nextAction(editing(5, 5), key('Tab')).move, { row: 6, col: 0 });
        assert.deepEqual(nextAction(editing(5, 2), key('Tab', { shiftKey: true })).move, { row: 5, col: 1 });
        assert.deepEqual(nextAction(editing(99, 5), key('Tab')), { action: 'commit', move: null, fill: false, preventDefault: true });
    });

    test('Esc cancels the edit', () => {
        assert.deepEqual(nextAction(editing(5, 2), key('Escape')), { action: 'cancel', preventDefault: true });
    });

    test('arrows in an edit started by typing commit and move; after Enter/F2 they move the caret', () => {
        assert.deepEqual(nextAction(editing(5, 2, 'type'), key('ArrowLeft')).move, { row: 5, col: 1 });
        assert.equal(nextAction(editing(5, 2, 'type'), key('ArrowLeft', { shiftKey: true })).action, 'none');
        assert.equal(nextAction(editing(5, 2, 'keep'), key('ArrowLeft')).action, 'none');
        assert.equal(nextAction(editing(5, 2, 'keep'), key('ArrowDown')).action, 'none');
        assert.equal(nextAction(editing(5, 2, 'keep'), key('F2')).action, 'toggleEditKind');
    });

    test('other keys in edit are the input\'s own', () => {
        for (const k of ['a', 'Backspace', 'Delete', 'Home', 'End', ' ']) {
            assert.deepEqual(nextAction(editing(5, 2), key(k)), { action: 'none', preventDefault: false }, k);
        }
        assert.equal(nextAction(editing(5, 2), key('z', { ctrlKey: true })).action, 'none');
    });

    test('IME: no commit, cancel or move while composing (isComposing or keyCode 229)', () => {
        for (const k of ['Enter', 'Tab', 'Escape', 'ArrowDown', 'a']) {
            assert.deepEqual(nextAction(editing(5, 2), key(k, { isComposing: true })), { action: 'none', preventDefault: false }, k);
            assert.deepEqual(nextAction(editing(5, 2), key(k, { keyCode: 229 })), { action: 'none', preventDefault: false }, k);
        }
        // Safari ends the composition before the Enter keydown on some versions but still reports keyCode 229
        assert.equal(nextAction(editing(5, 2), key('Enter', { keyCode: 229, isComposing: false })).action, 'none');
        // IME key in navigation: start an edit, let the browser deliver the key to the editor
        assert.deepEqual(nextAction(at(5, 2), key('Process', { keyCode: 229 })), { action: 'startEdit', replace: true, preventDefault: false });
    });

    test('dead keys wait for the composed character', () => {
        assert.equal(nextAction(at(5, 2), key('Dead')).action, 'none');
    });

    test('modifier-only and unknown keys do nothing', () => {
        for (const k of ['Shift', 'Control', 'Meta', 'Alt', 'CapsLock', 'F5', 'Insert', 'ContextMenu']) {
            assert.deepEqual(nextAction(at(5, 2), key(k)), { action: 'none', preventDefault: false }, k);
        }
    });
}
