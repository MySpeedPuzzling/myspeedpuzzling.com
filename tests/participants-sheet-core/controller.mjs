// The page controller (assets/controllers/participants_sheet_controller.js) under Stimulus in jsdom: the setup
// checklist, undo/redo titles on a Mac, Ctrl+Z inside a dialog, the breakpoint, a view that failed to load, a refused
// checkbox marked on its cell, "needs you · offline", Keep mine as an undo step, one re-render per action, disconnect.
import assert from 'node:assert/strict';
import { setupDom, key, tick } from './dom.mjs';
import { smallState, ROUND_SOLO, ROUND_PAIRS } from './fixture.mjs';

// A few real texts (the rest answer their key): refusal reasons are the server's, with parameters
const TEXTS_CORE = {
    reason_has_result_in_round: "%name%'s result in %round% is recorded - clear it on the results desk first to take %name% out.",
    team_no_name: '(no name)',
};

function attribute(value) {
    return String(value).replace(/&/g, '&amp;').replace(/'/g, '&#39;').replace(/"/g, '&quot;');
}

async function mount({ state = smallState({ mercure: null }), platform = 'Linux x86_64' } = {}) {
    setupDom();
    Object.defineProperty(globalThis, 'navigator', { value: { platform, userAgent: 'test' }, configurable: true, writable: true });
    // Requests never answer: the page's own saves and fetches stay on their way (nothing leaks out of a test)
    globalThis.fetch = () => new Promise(() => {});
    const { Application } = await import('@hotwired/stimulus');
    const { default: Controller } = await import('../../assets/controllers/participants_sheet_controller.js');

    document.body.innerHTML = `<div class="participants-sheet" data-controller="participants-sheet"
        data-participants-sheet-urls-value='${attribute(JSON.stringify({ state: '/s', changes: '/c', version: '/v', playerSearch: '/p', record: '/r/__ROUND__', tables: '/t/__ROUND__' }))}'
        data-participants-sheet-csrf-token-value="x"
        data-participants-sheet-locale-value="en"
        data-participants-sheet-countries-value='${attribute(JSON.stringify({ us: 'United States', ca: 'Canada', cz: 'Czechia' }))}'
        data-participants-sheet-texts-core-value='${attribute(JSON.stringify(TEXTS_CORE))}'
        data-participants-sheet-texts-round-value="{}"
        data-participants-sheet-texts-people-value="{}"
        data-participants-sheet-tab-value="people">
        <div data-participants-sheet-target="status"></div>
        <button type="button" data-participants-sheet-target="undo" title="Undo (Ctrl+Z)" disabled></button>
        <button type="button" data-participants-sheet-target="redo" title="Redo (Ctrl+Y)" disabled></button>
        <button type="button" data-participants-sheet-target="help"></button>
        <nav data-participants-sheet-target="tabs"></nav>
        <div data-participants-sheet-target="checklist">Set up the event</div>
        <div data-participants-sheet-target="main"></div>
        <script type="application/json" id="participants-sheet-state">${JSON.stringify(state)}</script>
    </div>`;

    const application = Application.start(document.documentElement);
    application.register('participants-sheet', Controller);
    await tick(5);
    const element = document.querySelector('[data-controller="participants-sheet"]');
    const controller = application.getControllerForElementAndIdentifier(element, 'participants-sheet');
    assert.ok(controller, 'the controller connected');

    // Disconnected like Turbo leaving the page: the element goes (Stimulus disconnects it), then the application stops
    const stop = async () => {
        element.remove();
        await tick(3);
        application.stop();
    };

    return { application, controller, element, stop };
}

/** A test with a mounted page that is always disconnected afterwards (no timer of a failed test left running). */
function page(options, fn) {
    return async () => {
        const mounted = await mount(options);

        try {
            await fn(mounted);
        } finally {
            if (mounted.element.isConnected) {
                await mounted.stop();
            }
        }
    };
}

export default function (test) {
    test('the setup checklist goes once the event has a round and a person on its list - people change live', page({ state: smallState({ mercure: null, people: [], places: [] }) }, async ({ controller, element }) => {
        const checklist = element.querySelector('[data-participants-sheet-target="checklist"]');
        assert.equal(checklist.hidden, false);
        controller.model.applyLocal('g1', [{ op: 'newParticipant', id: 'p-new', name: 'New Person', country: null, externalId: null }]);
        assert.equal(checklist.hidden, true);
    }));

    test('the setup checklist is hidden at once when the event is set up', page({}, async ({ element }) => {
        assert.equal(element.querySelector('[data-participants-sheet-target="checklist"]').hidden, true);
    }));

    const someEdit = () => ({ label: { key: 'field' }, groups: [{ id: 'g1', changes: [{ op: 'field', participant: 'p-ana', field: 'name', from: 'Ana Example', to: 'Ana One' }] }], inverse: [], errors: [] });

    test('undo/redo titles say ⌘ on a Mac', page({ platform: 'MacIntel' }, async ({ controller, element }) => {
        controller.act(someEdit());
        assert.equal(element.querySelector('[data-participants-sheet-target="undo"]').getAttribute('title'), 'undo_label_mac');
    }));

    test('undo/redo titles say Ctrl elsewhere', page({}, async ({ controller, element }) => {
        controller.act(someEdit());
        assert.equal(element.querySelector('[data-participants-sheet-target="undo"]').getAttribute('title'), 'undo_label');
    }));

    test('Ctrl+Z inside a dialog (the help, a preview) never undoes the sheet behind it', page({}, async ({ controller, element }) => {
        let undone = 0;
        controller.undo = () => undone++;
        controller.showHelp();
        key(controller.helpDialog.querySelector('[data-help-close]'), 'z', { ctrlKey: true });
        assert.equal(undone, 0);
        controller.helpDialog.close();
        key(element.querySelector('[data-participants-sheet-target="status"]'), 'z', { ctrlKey: true });
        assert.equal(undone, 1, 'outside a dialog it does');
    }));

    test('a breakpoint switch that resolves to the same module keeps the view (and an open edit)', page({}, async ({ controller }) => {
        const view = controller.view;
        view.grid.focusCell('p-ana', 'name');
        key(view.grid.cellElement('p-ana', 'name'), 'Enter');
        view.grid.editor.value = 'Ana Typing';
        controller.media.matches = true;
        controller.onBreakpoint();
        await tick(5);
        assert.equal(controller.view, view, 'no phone people list yet - the same grid stays');
        assert.equal(view.grid.isEditing(), true);
        assert.equal(view.grid.editor.value, 'Ana Typing');
    }));

    test('a view that failed to load (offline) is not remembered as missing: "Try again" loads it', page({}, async ({ controller }) => {
        let attempts = 0;
        controller.importView = async () => {
            attempts++;

            if (attempts === 1) {
                throw Object.assign(new Error('Loading chunk 7 failed.'), { name: 'ChunkLoadError' });
            }

            return { default: () => ({ render() { controller.viewRoot.textContent = 'round view'; }, update() {}, destroy() {} }) };
        };
        const original = console.error;
        console.error = () => {};

        try {
            await controller.showTab(ROUND_PAIRS);
        } finally {
            console.error = original;
        }

        assert.match(controller.viewRoot.textContent, /view_load_failed/);
        assert.equal(controller.modules.has('team_round_view'), false);
        controller.viewRoot.querySelector('[data-sheet-view-retry]').click();
        await tick(5);
        assert.equal(controller.viewRoot.textContent, 'round view');
        assert.equal(attempts, 2);

        // A module that is not in the build at all falls back for good
        controller.importView = async () => {
            throw Object.assign(new Error("Cannot find module './solo_round_view.js'"), { code: 'MODULE_NOT_FOUND' });
        };
        await controller.showTab(ROUND_SOLO);
        assert.equal(controller.modules.get('solo_round_view'), null);
        assert.match(controller.viewRoot.textContent, /round_view_unavailable/);
    }));

    test('a checkbox click refused in the browser is marked on its cell in the server\'s words, the box ticked again', page({}, async ({ controller }) => {
        const cell = controller.view.grid.cellElement('p-kim', `round:${ROUND_SOLO}`);
        cell.querySelector('input.sheet-check').click();
        assert.equal(cell.querySelector('input.sheet-check').checked, true);
        const mark = controller.model.marks.get(`place:p-kim:${ROUND_SOLO}`);
        assert.equal(mark.state, 'refused');
        assert.equal(mark.message, "Kim Example's result in Solo is recorded - clear it on the results desk first to take Kim Example out.");
        assert.ok(controller.view.grid.cellElement('p-kim', `round:${ROUND_SOLO}`).classList.contains('has-marker-refused'));
        assert.equal(controller.liveRegion.textContent === '' || controller.liveRegion.textContent === mark.message, true);
    }));

    test('problems while offline: the pill says both, the offline banner shows', page({}, async ({ controller, element }) => {
        controller.renderStatus({ state: 'attention', waiting: 2, attention: 1, offline: true });
        assert.match(element.querySelector('[data-participants-sheet-target="status"]').textContent, /status_attention_offline/);
        assert.match(controller.banner.textContent, /banner_offline/);
        assert.equal(controller.banner.hidden, false);
        controller.renderStatus({ state: 'attention', waiting: 0, attention: 1, offline: false });
        assert.equal(controller.banner.hidden, true);
    }));

    test('Keep mine in the problems panel is an undo step; an action re-renders the view once', page({}, async ({ controller }) => {
        let updates = 0;
        const update = controller.view.update.bind(controller.view);
        controller.view.update = (delta) => {
            updates++;
            update(delta);
        };
        controller.act({ label: { key: 'round_in' }, groups: ['p-ana', 'p-jo', 'p-lee'].map((id, index) => ({ id: `b${index}`, changes: [{ op: 'place', participant: id, round: ROUND_SOLO, from: 'out', to: 'in' }] })), inverse: [], errors: [] });
        assert.equal(updates, 1, 'three groups, their markers: one update');

        const keep = { label: { key: 'field', field: 'name' }, groups: [{ id: 'k1', changes: [{ op: 'field', participant: 'p-jo', field: 'name', from: 'Jo Elsewhere', to: 'Jo Mine' }] }], inverse: [{ id: 'k1i', inverseOf: 'k1', changes: [{ op: 'field', participant: 'p-jo', field: 'name', from: 'Jo Mine', to: 'Jo Elsewhere' }] }], errors: [] };
        const problem = { id: 'g9:0', kind: 'sheet', status: 'conflict', message: 'Changed meanwhile.', current: 'Jo Elsewhere', change: keep.groups[0].changes[0], group: { id: 'g9', changes: [], origin: 'people' }, target: { key: 'person:p-jo:name', people: ['p-jo'] } };
        controller.queue.problems = () => [problem];
        controller.queue.problem = () => problem;
        controller.queue.keepMineAction = () => keep;
        controller.renderProblems();
        controller.problemsPanel.querySelector('[data-problem-action="keep"]').click();
        assert.equal(controller.model.person('p-jo').name, 'Jo Mine');
        assert.equal(controller.undoStack.peekUndo(), keep);
    }));

    test('disconnect: an open edit is saved first; dialogs, timers and editors are let go', page({}, async ({ controller, stop }) => {
        controller.showHelp();
        controller.helpDialog.close();
        const grid = controller.view.grid;
        grid.focusCell('p-ana', 'name');
        key(grid.cellElement('p-ana', 'name'), 'Enter');
        grid.editor.value = 'Ana Leaving';
        controller.announce('something');
        const model = controller.model;
        assert.equal(model.pending.length, 0);
        await stop();
        assert.equal(model.person('p-ana').name, 'Ana Leaving', 'committed like a blur');
        assert.equal(model.pending.length, 1);
        assert.equal(controller.helpDialog, null);
        assert.equal(controller.personEditor, null);
        assert.equal(controller.announceTimer, null);
        assert.equal(controller.view, null);
    }));
}
