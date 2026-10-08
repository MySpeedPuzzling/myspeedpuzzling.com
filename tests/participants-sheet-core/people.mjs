// The People grid (assets/participants_sheet/views/people_view.js) on the real grid and model in jsdom: an edit sends
// what its editor showed when it opened (review B1), the "Changed meanwhile" notice, the profile column's Tab rule
// (M1), a refused checkbox (M2), an edit surviving a rebuilt grid (minor 1), hidden profiles from the search (O9).
import assert from 'node:assert/strict';
import { setupDom, key, TEXTS, tick } from './dom.mjs';
import { smallState, ROUND_SOLO } from './fixture.mjs';

async function setup({ state = smallState(), fetchAnswer = null } = {}) {
    setupDom();
    const { SheetModel } = await import('../../assets/participants_sheet/sheet_model.js');
    const { SheetGrid } = await import('../../assets/participants_sheet/sheet_grid.js');
    const { PeopleView } = await import('../../assets/participants_sheet/views/people_view.js');
    const model = new SheetModel(state, { now: () => 0 });
    const acted = [];
    const announced = [];
    const root = document.getElementById('root');

    if (fetchAnswer !== null) {
        globalThis.fetch = async () => ({ ok: true, json: async () => fetchAnswer });
    }

    const view = new PeopleView({
        root,
        model,
        texts: { core: TEXTS, people: TEXTS, round: TEXTS },
        queue: { preview: async () => ({ kind: 'offline' }), problems: () => [] },
        errorText: (error) => error.reason,
        countries: { us: 'United States', ca: 'Canada', cz: 'Czechia' },
        countryCodes: new Set(['us', 'ca', 'cz']),
        locale: 'en',
        urls: { playerSearch: '/en/participants-sheet-api/c1/players' },
        act: (action, options = {}) => {
            acted.push(action);

            if (action.errors.length > 0 && !options.quiet) {
                announced.push(action.errors[0].reason);
            }

            model.applyLocalMany(action.groups);

            return { performed: action.groups.length > 0, errors: action.errors };
        },
        announce: (text) => announced.push(text),
        reasonText: (code) => code,
        markerFor: () => null,
        openPersonEditor: () => {},
        switchTab: () => {},
        createGrid: (options) => new SheetGrid({ texts: TEXTS, announce: (text) => announced.push(text), ...options }),
    });
    model.subscribe((delta) => view.update(delta));
    view.render();

    const changes = () => acted.flatMap((action) => action.groups.flatMap((group) => group.changes.map((change) => {
        const { _player, ...wire } = change;

        return wire;
    })));

    return { model, view, acted, announced, changes, cell: (row, col) => view.grid.cellElement(row, col) };
}

/** Another organiser's save arrived (a fetched state): Kim renamed and moved to Canada. */
function theirs(model) {
    const state = smallState({ version: 'v2' });
    state.people = state.people.map((person) => (person.id === 'p-kim' ? { ...person, name: 'Kimberly Example', country: 'ca' } : person));
    model.replaceState(state);
}

export default function (test) {
    test('B1: an editor open while another organiser\'s change arrives sends what it showed when it opened as `from`', async () => {
        const { model, view, changes, cell } = await setup();

        // Enter on Kim's name, the live change arrives, Enter again without typing: nothing is sent (theirs stays)
        view.grid.focusCell('p-kim', 'name');
        key(cell('p-kim', 'name'), 'Enter');
        theirs(model);
        key(view.grid.editor, 'Enter');
        assert.deepEqual(changes(), [], 'the value the editor opened with is no change - never {from: theirs, to: the old name}');
        assert.equal(model.person('p-kim').name, 'Kimberly Example');

        // Typed something: it goes as a change of what the organiser saw - the server answers with a conflict
        const fresh = await setup();
        fresh.view.grid.focusCell('p-kim', 'name');
        key(fresh.cell('p-kim', 'name'), 'Enter');
        theirs(fresh.model);
        fresh.view.grid.editor.value = 'Kim Example-Smith';
        key(fresh.view.grid.editor, 'Enter');
        assert.deepEqual(fresh.changes(), [{ op: 'field', participant: 'p-kim', field: 'name', from: 'Kim Example', to: 'Kim Example-Smith' }]);

        // The country list the same way (a click on an option)
        const country = await setup();
        country.view.grid.focusCell('p-kim', 'country');
        key(country.cell('p-kim', 'country'), 'ArrowDown', { altKey: true });
        theirs(country.model);
        country.view.grid.commitEdit(null, false, { value: 'cz', label: 'Czechia' }, 'click');
        assert.deepEqual(country.changes(), [{ op: 'field', participant: 'p-kim', field: 'country', from: 'us', to: 'cz' }]);
        country.view.destroy();
        fresh.view.destroy();
        view.destroy();
    });

    test('B1: "Changed meanwhile to X" next to the editor - Keep mine sends over their value knowingly, Use theirs closes it', async () => {
        const { model, view, changes, cell } = await setup();
        view.grid.focusCell('p-kim', 'name');
        key(cell('p-kim', 'name'), 'Enter');
        view.grid.editor.value = 'Kim Example-Smith';
        assert.equal(view.grid.notice.hidden, true);
        theirs(model);
        assert.equal(view.grid.notice.hidden, false);
        assert.match(view.grid.notice.textContent, /editor_changed_meanwhile \{"value":"Kimberly Example"\}/);
        const [keep] = view.grid.notice.querySelectorAll('[data-notice-action]');
        keep.dispatchEvent(new window.MouseEvent('click', { bubbles: true }));
        assert.deepEqual(changes(), [{ op: 'field', participant: 'p-kim', field: 'name', from: 'Kimberly Example', to: 'Kim Example-Smith' }]);
        assert.equal(view.grid.isEditing(), false);

        const other = await setup();
        other.view.grid.focusCell('p-kim', 'country');
        key(other.cell('p-kim', 'country'), 'F2');
        theirs(other.model);
        assert.match(other.view.grid.notice.textContent, /Canada/);
        const [, useTheirs] = other.view.grid.notice.querySelectorAll('[data-notice-action]');
        useTheirs.dispatchEvent(new window.MouseEvent('click', { bubbles: true }));
        assert.equal(other.view.grid.isEditing(), false);
        assert.deepEqual(other.changes(), []);
        assert.match(other.cell('p-kim', 'country').textContent, /Canada/, 'the cell shows theirs');
        other.view.destroy();
        view.destroy();
    });

    test('M1: Tab or a blur on the profile column never unlinks or links - only Enter or a click picks', async () => {
        const hidden = smallState();
        hidden.people = hidden.people.map((person) => (person.id === 'p-kim'
            ? { ...person, player: { id: 'pl-kim', visible: false, name: null, code: null, country: null, avatar: null, profileUrl: null } }
            : person));
        const { model, view, changes, cell } = await setup({ state: hidden });

        view.grid.focusCell('p-kim', 'player');
        key(cell('p-kim', 'player'), 'Enter');
        await new Promise((resolve) => setTimeout(resolve, 200));
        const options = [...view.grid.listbox.children];
        assert.deepEqual(options.map((li) => li.textContent), ['people_profile_unlink']);
        assert.ok(options.every((li) => li.getAttribute('aria-selected') === 'false'), 'nothing highlighted (autoHighlight: false)');
        key(view.grid.editor, 'Tab');
        assert.deepEqual(changes(), [], 'Tab moved on, Kim stays linked');
        assert.equal(model.person('p-kim').player?.id, 'pl-kim');

        view.grid.focusCell('p-kim', 'player');
        key(cell('p-kim', 'player'), 'Enter');
        await new Promise((resolve) => setTimeout(resolve, 200));
        key(view.grid.editor, 'ArrowDown');
        key(view.grid.editor, 'Tab');
        assert.deepEqual(changes(), [], 'even with "Unlink" highlighted, Tab does not unlink');

        view.grid.focusCell('p-kim', 'player');
        key(cell('p-kim', 'player'), 'Enter');
        await new Promise((resolve) => setTimeout(resolve, 200));
        key(view.grid.editor, 'ArrowDown');
        key(view.grid.editor, 'Enter');
        assert.deepEqual(changes(), [{ op: 'player', participant: 'p-kim', from: 'pl-kim', to: null }], 'Enter does');
        view.destroy();
    });

    test('M1: a search hit is never linked by Tab; a profile hidden from the organiser links as "Linked to a profile" (O9)', async () => {
        const { model, view, changes, cell } = await setup({ fetchAnswer: [{ key: 'pl-ana', label: 'Ana Blocked', code: 'ana1', country: 'cz', hidden: true }] });
        view.grid.focusCell('p-ana', 'player');
        key(cell('p-ana', 'player'), 'Enter');
        view.grid.editor.value = 'Ana';
        view.grid.editor.dispatchEvent(new window.Event('input', { bubbles: true }));
        await new Promise((resolve) => setTimeout(resolve, 250));
        assert.equal(view.grid.listbox.children.length, 1);
        key(view.grid.editor, 'ArrowDown');
        key(view.grid.editor, 'Tab');
        assert.deepEqual(changes(), [], 'Tab never links a search hit');
        assert.equal(view.grid.isEditing(), true, 'the editor stays with "pick a profile"');
        assert.match(view.grid.editorError.textContent, /people_profile_pick/);

        key(view.grid.editor, 'Escape');
        key(view.grid.editor, 'Escape');
        view.grid.focusCell('p-ana', 'player');
        key(cell('p-ana', 'player'), 'Enter');
        view.grid.editor.value = 'Ana';
        view.grid.editor.dispatchEvent(new window.Event('input', { bubbles: true }));
        await new Promise((resolve) => setTimeout(resolve, 250));
        key(view.grid.editor, 'ArrowDown');
        key(view.grid.editor, 'Enter');
        assert.deepEqual(changes(), [{ op: 'player', participant: 'p-ana', from: null, to: 'pl-ana' }]);
        assert.equal(model.person('p-ana').player.visible, false, 'hidden from this organiser: no name, no code');
        assert.equal(model.person('p-ana').player.name, null);
        assert.equal(cell('p-ana', 'player').textContent.trim(), 'people_profile_hidden');
        view.destroy();
    });

    test('M2: a checkbox click the sheet refused snaps back and says why', async () => {
        const { model, view, announced, cell } = await setup();
        const box = () => cell('p-kim', `round:${ROUND_SOLO}`).querySelector('input.sheet-check');
        assert.equal(box().checked, true);
        box().click();
        assert.equal(model.placeValue('p-kim', ROUND_SOLO), 'in');
        assert.equal(box().checked, true, 'Kim holds a result in Solo - the tick is back');
        assert.deepEqual(announced, ['has_result_in_round']);
        view.grid.updateRows(['p-kim']);
        box().click();
        assert.equal(box().checked, true, 'and again');
        view.destroy();
    });

    test('minor 1: a rebuilt grid (a live update renamed a round) keeps the typed text and the focus', async () => {
        const { model, view, changes, cell } = await setup();
        view.grid.focusCell('p-kim', 'name');
        key(cell('p-kim', 'name'), 'Enter');
        view.grid.editor.value = 'Kim Example-Smith';
        const grid = view.grid;
        const state = smallState({ version: 'v2' });
        state.rounds = state.rounds.map((round) => (round.id === ROUND_SOLO ? { ...round, name: 'Solo A' } : round));
        model.replaceState(state);
        assert.notEqual(view.grid, grid, 'the grid was rebuilt (new columns)');
        assert.equal(view.grid.isEditing(), true);
        assert.equal(view.grid.editor.value, 'Kim Example-Smith');
        assert.equal(document.activeElement, view.grid.editor);
        assert.deepEqual(changes(), [], 'nothing sent by the rebuild');
        key(view.grid.editor, 'Enter');
        assert.deepEqual(changes(), [{ op: 'field', participant: 'p-kim', field: 'name', from: 'Kim Example', to: 'Kim Example-Smith' }]);
        await tick();
        view.destroy();
    });

    test('minor 1: leaving the view (a tab switch, the page going) commits an open edit', async () => {
        const { view, changes, cell } = await setup();
        view.grid.focusCell('p-ana', 'name');
        key(cell('p-ana', 'name'), 'Enter');
        view.grid.editor.value = 'Ana Leaving';
        view.destroy();
        assert.deepEqual(changes(), [{ op: 'field', participant: 'p-ana', field: 'name', from: 'Ana Example', to: 'Ana Leaving' }]);
    });
}
