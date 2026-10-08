// The page controller (assets/controllers/participants_sheet_controller.js) under Stimulus in jsdom: the setup
// checklist (BR15), undo/redo titles on a Mac, Ctrl+Z inside a dialog, the breakpoint, a view that failed to load, a
// refused checkbox marked on its cell and shown as a toast (BR1), "nothing to undo" / an undo on another tab / a problem
// on another tab shown, the Help dialog's task help (BR7), "needs you · offline", Keep mine as an undo step, one
// re-render per action, disconnect.
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
    test('the setup checklist goes once the event has a round and a person on its list - people change live', page({ state: smallState({ mercure: null, people: [], places: [], teams: [] }) }, async ({ controller, element }) => {
        const checklist = element.querySelector('[data-participants-sheet-target="checklist"]');
        assert.equal(checklist.hidden, false);
        controller.model.applyLocal('g1', [{ op: 'newParticipant', id: 'p-new', name: 'New Person', country: null, externalId: null }]);
        assert.equal(checklist.hidden, true);
    }));

    test('an event of team names only (rounds and pairs/teams, nobody on the list) is set up - no checklist (BR15)', page({ state: smallState({ mercure: null, people: [], places: [] }) }, async ({ element }) => {
        assert.equal(element.querySelector('[data-participants-sheet-target="checklist"]').hidden, true);
    }));

    test('without a round the checklist stays, whoever is on the list', page({ state: smallState({ mercure: null, rounds: [], places: [], teams: [] }) }, async ({ element }) => {
        assert.equal(element.querySelector('[data-participants-sheet-target="checklist"]').hidden, false);
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

    test('Ctrl+Z with the focus left on the page body (a toast or a menu closed) still undoes; keys outside the sheet do not', page({}, async ({ controller, element }) => {
        let undone = 0;
        let redone = 0;
        controller.undo = () => undone++;
        controller.redo = () => redone++;
        key(element.ownerDocument.body, 'z', { ctrlKey: true });
        key(element.ownerDocument.body, 'z', { ctrlKey: true, shiftKey: true });
        assert.equal(undone, 1, 'Ctrl+Z on the body undoes');
        assert.equal(redone, 1, 'Ctrl+Shift+Z on the body redoes');
        const outside = element.ownerDocument.createElement('button');
        element.ownerDocument.body.append(outside);
        key(outside, 'z', { ctrlKey: true });
        assert.equal(undone, 1, 'a key aimed at something outside the sheet is not ours');
        outside.remove();
    }));

    test('a breakpoint switch that resolves to the same module keeps the view (and an open edit)', page({}, async ({ controller }) => {
        // As when the phone list is not in the build: the phone falls back to the same People grid
        controller.modules.set('people_list_view', null);
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

    test('a breakpoint switch to another module saves the open edit before the grid goes', page({}, async ({ controller }) => {
        const grid = controller.view.grid;
        grid.focusCell('p-ana', 'name');
        key(grid.cellElement('p-ana', 'name'), 'Enter');
        grid.editor.value = 'Ana Switching';
        controller.modules.set('people_list_view', () => ({ render() {}, update() {}, destroy() {}, focus() {} }));
        controller.media.matches = true;
        controller.onBreakpoint();
        await tick(5);
        assert.equal(grid.destroyed, true);
        assert.equal(controller.model.person('p-ana').name, 'Ana Switching');
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

    test('a checkbox click refused in the browser is marked on its cell in the server\'s words, the box ticked again', page({}, async ({ controller, element }) => {
        const cell = controller.view.grid.cellElement('p-kim', `round:${ROUND_SOLO}`);
        cell.querySelector('input.sheet-check').click();
        assert.equal(cell.querySelector('input.sheet-check').checked, true);
        const mark = controller.model.marks.get(`place:p-kim:${ROUND_SOLO}`);
        assert.equal(mark.state, 'refused');
        assert.equal(mark.message, "Kim Example's result in Solo is recorded - clear it on the results desk first to take Kim Example out.");
        assert.ok(controller.view.grid.cellElement('p-kim', `round:${ROUND_SOLO}`).classList.contains('has-marker-refused'));

        // Shown, not only read out: a toast pointing at the cell (BR1) - and said once
        assert.equal(controller.actingAnchor(), controller.view.grid.cellElement('p-kim', `round:${ROUND_SOLO}`), 'the cell acted on');
        assert.equal(controller.toasts.shown().length, 1);
        const [toast] = controller.toasts.shown();
        assert.deepEqual({ kind: toast.kind, text: toast.text }, { kind: 'error', text: mark.message });
        assert.ok(controller.toastRegion.querySelector('.sheet-toast-error'));
        assert.equal(controller.toastRegion.previousElementSibling, element.querySelector('[data-participants-sheet-target="status"]'), 'next to the save status');
        await new Promise((resolve) => setTimeout(resolve, 80));
        assert.equal(controller.liveRegion.textContent, mark.message);
    }));

    test('several refusals at once: the first reason and how many more; a quiet act shows nothing', page({}, async ({ controller }) => {
        const refused = (participant) => ({ reason: 'participant_removed', change: { op: 'place', participant, round: ROUND_SOLO, from: 'out', to: 'in' } });
        controller.act({ label: { key: 'round_in' }, groups: [], inverse: [], errors: [refused('p-ana'), refused('p-jo'), refused('p-lee')] });
        assert.match(controller.toasts.shown()[0].text, / notify_more_refused$/);
        controller.toasts.destroy();
        controller.act({ label: { key: 'round_in' }, groups: [], inverse: [], errors: [refused('p-ana')] }, { quiet: true });
        assert.deepEqual(controller.toasts.shown(), [], 'the caller shows it (an editor error)');
    }));

    test('Ctrl+Z with nothing to undo is shown; an undo of a step made on another tab says so with "Show" (BR1)', page({}, async ({ controller }) => {
        controller.undo();
        assert.deepEqual(controller.toasts.shown().map((toast) => [toast.kind, toast.text]), [['info', 'undo_nothing']]);

        // A step made on the Pairs tab, undone from People
        controller.act({ label: { key: 'rename_team' }, groups: [{ id: 'gp', changes: [{ op: 'renameTeam', team: 't-corners', from: 'Corners', to: 'Corner Kings' }] }], inverse: [{ id: 'gpi', inverseOf: 'gp', changes: [{ op: 'renameTeam', team: 't-corners', from: 'Corner Kings', to: 'Corners' }] }], errors: [] }, { origin: ROUND_PAIRS });
        assert.equal(controller.currentTab, 'people');
        let revealed = null;
        controller.showTab = async (tab, options = {}) => {
            revealed = { tab, key: options.reveal?.target?.key ?? null };
        };
        controller.undo();
        assert.equal(controller.model.team('t-corners').name, 'Corners');
        const [toast] = controller.toasts.shown();
        assert.equal(toast.text, 'undo_done_elsewhere');
        controller.toastRegion.querySelector('[data-toast-action="0"]').click();
        assert.deepEqual(revealed, { tab: ROUND_PAIRS, key: 'team:t-corners:name' }, '"Show" opens the tab at the cell');

        // On the tab it was made on: the change is on screen - read out only
        controller.toasts.destroy();
        controller.act({ label: { key: 'field' }, groups: [{ id: 'gn', changes: [{ op: 'field', participant: 'p-ana', field: 'name', from: 'Ana Example', to: 'Ana One' }] }], inverse: [{ id: 'gni', inverseOf: 'gn', changes: [{ op: 'field', participant: 'p-ana', field: 'name', from: 'Ana One', to: 'Ana Example' }] }], errors: [] });
        controller.undo();
        assert.deepEqual(controller.toasts.shown(), []);
    }));

    test('a refused undo is shown as an error, a problem of another tab gets a toast with "Show" - the current tab\'s stay markers', page({}, async ({ controller }) => {
        const problem = (id, origin, status = 'refused') => ({ id, kind: 'sheet', status, message: 'Refused by the server.', current: null, change: { op: 'renameTeam', team: 't-corners', from: 'Corners', to: 'X' }, group: { id: `${id}-g`, changes: [], origin }, target: { key: 'team:t-corners:name', teams: ['t-corners'] } });
        let problems = [problem('p1', 'people')];
        controller.queue.problems = () => problems;
        controller.queue.problem = (id) => problems.find((candidate) => candidate.id === id) ?? null;
        controller.onQueueEvent({ type: 'problems' });
        assert.deepEqual(controller.toasts.shown(), [], 'on screen: the marker and the problems panel say it');

        problems = [...problems, problem('p2', ROUND_PAIRS)];
        controller.onQueueEvent({ type: 'problems' });
        const [toast] = controller.toasts.shown();
        assert.equal(toast.kind, 'error');
        assert.match(toast.text, /^notify_problem_elsewhere/);
        controller.onQueueEvent({ type: 'problems' });
        assert.equal(controller.toasts.shown().length, 1, 'once per problem');

        let shown = null;
        controller.showTab = async (tab, options = {}) => {
            shown = { tab, problem: options.reveal?.id ?? null };
        };
        controller.toastRegion.querySelector('[data-toast-action="0"]').click();
        assert.deepEqual(shown, { tab: ROUND_PAIRS, problem: 'p2' });

        problems = [...problems, problem('p3', ROUND_PAIRS, 'conflict'), problem('p4', ROUND_SOLO, 'conflict')];
        controller.onQueueEvent({ type: 'problems' });
        assert.deepEqual([controller.toasts.shown()[0].kind, controller.toasts.shown()[0].text], ['warning', 'notify_problems_elsewhere']);

        // An undo the server refused: "Can't undo"
        controller.undoStack.reversals.set('u1', 'undo');
        controller.undoStack.statuses.set('u1', 'pending');
        controller.onQueueEvent({ type: 'outcome', kind: 'sheet', group: { id: 'u1', origin: 'people', changes: [] }, outcome: { status: 'conflict' } });
        assert.deepEqual([controller.toasts.shown()[0].kind, controller.toasts.shown()[0].text], ['error', 'undo_refused']);
    }));

    test('a server warning is shown at the name it is about and marked there', page({}, async ({ controller }) => {
        controller.showWarnings([{ participantId: 'p-ana', message: 'Probably the same person as Ana Exampel - check the list.' }]);
        assert.deepEqual(controller.toasts.shown().map((toast) => [toast.kind, toast.text]), [['warning', 'Probably the same person as Ana Exampel - check the list.']]);
        assert.equal(controller.model.marks.get('person:p-ana:name').state, 'warning');
        assert.equal(controller.anchorElement({ row: 'p-ana', col: 'name' }), controller.view.grid.cellElement('p-ana', 'name'), 'the toast points at the name');
    }));

    test('the person editor that could not be loaded says so with "Try again"', page({}, async ({ controller }) => {
        let attempts = 0;
        controller.importView = async () => {
            attempts++;

            throw Object.assign(new Error('Loading chunk 9 failed.'), { name: 'ChunkLoadError' });
        };
        const original = console.error;
        console.error = () => {};

        try {
            assert.equal(await controller.openPersonEditor('p-ana'), false);
            assert.deepEqual(controller.toasts.shown().map((toast) => [toast.kind, toast.text]), [['error', 'editor_load_failed']]);
            controller.toastRegion.querySelector('[data-toast-action="0"]').click();
            await tick(5);
            assert.equal(attempts, 2, 'tried again');
        } finally {
            console.error = original;
        }
    }));

    test('views get notify(); a toast in a modal dialog shows inside it', page({}, async ({ controller }) => {
        const context = controller.viewContext({ kind: 'people', round: null });
        assert.equal(typeof context.notify, 'function');
        context.notify('Kim can\'t be moved', { kind: 'warning' });
        assert.equal(controller.toasts.shown()[0].text, 'Kim can\'t be moved');
        assert.equal(context.notify(''), null, 'nothing to say, nothing shown');

        controller.showHelp();
        context.notify('Saved elsewhere');
        assert.ok(controller.helpDialog.querySelector('.sheet-toast'), 'inside the open help (the page behind it is inert)');
        controller.helpDialog.close();
    }));

    test('the Help dialog explains the tasks next to the shortcuts (BR7)', page({}, async ({ controller }) => {
        controller.showHelp();
        const text = controller.helpDialog.textContent;

        for (const task of ['saving', 'names', 'pairs', 'results', 'undo', 'leave']) {
            assert.match(text, new RegExp(`help_task_${task}_title`));
            assert.match(text, new RegExp(`help_task_${task}(?!_)`));
        }

        assert.match(text, /help_keys_title/);
        assert.equal(controller.helpDialog.querySelectorAll('tbody tr').length, 16);
        controller.helpDialog.close();
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
