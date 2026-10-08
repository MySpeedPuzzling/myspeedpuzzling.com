// The People tab's views in jsdom on the real model, grid and preview dialog (assets/participants_sheet/views/
// people_view.js, person_editor.js, people_list_view.js): review E's reproductions (M1 a paste on the selection column,
// M2 the editor's round switch, m1 the event changing under the view, m3, the NITs) and the business review's People
// items (BR3 "and put them into", BR4 filters + sorting, BR9 doubtful names, BR12 renames previewed, BR13 bulk
// registration, BR17 `?filter=`, E-3, every refusal shown through `context.notify`).
import assert from 'node:assert/strict';
import { setupDom, tick } from '../participants-sheet-core/dom.mjs';
import { person, place, smallState } from '../participants-sheet-core/fixture.mjs';

const BASE_URL = 'https://example.test/en/participants-sheet/c1';

/** Texts answering their key and their parameters (plural ones: key, count, parameters). */
const TEXTS = {
    t: (key, params = {}) => (Object.keys(params).length > 0 ? `${key} ${JSON.stringify(params)}` : key),
    tc: (key, count, params = {}) => `${key} ${count}${Object.keys(params).length > 0 ? ` ${JSON.stringify(params)}` : ''}`,
    has: () => true,
};

const applied = (groups) => ({
    kind: 'ok',
    data: { groups: groups.map((group) => ({ id: group.id, status: 'applied', changes: group.changes.map((change, index) => ({ index, status: 'applied' })), warnings: [] })) },
});

/**
 * A People tab on a page: `confirm` = every acted group is saved at once (the model's base moves on - like a quick
 * server), else they stay pending. `dryRun(groups)` = the server's dry run answer.
 */
async function setup({ state = smallState(), url = BASE_URL, dryRun = applied, confirm = false, phone = false } = {}) {
    setupDom({ url });
    const proto = window.HTMLDialogElement.prototype;

    if (typeof proto.show !== 'function') {
        proto.show = function () {
            this.setAttribute('open', '');
        };
    }

    const { SheetModel } = await import('../../assets/participants_sheet/sheet_model.js');
    const { SheetGrid } = await import('../../assets/participants_sheet/sheet_grid.js');
    const { PreviewDialog } = await import('../../assets/participants_sheet/preview_dialog.js');
    const { PeopleView } = await import('../../assets/participants_sheet/views/people_view.js');
    const { PeopleListView } = await import('../../assets/participants_sheet/views/people_list_view.js');
    const { PersonEditor } = await import('../../assets/participants_sheet/views/person_editor.js');
    const model = new SheetModel(state, { now: () => 0 });
    const acted = [];
    const announced = [];
    const shown = [];
    const previews = [];
    const listeners = new Set();
    const queue = {
        preview: async (groups) => {
            previews.push(groups);

            return dryRun(groups);
        },
        problems: () => [],
        flushNow: () => {},
        subscribe: (listener) => {
            listeners.add(listener);

            return () => listeners.delete(listener);
        },
        emit: (event) => [...listeners].forEach((listener) => listener(event)),
    };
    const root = document.getElementById('root');
    const context = {
        root,
        model,
        queue,
        texts: { core: TEXTS, people: TEXTS, round: TEXTS },
        errorText: (error) => error.reason,
        countries: { us: 'United States', ca: 'Canada', cz: 'Czechia' },
        countryCodes: new Set(['us', 'ca', 'cz']),
        locale: 'en',
        urls: { playerSearch: '/players', registration: '/registration' },
        csrfToken: 'tok',
        act: (action) => {
            acted.push(action);
            model.applyLocalMany(action.groups);

            if (confirm) {
                model.confirmMany(action.groups.map((group) => ({ groupId: group.id, deletedTeams: [] })));
            }

            return { performed: action.groups.length > 0, errors: action.errors };
        },
        announce: (text) => announced.push(text),
        notify: (text, options = {}) => shown.push({ text, ...options }),
        reasonText: (code) => code,
        markerFor: () => null,
        openPersonEditor: () => true,
        switchTab: () => {},
        createGrid: (options) => new SheetGrid({ texts: TEXTS, announce: (text) => announced.push(text), ...options }),
        preview: (options) => new PreviewDialog({ host: document.body, texts: TEXTS, ...options }).open(),
    };
    const View = phone ? PeopleListView : PeopleView;
    const view = new View(context);
    model.subscribe((delta) => view.update(delta));
    view.render();
    const editor = () => {
        const instance = new PersonEditor(context);
        model.subscribe((delta) => instance.update(delta));

        return instance;
    };
    const changes = (from = 0) => acted.slice(from).flatMap((action) => action.groups.flatMap((group) => group.changes.map((change) => {
        const { _player, ...wire } = change;

        return wire;
    })));

    return { model, view, context, acted, announced, shown, previews, queue, editor, changes };
}

const openDialog = (selector = 'dialog.sheet-preview') => [...document.querySelectorAll(selector)].find((dialog) => dialog.hasAttribute('open')) ?? null;
const submit = (dialog) => dialog.querySelector('form').dispatchEvent(new window.Event('submit', { cancelable: true }));
const fire = (element, type) => element.dispatchEvent(new window.Event(type, { bubbles: true }));
const lineOf = (dialog, text) => [...dialog.querySelectorAll('.sheet-preview-line')].find((line) => line.textContent.includes(text)) ?? null;

function managed(state, capacity = 20) {
    state.competition = { ...state.competition, registrationManaged: true, capacity };
    state.people = state.people.map((row) => ({ ...row, registration: row.registration ?? { status: 'reserved', registeredAt: null, paidAt: null, checkedInAt: null } }));

    return state;
}

/** fetch() answering the registration endpoint (one at a time, recording how many were on their way at once). */
function registrationServer(model, answer = () => ({ ok: true })) {
    const server = { calls: [], inFlight: 0, maxInFlight: 0 };
    globalThis.fetch = async (url, init) => {
        const body = JSON.parse(init.body);
        server.calls.push(body);
        server.inFlight++;
        server.maxInFlight = Math.max(server.maxInFlight, server.inFlight);
        await new Promise((resolve) => setTimeout(resolve, 2));
        server.inFlight--;
        const data = answer(body);
        const row = model.person(body.participant);
        const status = { markPaid: 'paid', promote: 'reserved', checkIn: row.registration.status }[body.action] ?? row.registration.status;

        return {
            type: 'basic',
            status: data.status ?? 200,
            ok: (data.status ?? 200) < 300,
            headers: { get: (name) => (name === 'Content-Type' ? 'application/json' : null) },
            json: async () => (data.ok === false ? data.body : { ok: true, version: 'v9', person: { ...row, registration: { ...row.registration, status, checkedInAt: body.action === 'checkIn' ? '2026-10-08T09:00:00+00:00' : row.registration.checkedInAt } } }),
        };
    };

    return server;
}

export default function (test) {
    test('M1: a block pasted on the selection column lands on the names - renamed only through the preview, nothing on the selection', async () => {
        const { view, acted, changes } = await setup();
        view.paste({ row: 'p-ana', col: 'select' }, [['Zed Newperson', 'cz'], ['Yan Other', 'us']], [{ row: 'p-ana', col: 'select' }]);
        await tick();
        assert.equal(acted.length, 0, 'renames are never applied without the preview (BR12)');
        const dialog = openDialog();
        assert.ok(dialog, 'the preview is open');
        assert.ok(lineOf(dialog, 'people_change_name'), 'the rename is listed');

        submit(dialog);
        await tick();
        assert.deepEqual(changes().map((change) => [change.participant, change.field, change.to]), [
            ['p-ana', 'name', 'Zed Newperson'],
            ['p-jo', 'name', 'Yan Other'],
            ['p-ana', 'country', 'cz'],
            ['p-jo', 'country', 'us'],
        ]);
        assert.equal(acted.length, 1, 'one undo step');
    });

    test('M1: one value pasted over a selection that includes the selection column - that column is listed as left out', async () => {
        const { view, acted } = await setup();
        view.paste({ row: 'p-ana', col: 'country' }, [['cz']], [{ row: 'p-ana', col: 'select' }, { row: 'p-ana', col: 'country' }, { row: 'p-jo', col: 'select' }, { row: 'p-jo', col: 'country' }]);
        await tick();
        const dialog = openDialog();
        assert.ok(dialog, 'something left out = previewed');
        assert.ok(lineOf(dialog, 'paste_select_skipped 2'));
        submit(dialog);
        await tick();
        assert.deepEqual(acted[0].groups.map((group) => [group.changes[0].participant, group.changes[0].field]), [['p-ana', 'country'], ['p-jo', 'country']]);
    });

    test('BR12: a rename of one row is previewed; a country of one row is applied at once', async () => {
        const { view, acted } = await setup();
        view.paste({ row: 'p-kim', col: 'name' }, [['Kimberly Example']], [{ row: 'p-kim', col: 'name' }]);
        await tick();
        assert.equal(acted.length, 0);
        const dialog = openDialog();
        assert.ok(dialog);
        dialog.querySelector('[data-preview-cancel]').click();
        await tick();
        assert.equal(acted.length, 0, 'cancelled - nothing renamed');

        view.paste({ row: 'p-kim', col: 'country' }, [['cz']], [{ row: 'p-kim', col: 'country' }]);
        assert.equal(acted.length, 1, 'no rename, a few rows: applied at once');
        assert.equal(openDialog(), null);
    });

    test('BR3 + BR9: names pasted on the new row - doubtful ones unticked, "And put them into ▾ Solo" places everybody, one Confirm, one undo step', async () => {
        const { view, acted, previews, changes, announced } = await setup();
        view.paste({ row: '__new', col: 'name' }, [['Robin Sampler', 'cz'], ['Kim Exampel'], ['Ana Example'], ['Pat Sample'], ['robin@example.com']], [{ row: '__new', col: 'name' }]);
        await tick();
        const dialog = openDialog();
        assert.ok(dialog);
        assert.ok(dialog.textContent.includes('paste_names_intro'));

        // BR9: "Did you mean Kim Example?" and an e-mail address - not ticked
        const kim = lineOf(dialog, 'Kim Exampel');
        assert.ok(kim.textContent.includes('paste_close {"name":"Kim Example"}'), kim.textContent);
        assert.equal(kim.querySelector('[data-tick]').checked, false);
        assert.equal(lineOf(dialog, 'robin@example.com').querySelector('[data-tick]').checked, false);
        assert.equal(lineOf(dialog, 'Robin Sampler').querySelector('[data-tick]').checked, true);
        assert.ok(dialog.textContent.includes('paste_count_unsure 2'));

        // BR3: only solo rounds are offered; choosing one checks again with the server
        const select = dialog.querySelector('[data-paste-round]');
        assert.deepEqual([...select.options].map((option) => option.value), ['', 'r-solo']);
        select.value = 'r-solo';
        fire(select, 'change');
        await tick();
        assert.equal(previews.length, 2, 'the dry run again, with the round');
        assert.ok(lineOf(dialog, 'Ana Example').textContent.includes('paste_existing_into'), 'Ana is on the list - she goes into Solo');
        assert.ok(lineOf(dialog, 'Pat Sample').textContent.includes('paste_existing_already'), 'Pat is in Solo already');
        assert.ok(lineOf(dialog, 'Robin Sampler').textContent.includes('paste_line_into'));
        assert.ok(dialog.querySelector('[data-paste-round]') === select, 'the select is not drawn again (the focus stays)');

        submit(dialog);
        await tick();
        assert.equal(acted.length, 1, 'one Confirm, one action = one undo step');
        const sent = changes();
        assert.equal(sent.length, 3, 'Kim Exampel and the e-mail address stayed unticked');
        assert.deepEqual([sent[0].op, sent[0].name, sent[0].country], ['newParticipant', 'Robin Sampler', 'cz']);
        assert.deepEqual(sent[1], { op: 'place', participant: sent[0].id, round: 'r-solo', from: 'out', to: 'in' });
        assert.deepEqual(sent[2], { op: 'place', participant: 'p-ana', round: 'r-solo', from: 'out', to: 'in' });
        assert.equal(acted[0].groups.length, 2, 'the new person and their place in one group, Ana in her own');
        assert.ok(announced.at(-1).includes('paste_round_done 2'), announced.at(-1));
    });

    test('M2: the editor\'s round switch sends the place it SHOWS - a tap without a focus after another organiser\'s change', async () => {
        const { model, editor, acted, changes } = await setup({ confirm: true });
        const panel = editor();
        panel.open('p-ana', {});
        let control = panel.dialog.querySelector('[data-round-switch="r-solo"]');
        control.checked = true;
        fire(control, 'change');
        assert.equal(model.placeValue('p-ana', 'r-solo'), 'in');

        // The organiser moves on; another organiser takes Ana out of Solo (a fetched state)
        panel.dialog.querySelector('[data-field="note"]').focus();
        model.replaceState(smallState({ version: 'v2' }));
        control = panel.dialog.querySelector('[data-round-switch="r-solo"]');
        assert.equal(control.checked, false);
        assert.equal(control.dataset.shown, 'out');

        // A tap (no focusin on Safari/iOS) puts her in again - from what the switch showed
        const before = acted.length;
        control.checked = true;
        fire(control, 'change');
        assert.deepEqual(changes(before), [{ op: 'place', participant: 'p-ana', round: 'r-solo', from: 'out', to: 'in' }]);
        assert.equal(model.placeValue('p-ana', 'r-solo'), 'in');
        panel.destroy();
    });

    test('M2: a focused pair picker that a live change could not redraw sends what it shows (the server answers a conflict)', async () => {
        const { model, editor, changes } = await setup();
        const panel = editor();
        panel.open('p-kim', {});
        const picker = panel.dialog.querySelector('[data-round-team="r-pairs"]');
        picker.focus();
        assert.equal(picker.dataset.shown, 'team:t-corners');

        const moved = smallState({ version: 'v2' });
        moved.places = moved.places.map((row) => (row.id === 'e-kim-pairs' ? { ...row, teamId: 't-corners2' } : row));
        model.replaceState(moved);
        assert.ok(picker.isConnected, 'not pulled away while used');
        assert.equal(picker.dataset.shown, 'team:t-corners');

        picker.value = 'in';
        fire(picker, 'change');
        assert.deepEqual(changes(), [{ op: 'place', participant: 'p-kim', round: 'r-pairs', from: 'team:t-corners', to: 'in' }]);

        // The section is drawn again from its source string - the next update with nothing new keeps the same elements
        const section = panel.dialog.querySelector('[data-section="rounds"]');
        const control = section.querySelector('[data-round-switch="r-solo"]');
        panel.update();
        assert.equal(section.querySelector('[data-round-switch="r-solo"]'), control);
        panel.destroy();
    });

    test('NIT: "Make a pair" refused by the dry run cannot be confirmed - also after a try', async () => {
        const refused = (groups) => ({ kind: 'ok', data: { groups: groups.map((group) => ({ id: group.id, status: 'refused', changes: [{ index: 0, status: 'refused', message: 'Corners holds a result.' }], warnings: [] })) } });
        const { view, acted } = await setup({ dryRun: refused });
        const done = view.makeTeam(['p-kim', 'p-lee'], 'r-pairs');
        await tick();
        const dialog = openDialog();
        const confirmButton = dialog.querySelector('[data-preview-confirm]');
        assert.equal(confirmButton.disabled, true);
        assert.ok(dialog.textContent.includes('Corners holds a result.'));

        submit(dialog);
        await tick();
        assert.ok(dialog.isConnected, 'still open');
        assert.ok(dialog.querySelector('[data-preview-message]').textContent.includes('make_team_blocked'));
        assert.equal(confirmButton.isConnected ? confirmButton.disabled : dialog.querySelector('[data-preview-confirm]').disabled, true, 'still disabled after drawing the message');

        dialog.querySelector('[data-preview-cancel]').click();
        await done;
        assert.equal(acted.length, 0);
    });

    test('NIT: the large-removal check counts who really goes (refused people left out), says it, takes full-width digits', async () => {
        const state = smallState();
        state.people.push(person('p-x1', 'Xia One'), person('p-x2', 'Xia Two'), person('p-x3', 'Xia Three'));
        const { view, acted, shown } = await setup({ state });
        // Kim and the Flat team's members hold a recorded result - refused; ten others go
        const ids = ['p-kim', 'p-t4', 'p-ana', 'p-jo', 'p-lee', 'p-max', 'p-pat', 'p-t1', 'p-t2', 'p-t3', 'p-x1', 'p-x2'];
        const done = view.removeWithCheck(ids);
        await tick();
        const dialog = openDialog('dialog.sheet-confirm');
        assert.ok(dialog, 'more than a quarter (and 10+): type the number');
        assert.ok(dialog.querySelector('label').textContent.includes('remove_many_prompt {"count":10}'), 'Kim (a recorded result) is not counted');
        const described = document.getElementById(dialog.getAttribute('aria-describedby'));
        assert.ok(described?.textContent.includes('remove_many_text'), 'the dialog is described by its text');

        const input = dialog.querySelector('[data-confirm-input]');
        input.value = '１０';
        fire(input, 'input');
        assert.equal(dialog.querySelector('[data-confirm-ok]').disabled, false, 'full-width digits are the same number');
        submit(dialog);
        await done;
        assert.equal(acted.at(-1).groups.length, 10);
        assert.ok(shown.some((entry) => entry.text.startsWith('bulk_refused 2') && entry.kind === 'error'), 'the refusals are shown');
    });

    test('m1: the view follows the event - a capacity changed by another organiser, registration management switched on', async () => {
        const state = managed(smallState(), 12);
        const { view, model, queue } = await setup({ state });
        assert.ok(view.registrationElement.textContent.includes('"capacity":12'));

        const raised = managed(smallState({ version: 'v2' }), 20);
        model.replaceState(raised);
        queue.emit({ type: 'state', state: raised, kind: 'ok' });
        await tick();
        assert.ok(view.registrationElement.textContent.includes('"capacity":20'), view.registrationElement.textContent);

        // The other way round: an event without management gets it
        const plain = await setup();
        assert.ok(!plain.view.columns.some((column) => column.key === 'registration'));
        assert.ok(plain.view.registrationElement.hidden);
        const switched = managed(smallState({ version: 'v2' }));
        plain.model.replaceState(switched);
        plain.queue.emit({ type: 'state', state: switched, kind: 'ok' });
        await tick();
        assert.ok(plain.view.columns.some((column) => column.key === 'registration'), 'the Registration column (on by default)');
        assert.ok(!plain.view.registrationElement.hidden);
        assert.ok(plain.view.filtersElement.querySelector('[data-filter="waitlist"]'));
        assert.ok(plain.view.columnsMenu.querySelector('[data-column="registered"]'));
    });

    test('m3 + m2: a cancelled paid registration says "paid on …, before the registration was cancelled"; the Paid filter', async () => {
        const state = managed(smallState());
        state.people = state.people.map((row) => {
            if (row.id === 'p-ola') {
                return { ...row, registration: { status: 'paid', registeredAt: null, paidAt: '2026-09-01T09:00:00+00:00', checkedInAt: null } };
            }

            return row.id === 'p-jo' ? { ...row, registration: { status: 'paid', registeredAt: null, paidAt: '2026-09-02T09:00:00+00:00', checkedInAt: null } } : row;
        });
        const { view, editor } = await setup({ state });
        view.setFilter('removed');
        const cell = view.grid.cellElement('p-ola', 'registration');
        assert.ok(cell.textContent.includes('paid_before'), cell.textContent);
        assert.ok(!cell.textContent.includes('status_paid'));

        const panel = editor();
        panel.open('p-ola', {});
        const section = panel.dialog.querySelector('[data-section="registration"]');
        assert.ok(section.textContent.includes('paid_before'));
        assert.ok(!section.querySelector('.sheet-reg'), 'no status chip on a cancelled registration');
        panel.destroy();

        view.setFilter('paid');
        assert.deepEqual(view.grid.rows.filter((row) => row !== '__new'), ['p-jo']);
    });

    test('BR4: a header click sorts (A→Z, again Z→A), aria-sort, remembered for the event; Enter on it is not the grid\'s', async () => {
        const { view } = await setup();
        const button = () => view.gridRoot.querySelector('[data-sort="name"]');
        const th = () => button().closest('th');
        assert.equal(th().hasAttribute('aria-sort'), false);

        button().click();
        assert.equal(th().getAttribute('aria-sort'), 'ascending');
        button().click();
        assert.equal(th().getAttribute('aria-sort'), 'descending');
        assert.equal(view.grid.rows[0], 'p-t7', 'Xan Seven first');
        assert.deepEqual(JSON.parse(window.localStorage.getItem('participants-sheet:people-sort:c1')), { key: 'name', dir: 'desc' });

        view.gridRoot.querySelector('[data-sort="country"]').click();
        assert.deepEqual(view.grid.rows.slice(0, 2), ['p-pat', 'p-kim'], 'Canada, United States, then nobody\'s country');
        assert.equal(th().hasAttribute('aria-sort'), false, 'one sorted column at a time');

        const event = new window.KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true });
        button().dispatchEvent(event);
        assert.equal(view.grid.isEditing(), false, 'the grid never opens an editor for the header button');
    });

    test('BR4: a row the organiser works on never jumps away when its sorted place changes; it goes there once they leave', async () => {
        const { view, model, context } = await setup();
        view.gridRoot.querySelector('[data-sort="name"]').click();
        const { setField } = await import('../../assets/participants_sheet/sheet_changes.js');
        view.grid.focusCell('p-kim', 'name');
        const index = view.grid.rows.indexOf('p-kim');
        context.act(setField(model, 'p-kim', 'name', 'Aaron Example'));
        assert.equal(view.grid.rows.indexOf('p-kim'), index, 'stays where it was while focused');

        view.searchInput.focus();
        context.act(setField(model, 'p-jo', 'note', 'later'));
        assert.equal(view.grid.rows[0], 'p-kim', 'sorted once the organiser left the grid');
    });

    test('BR4: "In no solo round", the round select, stored sort by a column the event lacks is no sort', async () => {
        const { view } = await setup();
        const noSolo = view.filtersElement.querySelector('[data-filter="no_solo"]');
        assert.ok(noSolo, 'offered: a solo round and team rounds');
        noSolo.click();
        assert.ok(!view.grid.rows.includes('p-kim') && view.grid.rows.includes('p-jo'));

        view.setFilter('all');
        view.roundSelect.value = 'out:r-pairs';
        fire(view.roundSelect, 'change');
        assert.ok(!view.grid.rows.includes('p-jo'), 'Jo is in Pairs (without a pair)');
        assert.ok(view.grid.rows.includes('p-ana'));
        assert.ok(view.filtersElement.textContent.length > 0);
        assert.deepEqual([...view.roundSelect.options].map((option) => option.value).slice(0, 3), ['', 'in:r-solo', 'out:r-solo']);

        view.sort = { key: 'registered', dir: 'asc' };
        assert.equal(view.sortActive(), false, 'no registration on this event');
    });

    test('BR13: Mark paid for the selected people - the confirmation says how many e-mails go out, sent one by one, summed up once', async () => {
        const state = managed(smallState());
        state.people = state.people.map((row) => {
            if (row.id === 'p-jo') {
                return { ...row, registration: { ...row.registration, status: 'paid', paidAt: '2026-09-02T09:00:00+00:00' } };
            }

            return row.id === 'p-ana' ? { ...row, player: { id: 'pl-ana', visible: true, name: 'Ana', code: 'ana1' } } : row;
        });
        const { view, model, shown } = await setup({ state });
        const server = registrationServer(model, (body) => (body.participant === 'p-pat' ? { ok: false, status: 409, body: { error: 'participant_removed', message: 'Removed meanwhile.' } } : { ok: true }));
        view.setSelected(['p-ana', 'p-jo', 'p-kim', 'p-pat'], true);
        view.bulkElement.querySelector('[data-bulk="markPaid"]').click();
        await tick();
        const dialog = openDialog('dialog.sheet-run');
        assert.ok(dialog);
        // Ana (linked) and Kim (linked) and Pat get marked; Jo is paid already
        assert.ok(dialog.querySelector('h2').textContent.includes('bulk_reg_title_markPaid 3'));
        assert.ok(dialog.textContent.includes('bulk_reg_emails 2'), dialog.textContent);
        assert.ok(dialog.textContent.includes('bulk_reg_skipped 1'));
        assert.equal(server.calls.length, 0, 'nothing sent before the confirmation');

        submit(dialog);
        for (let i = 0; i < 20 && dialog.isConnected; i++) {
            await new Promise((resolve) => setTimeout(resolve, 5));
        }

        assert.equal(dialog.isConnected, false);
        assert.deepEqual(server.calls.map((call) => [call.participant, call.action]), [['p-ana', 'markPaid'], ['p-kim', 'markPaid'], ['p-pat', 'markPaid']]);
        assert.equal(server.maxInFlight, 1, 'never two at once');
        assert.equal(model.person('p-ana').registration.status, 'paid');
        const summary = shown.at(-1);
        assert.ok(summary.text.includes('bulk_reg_done_markPaid 2') && summary.text.includes('bulk_reg_failed 1'), summary.text);
        assert.equal(summary.kind, 'error');
    });

    test('BR13: nobody the action applies to - said, nothing asked; a check-in sends no e-mail', async () => {
        const state = managed(smallState());
        const { view, shown } = await setup({ state });
        state.people.forEach(() => {});
        view.setSelected(['p-ana'], true);
        view.bulkElement.querySelector('[data-bulk="checkIn"]').click();
        await tick();
        const dialog = openDialog('dialog.sheet-run');
        assert.ok(dialog.textContent.includes('bulk_reg_no_emails'));
        dialog.querySelector('[data-run-cancel]').click();
        await tick();
        assert.equal(openDialog('dialog.sheet-run'), null);

        const result = await view.bulkRegistration(['p-ola'], 'markPaid');
        assert.equal(result, null);
        assert.equal(shown.at(-1).text, 'bulk_reg_none_markPaid');
    });

    test('BR17: ?filter=waitlist opens the tab with the filter on - read once, taken out of the URL', async () => {
        const state = managed(smallState());
        state.people = state.people.map((row) => (row.id === 'p-kim' ? { ...row, registration: { ...row.registration, status: 'waitlisted', waitlistPosition: 1 } } : row));
        const { view } = await setup({ state, url: `${BASE_URL}?tab=people&filter=waitlist` });
        assert.equal(view.filter, 'waitlist');
        assert.equal(view.filtersElement.querySelector('[data-filter="waitlist"]').getAttribute('aria-pressed'), 'true');
        assert.deepEqual(view.grid.rows, ['p-kim', '__new']);
        assert.equal(new URL(window.location.href).searchParams.get('filter'), null);
        assert.equal(new URL(window.location.href).searchParams.get('tab'), 'people');
        assert.ok(view.grid.cellElement('p-kim', 'registration').textContent.includes('status_waitlisted_position {"position":1}'));

        const unknown = await setup({ url: `${BASE_URL}?filter=nonsense` });
        assert.equal(unknown.view.filter, 'all');
        const round = await setup({ url: `${BASE_URL}?filter=in:r-pairs` });
        assert.equal(round.view.roundFilter, 'in:r-pairs');

        const phone = await setup({ state: managed(smallState()), url: `${BASE_URL}?tab=people&filter=not_paid`, phone: true });
        assert.equal(phone.view.filter, 'not_paid');
        assert.equal(phone.view.filterSelect.value, 'not_paid');
        assert.ok(!phone.view.roundSelect.hidden);
    });

    test('E-3 + NIT: "Give a spot" is readable, says an e-mail goes out and keeps the focus when the hint moves on', async () => {
        const state = managed(smallState(), 20);
        state.people = state.people.map((row) => {
            const waiting = { 'p-kim': 1, 'p-lee': 2 };

            return row.id in waiting ? { ...row, registration: { ...row.registration, status: 'waitlisted', waitlistPosition: waiting[row.id] } } : row;
        });
        const { view, model } = await setup({ state });
        registrationServer(model);
        await tick();
        const button = view.registrationElement.querySelector('[data-promote]');
        assert.equal(button.dataset.promote, 'p-kim');
        assert.ok(button.classList.contains('sheet-btn-success') && !button.classList.contains('btn-success'));
        assert.ok(document.getElementById(button.getAttribute('aria-describedby')).textContent.includes('first_in_line_email'));

        button.focus();
        button.click();
        for (let i = 0; i < 20 && model.person('p-kim').registration.status === 'waitlisted'; i++) {
            await new Promise((resolve) => setTimeout(resolve, 5));
        }

        await tick();
        const next = view.registrationElement.querySelector('[data-promote]');
        assert.equal(next?.dataset.promote, 'p-lee', 'Lee is first in line now');
        assert.equal(document.activeElement, next, 'the focus stays on the hint\'s button');
    });

    test('refusals and "nothing happened" are shown (context.notify), with the cell they are about', async () => {
        const { view, shown } = await setup();
        view.clear([{ row: 'p-kim', col: 'name' }]);
        assert.deepEqual(shown.at(-1), { text: 'people_name_required', kind: 'error', anchor: { row: 'p-kim', col: 'name' } });

        view.paste({ row: 'p-kim', col: 'country' }, [['us']], [{ row: 'p-kim', col: 'country' }]);
        assert.equal(shown.at(-1).text, 'people_paste_nothing');
        assert.equal(shown.at(-1).kind, 'warning');

        view.bulkInRound(['p-kim', 'p-pat'], 'r-solo', true);
        assert.equal(shown.at(-1).text, 'bulk_nothing');

        view.setFilter('removed');
        view.paste({ row: 'p-ola', col: 'name' }, [['A'], ['B']], [{ row: 'p-ola', col: 'name' }]);
        assert.equal(shown.at(-1).text, 'paste_no_add_removed');
    });

    test('NIT: the search result count is read out once the typing pauses, not after every letter', async () => {
        const { view, announced } = await setup();
        view.searchInput.value = 'k';
        fire(view.searchInput, 'input');
        view.searchInput.value = 'ki';
        fire(view.searchInput, 'input');
        assert.ok(!announced.some((text) => text.startsWith('shown_count')), 'nothing yet');
        await new Promise((resolve) => setTimeout(resolve, 760));
        assert.deepEqual(announced.filter((text) => text.startsWith('shown_count')), ['shown_count 1']);

        view.filtersElement.querySelector('[data-filter="no_round"]').click();
        assert.equal(announced.at(-1), 'shown_count 0', 'a filter button is said at once');
    });

    test('the phone list: the round select and the solo filters; an add refused is shown', async () => {
        const state = smallState();
        state.places.push(place('e-ana-solo', 'p-ana', 'r-solo'));
        state.people.push(person('p-x', 'X'));
        const { view, shown } = await setup({ state, phone: true });
        assert.ok([...view.filterSelect.options].some((option) => option.value === 'no_solo'));
        view.roundSelect.value = 'in:r-solo';
        fire(view.roundSelect, 'change');
        assert.deepEqual(view.ids, ['p-ana', 'p-kim', 'p-pat']);

        const input = view.context.root.querySelector('[data-plist-new]');
        input.value = 'x'.repeat(300);
        view.add();
        assert.equal(shown.at(-1).text, 'name_too_long');
        assert.equal(shown.at(-1).anchor, input);
    });
}
