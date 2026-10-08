// The round tabs' views (views/team_round_view.js, solo_round_view.js, round_cards_view.js) on the real grid, model,
// preview dialog and RoundDialog in jsdom - review D's reproductions (t1-t6) and the business review's round items:
// a fetched state's new rows (D-m1), Enter never moving anybody on its own (D-m2), the filter that never traps (D-M2),
// the open editor's conflict and "Swap them", skip reasons and blank results (D-m3, D-m7), the undo of "Take the whole
// pair out" (D-m5), the phone rename's `from` (D-m6), the core's refusals with their cause (D-m9), the toolbar (BR6),
// the paste hint (BR7), the preview's dry run again on every choice (D-m4), names into a solo round (BR3), teams made
// by a results paste (BR16), and visible feedback for refusals (BR1 - `context.notify`).
import assert from 'node:assert/strict';
import { setupDom, key, TEXTS, tick } from '../participants-sheet-core/dom.mjs';
import { ROUND_PAIRS, ROUND_SOLO, ROUND_TEAMS, person, place, smallState, team } from '../participants-sheet-core/fixture.mjs';

/**
 * A view on a real model and grid. `previewAnswer(groups, call)` = what the server's dry run answers (default: all
 * applied). Returns the view and what it did: `acted` (actions), `announced`, `notified` ({text, kind, anchor}),
 * `previews` (the dry runs' groups), `tables`.
 */
async function mount(viewName, { state = smallState(), roundId = ROUND_PAIRS, previewAnswer = null, notify = true, storage = null, phone = false } = {}) {
    setupDom();

    if (storage !== null) {
        Object.defineProperty(window, 'localStorage', { value: storage, configurable: true });
    }

    const { SheetModel } = await import('../../assets/participants_sheet/sheet_model.js');
    const { SheetGrid } = await import('../../assets/participants_sheet/sheet_grid.js');
    const { PreviewDialog } = await import('../../assets/participants_sheet/preview_dialog.js');
    const { PendingChanges } = await import('../../assets/official_results_pending_changes.js');
    const modules = {
        team: () => import('../../assets/participants_sheet/views/team_round_view.js'),
        solo: () => import('../../assets/participants_sheet/views/solo_round_view.js'),
        cards: () => import('../../assets/participants_sheet/views/round_cards_view.js'),
    };
    const create = (await modules[viewName]()).default;
    const model = new SheetModel(state, { now: () => 0 });
    const pendings = new Map();
    const log = { acted: [], announced: [], notified: [], previews: [], tables: [], undo: [] };
    const root = document.getElementById('root');
    const context = {
        root,
        kind: 'round',
        round: model.round(roundId),
        phone,
        model,
        queue: {
            results(id) {
                if (!pendings.has(id)) {
                    pendings.set(id, new PendingChanges());
                }

                return pendings.get(id);
            },
            enqueueResults() {},
            enqueueGroups() {},
            preview: async (groups) => {
                log.previews.push(groups);

                return previewAnswer ? previewAnswer(groups, log.previews.length) : { kind: 'ok', data: { groups: groups.map((group) => ({ id: group.id, status: 'applied', changes: [], warnings: [] })) } };
            },
            enqueueTables: async (id, assignments) => {
                log.tables.push(assignments);

                return { kind: 'ok', entries: [] };
            },
            problem: () => null,
            dismiss() {},
        },
        undo: { record: (action) => log.undo.push(action) },
        texts: { core: TEXTS, round: TEXTS, people: TEXTS },
        countries: { us: 'United States', ca: 'Canada', de: 'Germany' },
        countryCodes: new Set(['us', 'ca', 'de']),
        locale: 'en',
        urls: {},
        csrfToken: 'x',
        act(action) {
            log.acted.push(action);

            if (action.groups.length === 0 && (action.results ?? []).length === 0) {
                return { performed: false, errors: action.errors };
            }

            model.applyLocalMany(action.groups);

            for (const change of action.results ?? []) {
                context.queue.results(change.roundId).set(change.ref, change.field, change.to, change.from);
            }

            return { performed: true, errors: action.errors };
        },
        announce: (text) => log.announced.push(text),
        switchTab() {},
        openPersonEditor: async () => false,
        createGrid: (options) => new SheetGrid({ texts: TEXTS, announce: () => {}, undo() {}, redo() {}, ...options }),
        preview: (options) => new PreviewDialog({ host: root, texts: TEXTS, ...options }).open(),
        reasonText: (code) => `reason:${code}`,
        errorText: (error) => `reason:${error.reason}`,
        markerFor: () => null,
    };

    if (notify) {
        context.notify = (text, options = {}) => log.notified.push({ text, ...options });
    }

    const view = create(context);
    model.subscribe((delta) => view.update(delta));
    view.render();

    const changes = () => log.acted.flatMap((action) => action.groups.flatMap((group) => group.changes));

    return { model, view, context, log, changes, root };
}

function dialogOf(root) {
    return root.querySelector('dialog.sheet-preview');
}

function menuItem(root, label) {
    return [...root.querySelectorAll('.sheet-round-dialog-item')].find((item) => item.textContent.includes(label));
}

function choose(select, value) {
    select.value = value;
    select.dispatchEvent(new window.Event('change', { bubbles: true }));
}

export default function (test) {
    test('t1 / D-m1: a fetched state with a new pair shows it at once - team grid, solo grid and phone cards', async () => {
        for (const viewName of ['team', 'cards']) {
            const { model, view, root } = await mount(viewName);
            const next = smallState({ version: 'v2' });
            next.teams = [...next.teams, team('t-new', ROUND_PAIRS, 'Night Owls')];
            next.places = next.places.map((p) => (p.id === 'e-jo-pairs' ? { ...p, teamId: 't-new' } : p));
            model.replaceState(next);
            await tick();

            if (viewName === 'team') {
                assert.ok(view.grid.rows.includes('t-new'), 'team grid');
            } else {
                assert.ok(root.querySelector('[data-key="menu:t-new"]'), 'phone card');
            }

            view.destroy();
        }

        const solo = await mount('solo', { roundId: ROUND_SOLO });
        const next = smallState({ version: 'v3' });
        next.places = [...next.places, place('e-ana-solo', 'p-ana', ROUND_SOLO)];
        solo.model.replaceState(next);
        await tick();
        assert.ok(solo.view.grid.rows.includes('p-ana'));
        solo.view.destroy();
    });

    test('t2 / D-m2: Enter on a typed name never moves somebody or guesses - only the list does', async () => {
        const { view, log } = await mount('team');
        const options = view.suggest('__new', 'm0', 'Kim Ex', {}).options;
        // A partial match: not exact - nothing for Enter alone
        assert.deepEqual(options.filter((option) => !option.create).map((option) => [option.personId, option.exact, option.moves]), [['p-kim', false, true]]);

        // Committed without an option: "Kim Ex" is nobody; "Kim Example" is in Corners - not moved by typing
        assert.match(view.commit('__new', 'm0', { text: 'Kim Ex', option: null }, {}).error, /member_unknown/);
        assert.match(view.commit('__new', 'm0', { text: 'Kim Example', option: null }, {}).error, /member_pick_to_move/);
        assert.equal(log.acted.length, 0);

        // Jo is in the tray: typed in full, she joins
        view.commit('__new', 'm0', { text: 'Jo Do', option: null }, {});
        assert.equal(log.acted.length, 1);
        // Picked from the list ("moves from Corners"), Kim moves
        view.commit('__new', 'm0', { text: 'Kim Example', option: options[0] }, {});
        assert.ok(log.acted[1].groups[0].changes.some((change) => change.participant === 'p-kim' && change.from === 'team:t-corners'));
        view.destroy();
    });

    test('D-m2: RoundDialog highlights only the one exact match that moves nobody; Enter without it says how to pick', async () => {
        setupDom();
        const { RoundDialog } = await import('../../assets/participants_sheet/round/round_common.js');
        const host = document.getElementById('root');
        const options = [
            { value: 'a', label: 'Kim Example', exact: false },
            { value: 'b', label: 'Jo Do', exact: true, moves: true },
            { value: 'c', label: 'Jo Doe', exact: true, moves: false },
        ];
        const dialog = new RoundDialog({ host, title: 'Pick', closeLabel: 'Close', picker: { label: 'Find', options: () => options, pick: 'Pick with arrows' } }).open();
        assert.equal(dialog.active, 2);
        dialog.close();

        const none = new RoundDialog({ host, title: 'Pick', closeLabel: 'Close', picker: { label: 'Find', options: () => options.slice(0, 2), pick: 'Pick with arrows' } }).open();
        assert.equal(none.active, -1);
        key(none.input, 'Enter');
        assert.equal(none.settled, false);
        assert.equal(none.hint.textContent, 'Pick with arrows');
        none.close();
    });

    test('t3: an open result editor - "Saved meanwhile" refuses Enter until Keep mine, qualified from = shown, a taken table offers Swap', async () => {
        const state = smallState({ rounds: smallState().rounds.map((r) => ({ ...r, started: true })) });
        const { model, view, log } = await mount('team', { state, roundId: ROUND_TEAMS });
        const grid = view.grid;
        grid.focusCell('t-flat', 'result');
        grid.startEdit(false);
        assert.deepEqual(view.editor.seen, { seconds: 5000 });
        // seenValue reads it back without starting over (review D NIT)
        assert.deepEqual(view.seenFor('t-flat', 'result'), { seconds: 5000 });
        assert.deepEqual(view.editor.seen, { seconds: 5000 });

        model.mergeEntries([{ ref: 'team:t-flat', tableNumber: null, result: { seconds: 5100 }, qualified: false, enteredAt: '2026-10-08T09:30:00+00:00', enteredBy: { name: 'Eva' } }]);
        await tick();
        grid.editor.value = '1:30:00';
        assert.equal(grid.commitEdit(null), false);
        assert.match(grid.editorError.textContent, /meanwhile_by/);
        view.commit('t-flat', 'result', { text: '1:30:00', option: { value: 'keep' } }, {});
        assert.deepEqual(log.acted.at(-1).results, [{ roundId: ROUND_TEAMS, ref: 'team:t-flat', field: 'result', from: { seconds: 5100 }, to: { seconds: 5400 } }]);

        view.toggle([{ row: 't-flat', col: 'qualified' }], null);
        assert.deepEqual(log.acted.at(-1).results, [{ roundId: ROUND_TEAMS, ref: 'team:t-flat', field: 'qualified', from: false, to: true }]);

        model.mergeEntries([{ ref: 'team:t-edge', tableNumber: 3, result: null, qualified: false }, { ref: 'team:t-flat', tableNumber: 5, result: { seconds: 5100 }, qualified: false, enteredAt: '2026-10-08T09:30:00+00:00', enteredBy: { name: 'Eva' } }]);
        view.captureSeen('t-flat', 'table');
        assert.match(view.commit('t-flat', 'table', { text: '3', option: null }, {}).error, /table_taken/);
        view.commit('t-flat', 'table', { text: '3', option: { value: 'swap' } }, {});
        await tick();
        assert.deepEqual(log.tables, [[{ entry: 'team:t-flat', from: 5, number: 3 }, { entry: 'team:t-edge', from: 3, number: 5 }]]);
        view.destroy();
    });

    test('t4 / D-m3 / D-m7: a results paste over a waitlisted person says so, a blank result clears nothing', async () => {
        const base = smallState();
        const state = smallState({
            competition: { ...base.competition, registrationManaged: true },
            people: base.people.map((p) => (p.id === 'p-pat' ? { ...p, registration: { status: 'waitlisted', registeredAt: null, paidAt: null, checkedInAt: null } } : p)),
            rounds: base.rounds.map((r) => ({ ...r, started: true })),
        });
        const { view, root, log } = await mount('solo', { state, roundId: ROUND_SOLO });
        view.paste({ row: 'p-kim', col: 'result' }, [['1:00:01'], ['1:00:02'], ['1:00:03']]);
        await tick();
        let text = dialogOf(root).textContent;
        assert.match(text, /paste_result_waitlisted/);
        assert.match(text, /paste_result_below_list/);
        dialogOf(root).querySelector('[data-preview-cancel]').click();
        await tick();

        view.paste({ row: '__new', col: 'name' }, [['Kim Example', ''], ['Pat Sample', '58:12']]);
        await tick();
        text = dialogOf(root).textContent;
        assert.match(text, /paste_result_empty/);
        assert.match(text, /paste_result_waitlisted/);
        // Nothing to save: Confirm records nothing, and says so
        dialogOf(root).querySelector('form').dispatchEvent(new window.Event('submit', { cancelable: true }));
        await tick();
        assert.deepEqual(log.acted, []);
        assert.equal(log.notified.at(-1).text, 'paste_nothing');
        view.destroy();
    });

    test('t6 / D-M2: a filter never traps - the button and "Show all" stay at 0, the row being fixed stays until the filter changes', async () => {
        const base = smallState();
        const state = smallState({
            places: base.places.filter((p) => p.id !== 'e-max-pairs' && p.id !== 'e-jo-pairs').concat([place('e-max-pairs', 'p-max', ROUND_PAIRS)]),
            teams: base.teams.map((t) => (t.id === 't-corners2' ? { ...t, name: 'Owls' } : t)),
        });
        const { view } = await mount('team', { state });
        view.toolbar.querySelector('[data-filter="incomplete"]').click();
        assert.deepEqual(view.grid.rows, ['t-corners2', '__new']);

        view.grid.focusCell('t-corners2', 'm1');
        view.captureSeen('t-corners2', 'm1');
        view.commit('t-corners2', 'm1', { text: 'Max Demo', option: { personId: 'p-max' } }, {});
        await tick();
        // Complete now - and still shown, with the filter and a way out
        assert.deepEqual(view.grid.rows, ['t-corners2', '__new']);
        assert.ok(view.toolbar.querySelector('[data-filter="incomplete"][aria-pressed="true"]'));
        view.toolbar.querySelector('[data-filter=""]').click();
        assert.equal(view.filter, null);
        assert.ok(view.grid.rows.includes('t-corners'));
        view.destroy();
    });

    test('D-m5: "Take the whole pair out" from the row menu - its undo gives the table number back', async () => {
        const { view, root, log } = await mount('team');
        view.openRowMenu('t-corners', null);
        menuItem(root, 'menu_take_out_pair').click();
        await tick();
        const action = log.acted.at(-1);
        assert.equal(action.groups[0].changes[0].op, 'deleteTeam');
        assert.deepEqual(action.inverseResults, [{ roundId: ROUND_PAIRS, ref: 'team:t-corners', field: 'table_number', from: null, to: 2, inverseOf: action.groups[0].id }]);
        assert.match(log.announced.at(-1), /taken_out_pair/);
        view.destroy();
    });

    test('D-m6: the phone rename sends the name shown when the sheet opened - a rename meanwhile is a conflict', async () => {
        const { model, view, root, log } = await mount('cards', { phone: true });
        root.querySelector('[data-key="menu:t-corners"]').click();
        await tick();
        menuItem(root, 'menu_rename_pair').click();
        await tick();
        const field = root.querySelector('.sheet-round-dialog-form input');
        assert.equal(field.value, 'Corners');
        // Another organiser renames it while the sheet is open
        model.applyLocal('g-other', [{ op: 'renameTeam', team: 't-corners', from: 'Corners', to: 'Corner Kids' }]);
        field.value = 'Pinecones';
        root.querySelector('.sheet-round-dialog-form').dispatchEvent(new window.Event('submit', { cancelable: true }));
        await tick();
        assert.deepEqual(log.acted.at(-1).groups[0].changes, [{ op: 'renameTeam', team: 't-corners', from: 'Corners', to: 'Pinecones' }]);
        view.destroy();
    });

    test('D-m9 / BR1: emptying a pair that holds a result is refused by the core with its cause - shown next to the cell', async () => {
        const state = smallState();
        state.teams = state.teams.map((t) => (t.id === 't-corners2' ? { ...t, result: { seconds: 4000 } } : t));
        const { view, log } = await mount('team', { state });
        view.clear([{ row: 't-corners2', col: 'm0' }, { row: 't-corners2', col: 'm1' }]);
        assert.equal(log.notified.length, 1);
        assert.equal(log.notified[0].text, 'reason:team_has_result_emptied');
        assert.deepEqual(log.notified[0].anchor, { row: 't-corners2', col: 'm0' });

        // Without notify() the page still reads it out
        const quiet = await mount('cards', { state, notify: false, phone: true });
        quiet.view.removeMember('p-lee', 't-corners2');
        quiet.view.removeMember('p-max', 't-corners2');
        assert.ok(quiet.log.announced.some((text) => text === 'reason:team_has_result_emptied' || text.includes('member_to_tray')));
        quiet.view.destroy();
        view.destroy();
    });

    test('a refused action never announces its success (review D NIT)', async () => {
        const { model, view, log } = await mount('team');
        const { newTeamRow } = await import('../../assets/participants_sheet/sheet_changes.js');
        // Ola is removed from the event: a pair with her is refused
        const performed = view.run(newTeamRow(model, ROUND_PAIRS, { members: ['p-ola'] }), { success: 'created!' });
        assert.equal(performed, false);
        assert.equal(log.announced.includes('created!'), false);
        assert.equal(log.notified.length, 1);
        view.destroy();
    });

    test('BR6: "Results published …" while the round\'s results are public, the qualified count, Sort by rank', async () => {
        const state = smallState({ rounds: smallState().rounds.map((r) => ({ ...r, started: true })) });
        state.teams = state.teams.map((t) => (t.id === 't-edge' ? { ...t, result: { seconds: 6000 }, qualified: true } : t));
        const { model, view } = await mount('team', { state, roundId: ROUND_TEAMS });
        assert.equal(view.toolbar.querySelector('.sheet-round-published'), null);
        assert.match(view.toolbar.textContent, /qualified_count 1/);
        model.updateRound({ id: ROUND_TEAMS, resultsPublished: true });
        assert.match(view.toolbar.querySelector('.sheet-round-published').textContent, /results_published/);

        // Flat 5000 s before Edge 6000 s
        assert.deepEqual(view.grid.rows, ['t-edge', 't-flat', '__new']);
        view.toolbar.querySelector('[data-rank-sort]').click();
        assert.deepEqual(view.grid.rows, ['t-flat', 't-edge', '__new']);
        assert.equal(view.toolbar.querySelector('[data-rank-sort]').getAttribute('aria-pressed'), 'true');
        // A faster result for Edge: the rows follow the ranks
        model.mergeEntries([{ ref: 'team:t-edge', tableNumber: null, result: { seconds: 4000 }, qualified: true, enteredAt: '2026-10-08T10:00:00+00:00', enteredBy: { name: 'Eva' } }]);
        await tick();
        assert.deepEqual(view.grid.rows, ['t-edge', 't-flat', '__new']);
        // Results columns hidden: no rank sort
        view.toolbar.querySelector('[data-results]').click();
        assert.equal(view.toolbar.querySelector('[data-rank-sort]'), null);
        view.destroy();

        const cards = await mount('cards', { state: smallState({ rounds: state.rounds.map((r) => ({ ...r, resultsPublished: true })) }), roundId: ROUND_SOLO, phone: true });
        assert.match(cards.root.querySelector('.sheet-round-published').textContent, /results_published/);
        cards.view.destroy();
    });

    test('BR7: a visible paste hint under the round grids', async () => {
        const pairs = await mount('team');
        assert.equal(pairs.root.querySelector('.sheet-round-paste-hint').textContent.trim(), 'paste_hint_pair');
        pairs.view.destroy();
        const solo = await mount('solo', { roundId: ROUND_SOLO });
        assert.equal(solo.root.querySelector('.sheet-round-paste-hint').textContent.trim(), 'paste_hint_solo');
        solo.view.destroy();
    });

    test('a renamed person in the tray shows the new name (review D NIT)', async () => {
        const { model, view } = await mount('team');
        model.applyLocal('g1', [{ op: 'field', participant: 'p-jo', field: 'name', from: 'Jo Do', to: 'Joanna Do' }]);
        assert.match(view.tray.textContent, /Joanna Do/);
        view.destroy();
    });

    test('results columns appear with the first result unless the organiser chose (review D NIT)', async () => {
        const memory = new Map();
        const storage = { getItem: (k) => memory.get(k) ?? null, setItem: (k, v) => memory.set(k, String(v)), removeItem: (k) => memory.delete(k) };
        const { model, view } = await mount('team', { storage });
        assert.equal(view.columns.some((column) => column.key === 'result'), false);
        model.mergeEntries([{ ref: 'team:t-corners', tableNumber: 2, result: { seconds: 3000 }, qualified: false, enteredAt: '2026-10-08T10:00:00+00:00', enteredBy: { name: 'Eva' } }]);
        await tick();
        assert.equal(view.columns.some((column) => column.key === 'result'), true);
        view.destroy();

        memory.set(`participants-sheet:results-columns:${ROUND_PAIRS}`, '0');
        const chose = await mount('team', { storage });
        chose.model.mergeEntries([{ ref: 'team:t-corners', tableNumber: 2, result: { seconds: 3000 }, qualified: false, enteredAt: '2026-10-08T10:00:00+00:00', enteredBy: { name: 'Eva' } }]);
        await tick();
        assert.equal(chose.view.columns.some((column) => column.key === 'result'), false);
        chose.view.destroy();
    });

    test('D-M1 / D-m4: Confirm waits for "Choose…"; every choice runs the dry run again; the row refused under the first choice goes in under the next', async () => {
        // The dry run refuses a row that puts Lee into the first Corners
        const previewAnswer = (groups) => ({
            kind: 'ok',
            data: {
                groups: groups.map((group) => {
                    const refused = group.changes.some((change) => change.participant === 'p-lee' && change.to === 'team:t-corners');

                    return { id: group.id, status: refused ? 'refused' : 'applied', changes: refused ? [{ status: 'refused', message: 'Lee may not' }] : [], warnings: [] };
                }),
            },
        });
        const { view, root, log } = await mount('team', { previewAnswer });
        view.paste({ row: '__new', col: 'name' }, [['Corners', 'Lee Mock', 'Jo Do']]);
        await tick();
        const dialog = dialogOf(root);
        const confirm = dialog.querySelector('[data-preview-confirm]');
        const select = dialog.querySelector('[data-choice="t0"]');
        assert.equal(select.value, '');
        assert.equal(confirm.disabled, true);
        // Nothing to check yet - the row is not decided
        assert.equal(log.previews.length, 0);
        assert.match(dialog.textContent, /paste_choose_first 1/);

        choose(select, 't-corners');
        await tick();
        assert.equal(log.previews.length, 1);
        assert.match(dialog.textContent, /Lee may not/);
        // The select keeps the focus through the redraw
        choose(dialog.querySelector('[data-choice="t0"]'), 't-corners2');
        await tick();
        assert.equal(log.previews.length, 2);
        assert.doesNotMatch(dialog.textContent, /Lee may not/);
        assert.equal(dialog.querySelector('[data-preview-confirm]').disabled, false);

        dialog.querySelector('form').dispatchEvent(new window.Event('submit', { cancelable: true }));
        await tick();
        assert.equal(log.acted.length, 1);
        assert.deepEqual(log.acted[0].groups[0].changes.map((change) => change.participant ?? change.op), ['p-jo', 'p-max']);
        view.destroy();
    });

    test('BR3: a column of names pasted into a solo tab puts those people into the round - one confirm, one step', async () => {
        const { view, root, log } = await mount('solo', { roundId: ROUND_SOLO });
        view.paste({ row: '__new', col: 'name' }, [['Ana Example'], ['Kim Example'], ['Zed New'], ['Kim Exampel']]);
        await tick();
        const dialog = dialogOf(root);
        assert.match(dialog.textContent, /paste_did_you_mean/);
        const ticks = [...dialog.querySelectorAll('[data-tick]')].map((box) => [box.dataset.tick, box.checked]);
        assert.deepEqual(ticks, [['nzed new', true], ['nkim exampel', false]]);
        assert.equal(log.previews.length, 1);
        dialog.querySelector('form').dispatchEvent(new window.Event('submit', { cancelable: true }));
        await tick();
        assert.equal(log.acted.length, 1);
        assert.deepEqual(log.acted[0].groups.map((group) => group.changes.map((change) => change.op).join('+')), ['place', 'newParticipant+place']);
        assert.equal(log.acted[0].label.key, 'paste');
        assert.ok(view.grid.rows.includes('p-ana'));
        // name ⇥ result stays a results paste
        view.paste({ row: '__new', col: 'name' }, [['Kim Example', '58:12']]);
        await tick();
        assert.match(dialogOf(root).textContent, /paste_results_title/);
        view.destroy();
    });

    test('BR16: a results paste in a round of team names only creates the ticked teams, then records their results', async () => {
        const state = smallState({ places: smallState().places.filter((p) => p.roundId !== ROUND_TEAMS), teams: [team('n1', ROUND_TEAMS, 'Owls')] });
        const { view, root, log, model } = await mount('team', { state, roundId: ROUND_TEAMS });
        view.paste({ row: '__new', col: 'name' }, [['Owls', '1:00:00'], ['Bats', '1:10:00']]);
        await tick();
        const dialog = dialogOf(root);
        assert.ok(dialog.querySelector('[data-tick="r1"]').checked);
        dialog.querySelector('form').dispatchEvent(new window.Event('submit', { cancelable: true }));
        await tick();
        assert.equal(log.acted.length, 2);
        const created = log.acted[0].groups[0].changes[0];
        assert.deepEqual([created.op, created.name], ['newTeam', 'Bats']);
        assert.deepEqual(log.acted[1].results.map((change) => [change.ref, change.to]), [['team:n1', { seconds: 3600 }], [`team:${created.id}`, { seconds: 4200 }]]);
        assert.ok(model.team(created.id));
        view.destroy();
    });

    test('BR8 / D-m8 in the preview: a heading row and a partner column\'s second listing are left out with the reason', async () => {
        const { view, root } = await mount('team');
        view.paste({ row: '__new', col: 'm0' }, [['Member 1', 'Member 2'], ['Jo Do', 'Ana Example'], ['Ana Example', 'Jo Do']]);
        await tick();
        const text = dialogOf(root).textContent;
        assert.match(text, /paste_header_skipped/);
        assert.match(text, /paste_same_people_pair/);
        assert.doesNotMatch(text, /paste_twice/);
        assert.match(text, /count_listed_twice 1/);
        view.destroy();
    });
}
