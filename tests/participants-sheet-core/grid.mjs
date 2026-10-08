// The generic grid (assets/participants_sheet/sheet_grid.js) in jsdom: what an editor saw when it opened comes back as
// `seen`, the notice next to the editor, a rebuilt grid resuming an edit, Tab/blur never taking an action option,
// a refused checkbox snapping back, aria-selected on every cell.
import assert from 'node:assert/strict';
import { setupDom, key, TEXTS, tick } from './dom.mjs';

let SheetGrid;

async function setup({ columns = null, rows = ['a', 'b', 'c'], suggest = null, toggle = null, commit = null } = {}) {
    setupDom();
    ({ SheetGrid } = await import('../../assets/participants_sheet/sheet_grid.js'));
    const data = {
        a: { name: 'Ann', country: 'cz', act: '', pick: '', box: true },
        b: { name: 'Bo', country: 'us', act: '', pick: '', box: false },
        c: { name: 'Cy', country: null, act: '', pick: '', box: false },
    };
    const commits = [];
    const announced = [];
    const container = document.getElementById('root');
    const options = {
        container,
        label: 'Test grid',
        texts: TEXTS,
        columns: columns ?? [
            { key: 'name', label: 'Name', kind: 'text', width: 200 },
            { key: 'country', label: 'Country', kind: 'list', width: 150 },
            { key: 'act', label: 'Actions', kind: 'list', width: 150 },
            { key: 'pick', label: 'Pick', kind: 'list', width: 150, commitOnBlur: false },
            { key: 'box', label: 'Box', kind: 'checkbox', width: 80 },
        ],
        rows,
        cell: (row, col) => (col === 'box' ? { checked: data[row]?.box === true, label: `Box ${row}` } : { text: String(data[row]?.[col] ?? '') }),
        seenValue: (row, col) => data[row]?.[col],
        suggest: suggest ?? ((row, col) => (col === 'act'
            ? [{ value: 'open', label: 'Open', action: true }, { value: 'x', label: 'X' }]
            : [{ value: 'p1', label: 'Pick one' }, { value: 'p2', label: 'Pick two' }])),
        commit: commit ?? ((row, col, input, info) => {
            commits.push({ row, col, text: input.text, option: input.option?.value ?? null, fill: info.fill, seen: info.seen, cells: info.cells.length });

            return undefined;
        }),
        toggle: toggle ?? (() => {}),
        announce: (text) => announced.push(text),
    };
    const grid = new SheetGrid(options);

    return { grid, data, commits, announced, container, options };
}

const cellOf = (grid, row, col) => grid.cellElement(row, col);

export default function (test) {
    test('an editor hands back what its cell showed when it OPENED as `seen` - whatever the cell holds by the commit', async () => {
        const { grid, data, commits } = await setup();
        grid.focusCell('a', 'name');
        key(cellOf(grid, 'a', 'name'), 'Enter');
        assert.equal(grid.isEditing(), true);
        data.a.name = 'Annabel';            // a live change while the cell is being edited
        grid.editor.value = 'Ann Smith';
        key(grid.editor, 'Enter');
        assert.deepEqual(commits.at(-1), { row: 'a', col: 'name', text: 'Ann Smith', option: null, fill: false, seen: 'Ann', cells: 1 });

        // Typing into a cell opens the editor too - seen is what the cell showed then
        grid.focusCell('b', 'name');
        key(cellOf(grid, 'b', 'name'), 'k');
        assert.equal(grid.editState().seen, 'Bo');
        data.b.name = 'Bob';
        grid.editor.value = 'k';
        key(grid.editor, 'Tab');
        assert.equal(commits.at(-1).seen, 'Bo');

        // A blur commits with it as well
        const outside = document.createElement('button');
        document.body.append(outside);
        grid.focusCell('c', 'name');
        key(cellOf(grid, 'c', 'name'), 'F2');
        data.c.name = 'Cyril';
        outside.focus();
        assert.equal(grid.isEditing(), false);
        assert.equal(commits.at(-1).seen, 'Cy');

        // Ctrl+Enter from the editor fills the selection - the edited cell's seen comes along
        grid.focusCell('a', 'name');
        key(cellOf(grid, 'a', 'name'), 'ArrowDown', { shiftKey: true });
        key(cellOf(grid, 'b', 'name'), 'Enter');
        key(grid.editor, 'Enter', { ctrlKey: true });
        assert.equal(commits.at(-1).fill, true);
        assert.equal(commits.at(-1).cells, 2);
        assert.equal(commits.at(-1).seen, 'Bob');
        grid.destroy();
    });

    test('Tab, arrows and a blur never take an action option, nor any option of a commitOnBlur:false column; Enter and a click do', async () => {
        const { grid, commits } = await setup();
        const openList = (row, col) => {
            grid.focusCell(row, col);
            key(cellOf(grid, row, col), 'ArrowDown', { altKey: true });
            assert.equal(grid.list.open, true, 'the list is open');
        };

        openList('a', 'act');
        assert.equal(grid.list.active, 0, '"Open" is highlighted (autoHighlight)');
        key(grid.editor, 'Tab');
        assert.equal(commits.at(-1).option, null, 'Tab does not open the profile / unlink');

        openList('a', 'act');
        key(grid.editor, 'Enter');
        assert.equal(commits.at(-1).option, 'open', 'Enter does');

        openList('a', 'act');
        key(grid.editor, 'ArrowDown');
        key(grid.editor, 'Tab');
        assert.equal(commits.at(-1).option, 'x', 'an ordinary option of an ordinary column goes with Tab');

        openList('b', 'pick');
        key(grid.editor, 'Tab');
        assert.equal(commits.at(-1).option, null, 'a commitOnBlur:false column takes nothing by Tab');
        openList('b', 'pick');
        grid.commitEditWithoutFocus();
        assert.equal(commits.at(-1).option, null, '... nor by a blur');

        openList('b', 'pick');
        grid.listbox.children[1].dispatchEvent(new window.MouseEvent('click', { bubbles: true }));
        assert.equal(commits.at(-1).option, 'p2', 'a click picks');

        openList('b', 'pick');
        key(grid.editor, 'Enter');
        assert.equal(commits.at(-1).option, 'p1', 'Enter picks');
        grid.destroy();
    });

    test('a checkbox click the view refused snaps back - the box always shows the cell\'s state', async () => {
        const { grid, data } = await setup({ toggle: () => {} });
        const box = () => cellOf(grid, 'a', 'box').querySelector('input.sheet-check');
        assert.equal(box().checked, true);
        box().click();
        assert.equal(box().checked, true, 'refused: ticked again at once');

        // A re-render with unchanged markup still puts the state back
        box().checked = false;
        grid.updateRows(['a']);
        assert.equal(box().checked, data.a.box);
        grid.destroy();

        const toggled = await setup({ toggle: (cells, value) => cells.forEach(({ row }) => { toggled.data[row].box = value; }) });
        const other = cellOf(toggled.grid, 'b', 'box').querySelector('input.sheet-check');
        other.click();
        assert.equal(other.checked, true, 'accepted: stays ticked');
        toggled.grid.destroy();
    });

    test('a notice next to the open editor: polite, describes the editor, its buttons reached by Tab and run by a click without committing', async () => {
        const { grid, commits } = await setup();
        const ran = [];
        grid.editorNotice({ text: 'ignored', actions: [] });
        assert.equal(grid.notice.hidden, true, 'no editor, no notice');

        grid.focusCell('a', 'name');
        key(cellOf(grid, 'a', 'name'), 'Enter');
        grid.editor.value = 'Ann Two';
        grid.editorNotice({ text: 'Changed meanwhile to Annabel.', actions: [{ label: 'Keep mine', run: () => ran.push('keep') }, { label: 'Use theirs', run: () => ran.push('theirs') }] });
        assert.equal(grid.notice.hidden, false);
        assert.equal(grid.notice.getAttribute('aria-live'), 'polite');
        assert.match(grid.notice.textContent, /Changed meanwhile to Annabel\./);
        assert.ok(grid.editor.getAttribute('aria-describedby').split(' ').includes(grid.notice.id));

        const buttons = grid.notice.querySelectorAll('[data-notice-action]');
        const press = new window.MouseEvent('mousedown', { bubbles: true, cancelable: true });
        buttons[0].dispatchEvent(press);
        assert.equal(press.defaultPrevented, true, 'a mouse press keeps the focus in the editor (a blur would commit)');
        buttons[0].dispatchEvent(new window.MouseEvent('click', { bubbles: true }));
        assert.deepEqual(ran, ['keep']);

        key(grid.editor, 'Tab');
        assert.equal(document.activeElement, buttons[0], 'Tab goes to the first button');
        assert.equal(grid.isEditing(), true);
        assert.equal(commits.length, 0, 'nothing committed by going there');
        key(buttons[0], 'Tab');
        assert.equal(document.activeElement, buttons[1]);
        key(buttons[1], 'Tab');
        assert.equal(document.activeElement, grid.editor, 'back to the editor after the last button');
        key(grid.editor, 'Tab');
        key(buttons[0], 'Escape');
        assert.equal(document.activeElement, grid.editor, 'Esc goes back to the editor');
        await tick();
        assert.equal(grid.isEditing(), true);

        grid.commitWithSeen('Annabel');
        assert.deepEqual(commits.at(-1), { row: 'a', col: 'name', text: 'Ann Two', option: null, fill: false, seen: 'Annabel', cells: 1 }, 'Keep mine: from = the value now seen');
        assert.equal(grid.notice.hidden, true, 'gone with the editor');
        assert.equal(grid.editor.hasAttribute('aria-describedby'), false);

        grid.focusCell('b', 'name');
        key(cellOf(grid, 'b', 'name'), 'Enter');
        grid.editorNotice({ text: 'x', actions: [] });
        grid.editorNotice(null);
        assert.equal(grid.notice.hidden, true);
        grid.destroy();
    });

    test('a rebuilt grid resumes the open edit: the typed text, the caret and what the cell showed when it first opened', async () => {
        const first = await setup();
        first.grid.focusCell('b', 'name');
        key(cellOf(first.grid, 'b', 'name'), 'Enter');
        first.data.b.name = 'Bobby';
        first.grid.editor.value = 'Bo Typed';
        const state = first.grid.editState();
        assert.deepEqual({ row: state.row, col: state.col, text: state.text, seen: state.seen }, { row: 'b', col: 'name', text: 'Bo Typed', seen: 'Bo' });
        first.grid.destroy({ keepEdit: true });
        assert.equal(first.commits.length, 0, 'keepEdit: nothing committed');

        const second = new SheetGrid(first.options);
        assert.equal(second.resumeEdit(state), true);
        assert.equal(second.isEditing(), true);
        assert.equal(second.editor.value, 'Bo Typed');
        assert.equal(document.activeElement, second.editor);
        key(second.editor, 'Enter');
        assert.deepEqual(first.commits.at(-1), { row: 'b', col: 'name', text: 'Bo Typed', option: null, fill: false, seen: 'Bo', cells: 1 });
        assert.equal(second.resumeEdit({ ...state, row: 'gone' }), false, 'a row that is not there any more');
        second.destroy();
    });

    test('destroying a grid commits an open edit like a blur (a refused one is read out)', async () => {
        const { grid, commits } = await setup();
        grid.focusCell('a', 'name');
        key(cellOf(grid, 'a', 'name'), 'Enter');
        grid.editor.value = 'Ann Leaving';
        grid.destroy();
        assert.equal(commits.at(-1).text, 'Ann Leaving');
        assert.equal(grid.destroyed, true);

        const refusing = await setup({ commit: () => ({ error: 'Not like this' }) });
        refusing.grid.focusCell('a', 'name');
        key(cellOf(refusing.grid, 'a', 'name'), 'Enter');
        refusing.grid.destroy();
        assert.deepEqual(refusing.announced, ['Not like this']);
    });

    test('APG: every cell of a grid with selection says aria-selected, true only on the selection', async () => {
        const { grid, container } = await setup();
        const cells = [...container.querySelectorAll('tbody td, tbody th')];
        assert.ok(cells.length > 10);
        assert.ok(cells.every((cell) => cell.getAttribute('aria-selected') === 'false'));
        grid.focusCell('a', 'name');
        key(cellOf(grid, 'a', 'name'), 'ArrowDown', { shiftKey: true });
        assert.equal(cellOf(grid, 'a', 'name').getAttribute('aria-selected'), 'true');
        assert.equal(cellOf(grid, 'b', 'name').getAttribute('aria-selected'), 'true');
        key(cellOf(grid, 'b', 'name'), 'Escape');
        assert.equal(cellOf(grid, 'a', 'name').getAttribute('aria-selected'), 'false');
        grid.setRows(['a', 'b', 'c', 'd']);
        assert.equal(container.querySelector('tr[data-row="d"] td').getAttribute('aria-selected'), 'false');
        grid.destroy();
    });
}
