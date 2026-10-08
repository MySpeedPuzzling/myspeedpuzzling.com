/**
 * A solo round tab on a desktop (O2, O3, O8; docs/features/competitions-management/participants-spreadsheet.md §11b and
 * "Client architecture (as built)") - one row per person of the round:
 *
 *   Table (or a plain row index "#") · Name · Country · Result · Rank · Qualified · ⋯
 *
 * - Name and country are the People tab's (read-only here): Enter or Space on a name opens the person editor when there
 *   is one, else the People tab at that person. Delete on a name, or the row's ⋯ menu, takes the person out of this
 *   round (refused with the reason while they hold a result here).
 * - The new row at the bottom: type a person (people of the event not in this round, or `+ Add "Jo Do" as a new
 *   participant`) → in the round.
 * - Results / rank / qualified (O3) like the pair/team tab (round/round_common.js RoundResultsCells): RecordRoundResults only,
 *   "Table 6 is Ben's · Swap them", "Saved meanwhile by Eva · Keep mine / Take theirs"; people on the waitlist are
 *   ignored by the results tools (official-results rule) - their cells say so.
 * - Paste: results (`name ⇥ result` matched only to people of this round, or one column onto Result).
 */

import { escapeHtml } from '../sheet_grid.js';
import { buildAction, combine, isEmpty, setPlace } from '../sheet_changes.js';
import { IN, OUT, cleanName } from '../sheet_model.js';
import { newClientId } from '../../official_results_api.js';
import {
    flagHtml,
    focusKey,
    keepOrder,
    nameCollator,
    pageStorage,
    personOptions,
    previewResults,
    resultsColumnsShown,
    RoundDialog,
    roundEntries,
    roundRefusalText,
    RoundResultsCells,
    safeColor,
    sortedPeopleIds,
    storeResultsColumns,
    usesTables,
} from '../round/round_common.js';
import { officialEdits, resultEditText, roundRanks } from '../sheet_results.js';
import { looksLikeResults, matchPerson, planResultsPaste } from '../round_paste.js';

export const NEW_ROW = '__new';
const RESULT_COLUMNS = ['result', 'rank', 'qualified'];

export default function createSoloRoundView(context) {
    return new SoloRoundView(context);
}

export class SoloRoundView {
    constructor(context) {
        this.context = context;
        this.model = context.model;
        this.roundId = context.round.id;
        this.texts = context.texts.round;
        this.core = context.texts.core;
        this.results = new RoundResultsCells(context);
        this.collator = nameCollator(context.locale);
        this.storage = pageStorage();
        this.grid = null;
        this.order = [];
        this.filter = null;
        this.editor = null;
        this.ranks = new Map();
        this.listeners = [];
        this.rebuildTimer = null;
        this.dialog = null;
    }

    t(key, params) {
        return this.texts.t(key, params);
    }

    tc(key, count, params) {
        return this.texts.tc(key, count, params);
    }

    // ---------------------------------------------------------------- the view interface

    render() {
        const root = this.context.root;
        root.classList.add('sheet-view', 'sheet-view-round', 'sheet-view-solo');
        this.toolbar = document.createElement('div');
        this.toolbar.className = 'sheet-round-toolbar';
        this.gridRoot = document.createElement('div');
        this.gridRoot.className = 'sheet-grid-host';
        root.replaceChildren(this.toolbar, this.gridRoot);
        this.on(this.toolbar, 'click', (event) => this.onToolbarClick(event));

        this.showResults = resultsColumnsShown(this.model, this.roundId, this.storage);
        this.order = sortedPeopleIds(this.model, this.roundId, this.collator);
        this.buildGrid();
        this.renderToolbar();
    }

    buildGrid() {
        this.columns = this.buildColumns();
        this.ranks = this.computeRanks();
        this.grid = this.context.createGrid({
            container: this.gridRoot,
            label: this.t('grid_label', { round: this.model.round(this.roundId)?.name ?? '' }),
            columns: this.columns,
            rows: this.rowKeys(),
            cell: (row, col) => this.cell(row, col),
            rowLabel: (row) => (row === NEW_ROW ? this.t('solo_new_row') : this.model.person(row)?.name ?? ''),
            rowClass: (row) => this.rowClass(row),
            editValue: (row, col) => this.editValue(row, col),
            // What the organiser saw when an edit began is the `from` of its save (the core calls one of the two)
            editStart: (row, col) => this.editStart(row, col),
            seenValue: (row, col) => this.editStart(row, col),
            suggest: (row, col, query, info) => this.suggest(row, col, query, info),
            commit: (row, col, input) => this.commit(row, col, input),
            toggle: (cells, value) => this.toggle(cells, value),
            clear: (cells) => this.clear(cells),
            paste: (anchor, rows) => this.paste(anchor, rows),
            fill: () => this.context.announce(this.t('fill_not_here')),
            activate: (row, col) => this.activate(row, col),
            openPanel: (row) => this.openPerson(row),
        });
    }

    rebuild() {
        clearTimeout(this.rebuildTimer);
        this.rebuildTimer = null;

        if (this.grid === null) {
            return;
        }

        if (this.grid.isEditing()) {
            // A live update asked for new columns while the organiser types: after the edit
            this.rebuildTimer = setTimeout(() => this.rebuild(), 300);

            return;
        }

        const active = { ...this.grid.active };
        const focused = this.gridRoot.contains(document.activeElement);
        this.grid.destroy();
        this.buildGrid();

        if (focused) {
            this.grid.focusCell(active.row, this.columns.some((column) => column.key === active.col) ? active.col : 'name');
        }
    }

    scheduleRebuild() {
        if (this.rebuildTimer === null) {
            this.rebuildTimer = setTimeout(() => this.rebuild(), 0);
        }
    }

    update(delta) {
        const round = this.model.round(this.roundId);

        if (this.grid === null || round === null) {
            return;
        }

        if (delta.all) {
            this.scheduleRebuild();
            this.renderToolbar();

            return;
        }

        if (this.tablesUsed !== usesTables(this.model, round) || this.roundName !== round.name) {
            this.scheduleRebuild();
        }

        const inRound = this.model.peopleIn(this.roundId).map((person) => person.id);
        this.order = keepOrder(this.order, inRound);
        const keys = this.rowKeys();
        const rowsChanged = keys.length !== this.grid.rows.length || keys.some((key, index) => this.grid.rows[index] !== key);

        if (rowsChanged) {
            this.grid.setRows(keys);
        }

        const ours = new Set(inRound);
        this.grid.updateRows([...delta.people].filter((id) => ours.has(id)));

        if (delta.rounds.has(this.roundId)) {
            this.updateRanks();
        }

        if (rowsChanged && !this.tablesUsed) {
            keys.forEach((key) => this.grid.updateCell(key, 'table'));
        }

        if (rowsChanged || delta.rounds.has(this.roundId) || delta.people.size > 0) {
            this.renderToolbar();
        }

        this.refreshOpenEditor(delta.people);
    }

    focus(target = null) {
        if (this.grid === null) {
            return;
        }

        if (target?.personId && this.grid.focusCell(target.personId, target.col && target.col !== 'name' ? target.col : 'name')) {
            return;
        }

        this.grid.focusActive();
    }

    reveal(problem) {
        const key = problem?.target?.key ?? '';

        if (key.startsWith('place:') || key.startsWith('person:')) {
            return this.grid?.focusCell(key.split(':')[1], 'name') ?? false;
        }

        if (key.startsWith('result:')) {
            const field = key.slice(key.lastIndexOf(':') + 1);
            const ref = key.slice('result:'.length, key.lastIndexOf(':'));
            const place = this.model.placeById(ref.slice('participant_round:'.length));

            if (place === null) {
                return false;
            }

            if (field !== 'table_number' && !this.showResults) {
                this.toggleResults(true);
            }

            return this.grid?.focusCell(place.participantId, field === 'table_number' ? 'table' : field) ?? false;
        }

        return false;
    }

    destroy() {
        clearTimeout(this.rebuildTimer);
        this.dialog?.close();
        this.listeners.forEach((remove) => remove());
        this.listeners = [];
        this.grid?.destroy();
        this.grid = null;
    }

    on(target, type, handler, options) {
        target.addEventListener(type, handler, options);
        this.listeners.push(() => target.removeEventListener(type, handler, options));
    }

    // ---------------------------------------------------------------- columns and rows

    buildColumns() {
        const round = this.model.round(this.roundId);
        this.tablesUsed = usesTables(this.model, round);
        this.roundName = round.name;
        const columns = [
            this.tablesUsed
                ? { key: 'table', label: this.t('col_table'), kind: 'list', width: 84, autoHighlight: false, className: 'sheet-col-table' }
                : { key: 'table', label: this.t('col_index'), kind: 'readonly', width: 56, className: 'sheet-col-table' },
            { key: 'name', label: this.t('col_person'), kind: 'list', width: 240, space: 'panel' },
            { key: 'country', label: this.t('col_country'), kind: 'readonly', width: 170 },
        ];

        if (this.showResults) {
            columns.push(
                { key: 'result', label: this.t('col_result'), kind: 'list', width: 150, autoHighlight: false, className: 'sheet-col-result' },
                { key: 'rank', label: this.t('col_rank'), kind: 'readonly', width: 64, className: 'sheet-col-rank' },
                { key: 'qualified', label: this.t('col_qualified'), kind: 'checkbox', width: 96 },
            );
        }

        columns.push({
            key: 'actions',
            label: this.t('col_actions'),
            headerHtml: `<span class="visually-hidden">${escapeHtml(this.t('col_actions'))}</span>`,
            kind: 'action',
            width: 52,
            className: 'sheet-col-actions',
        });

        return columns;
    }

    rowKeys() {
        const ours = new Set(this.model.peopleIn(this.roundId).map((person) => person.id));
        let ids = this.order.filter((id) => ours.has(id));

        if (this.filter === 'waitlist') {
            ids = ids.filter((id) => this.model.isWaitlisted(id));
        }

        this.indexByRow = new Map(ids.map((id, index) => [id, index + 1]));

        return [...ids, NEW_ROW];
    }

    rowClass(row) {
        if (row === NEW_ROW) {
            return 'sheet-row-new';
        }

        const classes = [];

        if (this.model.isWaitlisted(row)) {
            classes.push('sheet-row-waitlisted');
        }

        if (this.model.place(row, this.roundId)?.local) {
            classes.push('sheet-row-local');
        }

        return classes.join(' ');
    }

    /** The person's results entry as the page shows it (null: not saved yet, or on the waitlist - see why()). */
    entryOf(personId) {
        const place = this.model.place(personId, this.roundId);

        if (place === null) {
            return null;
        }

        const ref = this.model.isWaitlisted(personId) ? null : this.model.entryRef(personId, this.roundId);
        const table = this.results.shown(ref, 'table_number', place.table);

        return {
            id: place.id,
            ref,
            displayName: this.model.person(personId)?.name ?? '',
            table,
            tableNumber: table,
            serverTable: place.table,
            result: this.results.shown(ref, 'result', place.result),
            serverResult: place.result,
            qualified: this.results.shown(ref, 'qualified', place.qualified) === true,
            serverQualified: place.qualified === true,
            place,
        };
    }

    /** Why a person's official cells cannot be edited: on the waitlist, or the place is not saved yet. */
    why(personId) {
        if (this.model.isWaitlisted(personId)) {
            return this.t('results_waitlisted');
        }

        return this.model.entryRef(personId, this.roundId) === null ? this.t('result_not_ready') : '';
    }

    computeRanks() {
        return roundRanks(roundEntries(this.model, this.roundId, this.results.pending(), this.texts));
    }

    updateRanks() {
        if (!this.showResults) {
            return;
        }

        const previous = this.ranks;
        this.ranks = this.computeRanks();
        const byPlace = new Map([...this.model.peopleIn(this.roundId)].map((person) => [this.model.place(person.id, this.roundId)?.id, person.id]));

        for (const id of new Set([...this.ranks.keys(), ...previous.keys()])) {
            if (this.ranks.get(id) !== previous.get(id) && byPlace.has(id)) {
                this.grid.updateCell(byPlace.get(id), 'rank');
            }
        }
    }

    // ---------------------------------------------------------------- cells

    cell(row, col) {
        if (row === NEW_ROW) {
            if (col === 'name') {
                return { text: '', html: `<span class="sheet-placeholder"><i class="bi bi-plus-lg" aria-hidden="true"></i> ${escapeHtml(this.t('solo_new_row'))}</span>`, copy: '' };
            }

            if (col === 'table') {
                return { text: '', html: '<span class="sheet-placeholder" aria-hidden="true">+</span>', copy: '', readonly: true, kind: 'readonly' };
            }

            return { text: '', copy: '', readonly: true, kind: 'readonly' };
        }

        const person = this.model.person(row);
        const entry = this.entryOf(row);

        if (person === null || entry === null) {
            return { text: '', readonly: true };
        }

        const reason = this.why(row);

        switch (col) {
            case 'table':
                if (!this.tablesUsed) {
                    return { text: String(this.indexByRow?.get(row) ?? ''), readonly: true, className: 'sheet-col-table' };
                }

                return this.results.tableCell(entry.ref, entry.place.table, { readonly: reason !== '' });
            case 'name': {
                const badge = this.model.isWaitlisted(row) ? `<span class="sheet-badge sheet-badge-waitlist">${escapeHtml(this.t('waitlisted'))}</span>` : '';

                return {
                    text: person.name,
                    html: badge ? `${escapeHtml(person.name)}${badge}` : undefined,
                    kind: 'action',
                    marker: this.context.markerFor(`place:${row}:${this.roundId}`) ?? this.context.markerFor(`person:${row}:name`),
                };
            }
            case 'country': {
                const label = person.country ? (this.context.countries[person.country] ?? person.country.toUpperCase()) : '';

                return { text: label, html: person.country ? `${flagHtml(person.country)}${escapeHtml(label)}` : '', readonly: true };
            }
            case 'result':
                return this.results.resultCell(entry.ref, entry.place.result, entry.place.enteredBy, entry.place.enteredAt, { readonly: reason !== '', reason });
            case 'rank':
                return this.results.rankCell(entry.ref, this.ranks.get(entry.place.id) ?? null, entry.result, entry.place.result);
            case 'qualified':
                return this.results.qualifiedCell(entry.ref, entry.place.qualified, this.t('qualified_label', { team: person.name }), { readonly: reason !== '' });
            case 'actions':
                return { text: '', html: `<i class="bi bi-three-dots" aria-hidden="true"></i><span class="visually-hidden">${escapeHtml(this.t('row_actions_label', { team: person.name }))}</span>`, copy: '', className: 'sheet-col-actions' };
            default:
                return { text: '', readonly: true };
        }
    }

    editValue(row, col) {
        const entry = row === NEW_ROW ? null : this.entryOf(row);

        if (entry === null) {
            return '';
        }

        if (col === 'table') {
            return entry.table === null || entry.table === undefined ? '' : String(entry.table);
        }

        return col === 'result' ? resultEditText(entry.result) : '';
    }

    /** An edit of a table or result cell began: what it showed is the `from` of its save (openEditor()). */
    editStart(row, col) {
        this.editor = null;

        if (row === NEW_ROW || (col !== 'table' && col !== 'result')) {
            return null;
        }

        const entry = this.entryOf(row);

        if (entry === null || entry.ref === null) {
            return null;
        }

        const field = col === 'table' ? 'table_number' : 'result';
        this.editor = { row, col, ...this.results.openEditor(entry.ref, field, field === 'result' ? entry.place.result : entry.place.table) };

        return this.editor.seen;
    }

    editorFor(row, col) {
        return this.editor !== null && this.editor.row === row && this.editor.col === col ? this.editor : null;
    }

    refreshOpenEditor(people) {
        const editing = this.grid?.editing;

        if (!editing || !people.has(editing.row) || (editing.col !== 'result' && editing.col !== 'table')) {
            return;
        }

        const editor = this.editorFor(editing.row, editing.col);
        const place = this.model.place(editing.row, this.roundId);

        if (editor !== null && place !== null && this.results.meanwhile(editor, editing.col === 'result' ? place.result : place.table, place.enteredBy) !== null) {
            this.grid.openList(true);
        }
    }

    // ---------------------------------------------------------------- suggestions and editing

    suggest(row, col, query, info = {}) {
        if (row === NEW_ROW) {
            if (col !== 'name') {
                return [];
            }

            const options = query.trim() === '' ? [] : personOptions(this.model, this.roundId, query, this.texts, { only: 'out', countries: this.context.countries });

            return { options, hint: query.trim() === '' ? this.t('solo_new_hint') : '' };
        }

        const place = this.model.place(row, this.roundId);

        if (place === null) {
            return [];
        }

        if (col === 'result') {
            return this.results.resultSuggest(this.editorFor(row, col), place.result, place.enteredBy, query, info.explicit === true);
        }

        if (col === 'table') {
            return this.results.tableSuggest(this.editorFor(row, col), this.entryOf(row), query);
        }

        return [];
    }

    options() {
        return { countries: this.context.countryCodes };
    }

    /** A client refusal in the server's words (cause variants, parameters - the core's refusalDetails()). */
    reasonText(error) {
        return roundRefusalText(this.context, this.model, this.texts, error);
    }

    perform(action) {
        const blocked = action.groups.length === 0 && (action.results ?? []).length === 0 && action.errors.length > 0;
        const outcome = this.context.act(action, { quiet: blocked });

        if (blocked) {
            return { error: this.reasonText(outcome.errors[0]) };
        }

        return undefined;
    }

    commit(row, col, input) {
        if (row === NEW_ROW) {
            return col === 'name' ? this.commitNewPerson(input) : undefined;
        }

        if (col === 'table' || col === 'result') {
            const entry = this.entryOf(row);
            const answer = col === 'table'
                ? this.results.tableCommit(this.editorFor(row, col), entry, input)
                : this.results.resultCommit(this.editorFor(row, col), entry?.place.result ?? null, entry?.place.enteredBy ?? null, input);

            if (answer === null || answer?.theirs) {
                this.editor = null;

                return undefined;
            }

            if (answer.error) {
                return { error: answer.error };
            }

            this.editor = null;

            if (answer.swap) {
                this.results.swapTables(answer.swap, answer.holder);

                return undefined;
            }

            return this.perform(answer.action);
        }

        return undefined;
    }

    /** The new row: a person of the event (or a new one, D9) into this round. */
    commitNewPerson(input) {
        const option = input.option;
        let action = null;
        let name = '';

        if (option?.create) {
            const id = newClientId();
            name = cleanName(option.name);
            action = buildAction(this.model, [[
                { op: 'newParticipant', id, name, country: null, externalId: null },
                { op: 'place', participant: id, round: this.roundId, from: OUT, to: IN },
            ]], { label: { key: 'add_person' }, ...this.options() });
        } else {
            let personId = option?.personId ?? null;
            const text = input.text.trim();

            if (personId === null) {
                if (text === '') {
                    return undefined;
                }

                const found = matchPerson(this.model, text, this.roundId);

                if (found.status !== 'one') {
                    return { error: this.t(found.status === 'several' ? 'member_ambiguous' : 'member_unknown', { name: cleanName(text) }) };
                }

                personId = found.ids[0];
            }

            if (this.model.placeValue(personId, this.roundId) !== OUT) {
                return { error: this.t('solo_already_in', { name: this.model.person(personId)?.name ?? '' }) };
            }

            name = this.model.person(personId)?.name ?? '';
            action = setPlace(this.model, personId, this.roundId, IN, { label: { key: 'round_in' }, ...this.options() });
        }

        const error = this.perform(action);

        if (error) {
            return error;
        }

        this.context.announce(this.t(option?.create ? 'added_new_to_round' : 'added_to_round', { name }));

        // Enter: the next person goes into the new row again
        return { focus: () => ({ row: NEW_ROW, col: 'name' }) };
    }

    toggle(cells, value) {
        const entries = cells.filter((cell) => cell.col === 'qualified' && cell.row !== NEW_ROW).map((cell) => this.entryOf(cell.row)).filter((entry) => entry !== null && entry.ref !== null);
        const action = this.results.qualifiedAction(entries, value);

        if (!isEmpty(action)) {
            this.context.act(action);
        }
    }

    clear(cells) {
        const out = [];
        const results = [];

        for (const { row, col } of cells) {
            if (row === NEW_ROW) {
                continue;
            }

            const entry = this.entryOf(row);

            if (entry === null) {
                continue;
            }

            if (col === 'name') {
                out.push(row);
            } else if (entry.ref !== null && col === 'table' && this.tablesUsed && entry.table !== null) {
                results.push({ roundId: this.roundId, ref: entry.ref, field: 'table_number', from: entry.table, to: null });
            } else if (entry.ref !== null && col === 'result' && entry.result !== null) {
                results.push({ roundId: this.roundId, ref: entry.ref, field: 'result', from: entry.result, to: null });
            } else if (entry.ref !== null && col === 'qualified' && entry.qualified) {
                results.push({ roundId: this.roundId, ref: entry.ref, field: 'qualified', from: true, to: false });
            }
        }

        const action = combine(
            { key: 'clear' },
            ...out.map((personId) => setPlace(this.model, personId, this.roundId, OUT, { label: { key: 'round_out' }, ...this.options() })),
            results.length > 0 ? officialEdits(results) : null,
        );

        if (!isEmpty(action) || action.errors.length > 0) {
            this.context.act(action, { quiet: true });

            if (action.errors.length > 0) {
                this.context.announce(this.reasonText(action.errors[0]));
            } else if (out.length > 0) {
                this.context.announce(this.tc('taken_out_people', out.length));
            }
        }
    }

    // ---------------------------------------------------------------- the person, row actions

    activate(row, col) {
        if (row === NEW_ROW) {
            return;
        }

        if (col === 'name') {
            this.openPerson(row);
        } else if (col === 'actions') {
            this.openRowMenu(row, this.grid.cellElement(row, 'actions'));
        }
    }

    /** The person editor (stream E) when there is one, else the People tab at that person. */
    async openPerson(personId) {
        if (personId === NEW_ROW || this.model.person(personId) === null) {
            return;
        }

        const opened = await this.context.openPersonEditor(personId);

        if (opened !== true) {
            this.context.switchTab('people', { personId, col: 'name' });
        }
    }

    async openRowMenu(personId, anchor) {
        const person = this.model.person(personId);

        if (person === null) {
            return;
        }

        const holds = this.model.holdsDataInRound(personId, this.roundId);
        this.dialog?.close();
        this.dialog = new RoundDialog({
            host: this.context.root,
            closeLabel: this.t('dialog_close'),
            title: person.name,
            anchor,
            items: [
                { value: 'open', label: this.t('menu_open_person'), icon: 'bi-person' },
                { value: 'out', label: this.t('menu_take_person_out'), icon: 'bi-box-arrow-right', danger: true, disabled: holds, reason: holds ? this.reasonText({ reason: 'has_result_in_round', change: { participant: personId, round: this.roundId } }) : '' },
            ],
            returnFocus: () => {
                if (this.grid && !this.grid.focusCell(personId, 'actions')) {
                    this.grid.focusActive();
                }
            },
            onDisabled: (item) => this.context.announce(item.reason),
        }).open();
        const choice = await this.dialog.result;

        if (choice?.value === 'open') {
            this.openPerson(personId);
        } else if (choice?.value === 'out') {
            const error = this.perform(setPlace(this.model, personId, this.roundId, OUT, { label: { key: 'round_out' }, ...this.options() }));
            this.context.announce(error ? error.error : this.t('taken_out_person', { name: person.name }));
        }
    }

    // ---------------------------------------------------------------- toolbar

    renderToolbar() {
        const round = this.model.round(this.roundId);

        if (round === null) {
            return;
        }

        const people = this.model.peopleIn(this.roundId);
        const waitlisted = people.filter((person) => this.model.isWaitlisted(person.id)).length;
        const urls = round.urls ?? {};
        const links = [
            [urls.liveEntry, 'link_live_entry', 'bi-broadcast'],
            [urls.resultsDesk, 'link_results_desk', 'bi-table'],
            [this.tablesUsed ? urls.seating : null, 'link_seating', 'bi-grid-3x3-gap'],
        ].filter(([url]) => typeof url === 'string' && url !== '')
            .map(([url, key, icon]) => `<a class="btn btn-sm btn-link" href="${escapeHtml(url)}" target="_blank" rel="noopener"><i class="bi ${icon}" aria-hidden="true"></i> ${escapeHtml(this.t(key))}<span class="visually-hidden"> ${escapeHtml(this.t('new_tab'))}</span></a>`)
            .join('');
        const filter = waitlisted > 0
            ? `<div class="sheet-round-filters" role="group" aria-label="${escapeHtml(this.t('filters_label'))}"><button type="button" class="btn btn-sm sheet-round-filter${this.filter === 'waitlist' ? ' active' : ''}" data-filter="waitlist" aria-pressed="${this.filter === 'waitlist' ? 'true' : 'false'}"><i class="bi bi-hourglass-split" aria-hidden="true"></i> ${escapeHtml(this.tc('filter_waitlist', waitlisted))}</button></div>`
            : '';
        const html = `<div class="sheet-round-summary">
                <span class="sheet-round-swatch" style="background-color:${escapeHtml(safeColor(round.color))}" aria-hidden="true"></span>
                <strong>${escapeHtml(round.name)}</strong>
                <span>${escapeHtml(this.core.tc('tab_count_people', people.length))}</span>
            </div>
            ${filter}
            <div class="sheet-round-actions">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-sort title="${escapeHtml(this.t('sort_hint'))}"><i class="bi bi-sort-numeric-down" aria-hidden="true"></i> ${escapeHtml(this.t('sort'))}</button>
                <button type="button" class="btn btn-sm btn-outline-secondary" data-results aria-pressed="${this.showResults ? 'true' : 'false'}"><i class="bi bi-stopwatch" aria-hidden="true"></i> ${escapeHtml(this.t('results_columns'))}</button>
                ${links}
            </div>`;

        if (this.toolbar.dataset.html !== html) {
            const focused = this.toolbar.contains(document.activeElement) ? focusKey(document.activeElement) : null;
            this.toolbar.innerHTML = html;
            this.toolbar.dataset.html = html;

            if (focused) {
                this.toolbar.querySelector(focused)?.focus();
            }
        }
    }

    onToolbarClick(event) {
        const filter = event.target.closest('[data-filter]');

        if (filter) {
            this.filter = this.filter === filter.dataset.filter ? null : filter.dataset.filter;
            this.grid.setRows(this.rowKeys());
            this.renderToolbar();
            this.context.announce(this.filter === null ? this.t('filter_cleared') : this.tc('filter_shown', this.grid.rows.length - 1));

            return;
        }

        if (event.target.closest('[data-sort]')) {
            this.order = sortedPeopleIds(this.model, this.roundId, this.collator);
            this.grid.setRows(this.rowKeys());

            if (!this.tablesUsed) {
                this.grid.rows.forEach((key) => this.grid.updateCell(key, 'table'));
            }

            this.context.announce(this.t('sorted'));

            return;
        }

        if (event.target.closest('[data-results]')) {
            this.toggleResults(!this.showResults);
        }
    }

    toggleResults(shown) {
        this.showResults = shown;
        storeResultsColumns(this.storage, this.roundId, shown);
        this.rebuild();
        this.renderToolbar();
        this.context.announce(this.t(shown ? 'results_shown' : 'results_hidden'));
    }

    // ---------------------------------------------------------------- paste

    paste(anchor, block) {
        if (RESULT_COLUMNS.includes(anchor.col)) {
            if (anchor.col !== 'result' || block.some((row) => row.length > 1)) {
                this.context.announce(this.t('paste_results_column'));

                return;
            }

            this.pasteResults(block, 'positional', anchor);

            return;
        }

        if (looksLikeResults(block, this.results.parseOptions(), this.model)) {
            this.pasteResults(block, 'names', anchor);

            return;
        }

        this.context.announce(this.t('solo_paste_hint'));
    }

    async pasteResults(block, mode, anchor) {
        const entries = roundEntries(this.model, this.roundId, this.results.pending(), this.texts);
        const startRow = this.grid.rows.indexOf(anchor.row);
        const targets = mode === 'positional'
            ? block.map((row, index) => {
                const key = this.grid.rows[startRow + index];

                return key === undefined || key === NEW_ROW ? null : (this.entryOf(key)?.ref ?? null);
            })
            : [];
        const plan = planResultsPaste(this.model, this.roundId, entries, block, { mode, targets, parse: this.results.parseOptions() });
        await previewResults(this, plan, entries);
    }
}
