/**
 * A pair/team round tab on a desktop (D1, O1, O3, O7; docs/features/competitions-management/participants-spreadsheet.md
 * §4 (C), §5, §11b and "Client architecture (as built)") - one row per pair/team:
 *
 *   Table (or a plain row index "#" when the round uses no table numbers) · Name · Member 1 … Member N · + · Size ·
 *   Result · Rank · Qualified · ⋯
 *
 * - The new-team row at the bottom: a name, or a person, creates the pair/team (Tab goes on in the new row's next cell,
 *   Enter starts the next one); member cells are typeaheads over the event's people with where they are in this round,
 *   "moves from Table 12 · Pinecones", and `+ Add "Jo Do" as a new participant` (D9); clearing a member puts them in the
 *   tray ("In the round without a pair") below the grid, whose chips pair people up, add them to a pair/team, start a new
 *   one or take them out of the round.
 * - Size in text (never colour alone), same-named pairs/teams told apart by table or members (O1, O7).
 * - Results / rank / qualified (O3): written only through RecordRoundResults (`context.act` with `results`), a table
 *   number taken by another entry offers "Swap them" (one AssignTableNumbers write); `from` of a result or table edit is
 *   what the cell showed when the edit began, a value saved by somebody else meanwhile asks "Keep mine / Take theirs".
 * - Paste: rows of pairs/teams (`Team ⇥ member ⇥ member`) and results (`name ⇥ result`, or a column onto Result) -
 *   round_paste.js, through the preview with the server's dry run.
 *
 * Every change is an action of sheet_changes.js / sheet_results.js handed to `context.act()`; edits re-render only the
 * rows the model's delta names.
 */

import { escapeHtml, markerHtml } from '../sheet_grid.js';
import {
    buildAction,
    combine,
    deleteTeam,
    isEmpty,
    newTeamRow,
    renameTeam,
    setPlace,
    setTeamSize,
} from '../sheet_changes.js';
import { IN, OUT, TEAM_SIZE_MAX, TEAM_SIZE_MIN, cleanName, cleanTeamName, hasOfficialData, parsePlace, teamPlace } from '../sheet_model.js';
import { newClientId } from '../../official_results_api.js';
import {
    RoundDialog,
    RoundResultsCells,
    flagHtml,
    focusKey,
    keepOrder,
    memberSlots,
    nameCollator,
    pageStorage,
    personOptions,
    previewResults,
    resultsColumnsShown,
    roundEntries,
    safeColor,
    sizeInfo,
    sortedTeamIds,
    storeResultsColumns,
    teamLabelText,
    teamOptions,
    teamShortLabel,
    usesTables,
    whereInRound,
} from '../round_common.js';
import { officialEdits, resultEditText, roundRanks } from '../sheet_results.js';
import {
    NEW_TEAM,
    SKIP,
    buildTeamPasteAction,
    looksLikeResults,
    matchPerson,
    planResultsPaste,
    planTeamPaste,
} from '../round_paste.js';

export const NEW_ROW = '__new';
// A paste touching more cells than this - or with anything to decide - is previewed first (§6)
export const PREVIEW_ABOVE_CELLS = 10;
const RESULT_COLUMNS = ['result', 'rank', 'qualified'];

export default function createTeamRoundView(context) {
    return new TeamRoundView(context);
}

export class TeamRoundView {
    constructor(context) {
        this.context = context;
        this.model = context.model;
        this.round = context.round;
        this.roundId = context.round.id;
        this.texts = context.texts.round;
        this.core = context.texts.core;
        this.results = new RoundResultsCells(context);
        this.collator = nameCollator(context.locale);
        this.storage = pageStorage();
        this.grid = null;
        this.order = [];
        this.slots = new Map();
        this.intents = new Map();
        this.filter = null;
        this.editor = null;
        this.ranks = new Map();
        this.sameNames = new Map();
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

    duo() {
        return this.model.round(this.roundId)?.category === 'duo';
    }

    /** "pair" / "team" wording - `<key>_pair` or `<key>_team`. */
    tk(key, params) {
        return this.t(`${key}_${this.duo() ? 'pair' : 'team'}`, params);
    }

    // ---------------------------------------------------------------- the view interface

    render() {
        const root = this.context.root;
        root.classList.add('sheet-view', 'sheet-view-round', 'sheet-view-teams');
        this.toolbar = document.createElement('div');
        this.toolbar.className = 'sheet-round-toolbar';
        this.gridRoot = document.createElement('div');
        this.gridRoot.className = 'sheet-grid-host';
        this.tray = document.createElement('section');
        this.tray.className = 'sheet-round-tray';
        this.tray.setAttribute('aria-labelledby', `sheet-tray-title-${this.roundId}`);
        root.replaceChildren(this.toolbar, this.gridRoot, this.tray);

        this.on(this.toolbar, 'click', (event) => this.onToolbarClick(event));
        this.on(this.toolbar, 'change', (event) => this.onToolbarChange(event));
        this.on(this.tray, 'click', (event) => this.onTrayClick(event));

        if (typeof ResizeObserver !== 'undefined') {
            const observer = new ResizeObserver(() => this.fitTray());
            observer.observe(this.tray);
            this.listeners.push(() => observer.disconnect());
        }

        this.showResults = resultsColumnsShown(this.model, this.roundId, this.storage);
        this.order = sortedTeamIds(this.model, this.roundId, this.collator);
        this.sameNames = new Map(this.model.sameNameTeams(this.roundId));
        this.buildGrid();
        this.renderToolbar();
        this.renderTray();
    }

    buildGrid() {
        this.memberCount = this.wantedMemberCount();
        this.columns = this.buildColumns();
        this.signature = this.roundSignature();
        this.ranks = this.computeRanks();
        this.grid = this.context.createGrid({
            container: this.gridRoot,
            label: this.t('grid_label', { round: this.model.round(this.roundId)?.name ?? '' }),
            columns: this.columns,
            rows: this.rowKeys(),
            cell: (row, col) => this.cell(row, col),
            rowLabel: (row) => (row === NEW_ROW ? this.tk('new_row') : teamLabelText(this.model, row, this.texts)),
            rowClass: (row) => this.rowClass(row),
            editValue: (row, col) => this.editValue(row, col),
            // What the organiser saw when an edit began is the `from` of its save (the core calls one of the two)
            editStart: (row, col) => this.captureSeen(row, col),
            seenValue: (row, col) => this.captureSeen(row, col),
            suggest: (row, col, query, info) => this.suggest(row, col, query, info),
            commit: (row, col, input, info) => this.commit(row, col, input, info),
            toggle: (cells, value) => this.toggle(cells, value),
            clear: (cells) => this.clear(cells),
            paste: (anchor, rows, selected) => this.paste(anchor, rows, selected),
            fill: () => this.context.announce(this.t('fill_not_here')),
            activate: (row, col) => this.activate(row, col),
        });
        this.on(this.grid.table, 'contextmenu', (event) => this.onContextMenu(event));
    }

    /** Columns changed (member count, results shown, the round's table usage): a new grid, the same cell focused. */
    rebuild() {
        clearTimeout(this.rebuildTimer);
        this.rebuildTimer = null;

        if (this.grid === null) {
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
        // Never inside a commit: the grid finishes the edit first
        if (this.rebuildTimer === null) {
            this.rebuildTimer = setTimeout(() => this.rebuild(), 0);
        }
    }

    update(delta) {
        if (this.grid === null || this.model.round(this.roundId) === null) {
            return;
        }

        const round = this.model.round(this.roundId);

        if (delta.all) {
            this.scheduleRebuild();
            this.renderToolbar();
            this.renderTray();

            return;
        }

        // New columns (a member typed into "+", the round's table usage, its name): a new grid once the edit is done
        if (this.memberCount !== this.wantedMemberCount() || this.tablesUsed !== usesTables(this.model, round) || this.roundName !== round.name) {
            this.scheduleRebuild();
        }

        const ours = new Set(this.model.teamsOf(this.roundId).map((team) => team.id));
        const touched = delta.rounds.has(this.roundId) || [...delta.teams].some((id) => ours.has(id) || this.order.includes(id));
        this.order = keepOrder(this.order, [...ours]);
        const keys = this.rowKeys();
        const rowsChanged = keys.length !== this.grid.rows.length || keys.some((key, index) => this.grid.rows[index] !== key);

        if (rowsChanged) {
            this.grid.setRows(keys);
        }

        const rows = new Set();

        for (const teamId of delta.teams) {
            if (ours.has(teamId)) {
                rows.add(teamId);
            }
        }

        for (const personId of delta.people) {
            const place = parsePlace(this.model.placeValue(personId, this.roundId));

            if (place.kind === 'team') {
                rows.add(place.teamId);
            }
        }

        if (touched) {
            const signature = this.roundSignature();

            if (signature !== this.signature) {
                // The expected size or "names only" changed: every size cell may say something else
                this.signature = signature;
                this.order.forEach((id) => this.grid.updateCell(id, 'size'));
            }

            const sameNames = this.model.sameNameTeams(this.roundId);

            for (const id of new Set([...this.sameNames.keys(), ...sameNames.keys()])) {
                if (JSON.stringify(this.sameNames.get(id) ?? []) !== JSON.stringify(sameNames.get(id) ?? [])) {
                    rows.add(id);
                }
            }

            this.sameNames = new Map(sameNames);
            this.updateRanks();
        }

        // Same-named pairs/teams name each other ("same name as Table 2") - a partner's change shows on both rows
        const partners = this.model.sameNameTeams(this.roundId);

        for (const id of [...rows]) {
            (partners.get(id) ?? []).forEach((other) => rows.add(other));
        }

        rows.delete(NEW_ROW);
        this.grid.updateRows([...rows].filter((id) => ours.has(id)));

        if (rowsChanged && !this.tablesUsed) {
            // The plain row index follows the rows
            keys.forEach((key) => this.grid.updateCell(key, 'table'));
        }

        if (touched || rowsChanged) {
            this.renderToolbar();
            this.renderTray();
        }

        this.refreshOpenEditor(rows);
    }

    focus(target = null) {
        if (this.grid === null) {
            return;
        }

        if (target?.teamId && this.model.team(target.teamId)?.roundId === this.roundId) {
            const slot = target.personId ? this.slotsOf(target.teamId).indexOf(target.personId) : -1;

            if (this.grid.focusCell(target.teamId, slot >= 0 && slot < this.memberCount ? `m${slot}` : 'name')) {
                return;
            }
        }

        if (target?.personId && parsePlace(this.model.placeValue(target.personId, this.roundId)).kind === IN) {
            const chip = this.tray.querySelector(`[data-person="${CSS.escape(target.personId)}"]`);

            if (chip) {
                chip.focus();
                chip.scrollIntoView({ block: 'nearest' });

                return;
            }
        }

        this.grid.focusActive();
    }

    /** Jump to the cell of a problem (the problems panel's "Show"). */
    reveal(problem) {
        const key = problem?.target?.key ?? '';

        if (key.startsWith('team:')) {
            const teamId = key.split(':')[1];

            return this.grid?.focusCell(teamId, 'name') ?? false;
        }

        if (key.startsWith('place:')) {
            const personId = key.split(':')[1];
            const place = parsePlace(this.model.placeValue(personId, this.roundId));
            this.focus({ personId, teamId: place.teamId });

            return true;
        }

        if (key.startsWith('result:')) {
            const field = key.slice(key.lastIndexOf(':') + 1);
            const ref = key.slice('result:'.length, key.lastIndexOf(':'));
            const teamId = ref.startsWith('team:') ? ref.slice(5) : null;

            if (field !== 'table_number' && !this.showResults) {
                this.toggleResults(true);
            }

            return teamId !== null && (this.grid?.focusCell(teamId, field === 'table_number' ? 'table' : field) ?? false);
        }

        if (key.startsWith('round:')) {
            this.toolbar.querySelector('[data-team-size]')?.focus();

            return true;
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
        this.context.root.style.removeProperty('--sheet-round-tray-h');
    }

    on(target, type, handler, options) {
        target.addEventListener(type, handler, options);
        this.listeners.push(() => target.removeEventListener(type, handler, options));
    }

    // ---------------------------------------------------------------- columns, rows, order

    wantedMemberCount() {
        let largest = 0;

        for (const team of this.model.teamsOf(this.roundId)) {
            largest = Math.max(largest, this.model.membersOf(team.id).length);
        }

        return Math.max(this.model.expectedSize(this.roundId) ?? 2, largest, 1);
    }

    roundSignature() {
        return `${this.model.expectedSize(this.roundId)}|${this.model.isNamesOnly(this.roundId)}`;
    }

    buildColumns() {
        const round = this.model.round(this.roundId);
        this.tablesUsed = usesTables(this.model, round);
        this.roundName = round.name;
        const columns = [
            this.tablesUsed
                ? { key: 'table', label: this.t('col_table'), kind: 'list', width: 72, autoHighlight: false, className: 'sheet-col-table' }
                : { key: 'table', label: this.t('col_index'), kind: 'readonly', width: 52, className: 'sheet-col-table' },
            { key: 'name', label: this.tk('col_name'), kind: 'text', width: 180 },
        ];

        for (let index = 0; index < this.memberCount; index++) {
            columns.push({ key: `m${index}`, label: this.t('col_member', { number: index + 1 }), kind: 'list', width: 175, className: 'sheet-col-member' });
        }

        columns.push({
            key: 'mplus',
            label: this.t('col_member_extra', { number: this.memberCount + 1 }),
            headerHtml: `<span aria-hidden="true">+</span><span class="visually-hidden">${escapeHtml(this.t('col_member_extra', { number: this.memberCount + 1 }))}</span>`,
            kind: 'list',
            width: 100,
            className: 'sheet-col-member sheet-col-extra',
        });
        columns.push({ key: 'size', label: this.t('col_size'), kind: 'readonly', width: 215, className: 'sheet-col-size' });

        if (this.showResults) {
            columns.push(
                { key: 'result', label: this.t('col_result'), kind: 'list', width: 150, autoHighlight: false, className: 'sheet-col-result' },
                { key: 'rank', label: this.t('col_rank'), kind: 'readonly', width: 58, className: 'sheet-col-rank' },
                { key: 'qualified', label: this.t('col_qualified'), kind: 'checkbox', width: 92 },
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
        let ids = this.order.filter((id) => this.model.team(id)?.roundId === this.roundId);

        if (this.filter !== null) {
            ids = ids.filter((id) => this.matchesFilter(id));
        }

        // The plain row index ("#") of rounds without table numbers
        this.indexByRow = new Map(ids.map((id, index) => [id, index + 1]));

        return [...ids, NEW_ROW];
    }

    matchesFilter(teamId) {
        const status = this.model.sizeStatus(teamId).status;

        switch (this.filter) {
            case 'incomplete':
                return status === 'incomplete';
            case 'tooMany':
                return status === 'too_many';
            case 'sameName':
                return this.model.sameNameTeams(this.roundId).has(teamId);
            default:
                return true;
        }
    }

    rowClass(row) {
        if (row === NEW_ROW) {
            return 'sheet-row-new';
        }

        return this.model.team(row)?.local ? 'sheet-row-local' : '';
    }

    /** The members of a pair/team in the grid's columns (stable while the organiser works - memberSlots()). */
    slotsOf(teamId) {
        const members = this.model.membersOf(teamId).map((person) => person.id);
        const intent = this.intents.get(teamId) ?? null;
        const slots = memberSlots(this.slots.get(teamId), members, intent);
        this.slots.set(teamId, slots);

        if (intent !== null && slots.includes(intent.personId)) {
            this.intents.delete(teamId);
        }

        return slots;
    }

    memberIndex(col) {
        return col === 'mplus' ? this.memberCount : Number(col.slice(1));
    }

    isMemberColumn(col) {
        return col === 'mplus' || /^m\d+$/.test(col);
    }

    entryOf(teamId) {
        const team = this.model.team(teamId);

        if (team === null) {
            return null;
        }

        const ref = `team:${teamId}`;
        const pending = this.results.pending();
        const table = pending.value(ref, 'table_number', team.table);

        return {
            id: teamId,
            ref,
            displayName: team.name ?? (this.model.membersOf(teamId).map((person) => person.name).join(', ') || this.core.t('team_no_name')),
            table,
            tableNumber: table,
            serverTable: team.table,
            result: pending.value(ref, 'result', team.result),
            serverResult: team.result,
            qualified: pending.value(ref, 'qualified', team.qualified) === true,
            serverQualified: team.qualified === true,
        };
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

        for (const id of new Set([...this.ranks.keys(), ...previous.keys()])) {
            if (this.ranks.get(id) !== previous.get(id)) {
                this.grid.updateCell(id, 'rank');
            }
        }
    }

    // ---------------------------------------------------------------- cells

    cell(row, col) {
        if (row === NEW_ROW) {
            return this.newRowCell(col);
        }

        const team = this.model.team(row);

        if (team === null) {
            return { text: '', readonly: true };
        }

        const ref = `team:${team.id}`;

        if (col === 'table') {
            if (!this.tablesUsed) {
                return { text: String(this.indexByRow?.get(row) ?? ''), readonly: true, className: 'sheet-col-table' };
            }

            return this.results.tableCell(ref, team.table);
        }

        if (col === 'name') {
            const marker = this.context.markerFor(`team:${team.id}:name`) ?? this.context.markerFor(`team:${team.id}:delete`);

            if (team.name === null) {
                return { text: '', html: `<span class="sheet-muted">${escapeHtml(this.core.t('team_no_name'))}</span>`, copy: '', marker };
            }

            return { text: team.name, marker };
        }

        if (this.isMemberColumn(col)) {
            const personId = this.slotsOf(team.id)[this.memberIndex(col)] ?? null;

            if (personId === null) {
                return { text: '', copy: '', className: 'sheet-col-member' };
            }

            return this.memberCell(personId);
        }

        if (col === 'size') {
            return this.sizeCell(team.id);
        }

        if (col === 'result') {
            return this.results.resultCell(ref, team.result, team.enteredBy, team.enteredAt);
        }

        if (col === 'rank') {
            const entry = this.entryOf(team.id);

            return this.results.rankCell(ref, this.ranks.get(team.id) ?? null, entry.result, team.result);
        }

        if (col === 'qualified') {
            return this.results.qualifiedCell(ref, team.qualified, this.t('qualified_label', { team: teamShortLabel(this.model, team.id, this.texts) }));
        }

        if (col === 'actions') {
            const label = this.t('row_actions_label', { team: teamShortLabel(this.model, team.id, this.texts) });

            return { text: '', html: `<i class="bi bi-three-dots" aria-hidden="true"></i><span class="visually-hidden">${escapeHtml(label)}</span>`, copy: '', className: 'sheet-col-actions' };
        }

        return { text: '', readonly: true };
    }

    newRowCell(col) {
        if (col === 'name') {
            return { text: '', html: `<span class="sheet-placeholder"><i class="bi bi-plus-lg" aria-hidden="true"></i> ${escapeHtml(this.tk('new_row'))}</span>`, copy: '' };
        }

        if (col === 'm0') {
            return { text: '', html: `<span class="sheet-placeholder">${escapeHtml(this.t('new_row_member'))}</span>`, copy: '' };
        }

        if (this.isMemberColumn(col)) {
            return { text: '', copy: '' };
        }

        if (col === 'table') {
            return { text: '', html: '<span class="sheet-placeholder" aria-hidden="true">+</span>', copy: '', readonly: true, kind: 'readonly' };
        }

        return { text: '', copy: '', readonly: true, kind: 'readonly' };
    }

    memberCell(personId) {
        const person = this.model.person(personId);
        const badge = this.model.isWaitlisted(personId) ? `<span class="sheet-badge sheet-badge-waitlist">${escapeHtml(this.t('waitlisted'))}</span>` : '';

        return {
            text: person?.name ?? '',
            html: `${flagHtml(person?.country)}${escapeHtml(person?.name ?? '')}${badge}`,
            copy: person?.name ?? '',
            marker: this.context.markerFor(`place:${personId}:${this.roundId}`),
            className: 'sheet-col-member',
        };
    }

    sizeCell(teamId) {
        const info = sizeInfo(this.model, teamId, this.texts);
        const text = [info.text, info.sameAs].filter(Boolean).join(' · ');
        const icon = info.warn ? '<i class="bi bi-exclamation-triangle" aria-hidden="true"></i> ' : '';

        return {
            text,
            // The whole text as a title too: the column is narrow for "same name as the pair with …"
            html: `<span class="${info.warn ? 'sheet-attention' : (info.status === 'complete' ? '' : 'sheet-muted')}"${info.sameAs ? ` title="${escapeHtml(text)}"` : ''}>${icon}${escapeHtml(info.text)}</span>${info.sameAs ? ` <span class="sheet-muted" title="${escapeHtml(text)}">· ${escapeHtml(info.sameAs)}</span>` : ''}`,
            readonly: true,
            className: 'sheet-col-size',
        };
    }

    editValue(row, col) {
        if (row === NEW_ROW) {
            return '';
        }

        const team = this.model.team(row);

        if (team === null) {
            return '';
        }

        if (col === 'name') {
            return team.name ?? '';
        }

        if (this.isMemberColumn(col)) {
            const personId = this.slotsOf(row)[this.memberIndex(col)] ?? null;

            return personId ? (this.model.person(personId)?.name ?? '') : '';
        }

        if (col === 'table') {
            const entry = this.entryOf(row);

            return entry?.table === null || entry?.table === undefined ? '' : String(entry.table);
        }

        if (col === 'result') {
            return resultEditText(this.entryOf(row)?.result ?? null);
        }

        return '';
    }

    /**
     * An edit began: what the cell showed is remembered - the `from` of its save, never the model's value at commit time
     * (a change somebody else made meanwhile comes back as a conflict instead of being overwritten). Official fields
     * follow the results desk's openEditor() (the organiser's own waiting value counts as seen). Returns the value
     * (the grid's `seenValue`).
     */
    captureSeen(row, col) {
        this.editor = null;
        this.sheetEditor = null;
        const team = row === NEW_ROW ? null : this.model.team(row);

        if (col === 'table' || col === 'result') {
            if (team === null || (col === 'table' && !this.tablesUsed)) {
                return null;
            }

            const field = col === 'table' ? 'table_number' : 'result';
            this.editor = { row, col, ...this.results.openEditor(`team:${row}`, field, field === 'result' ? team.result : team.table) };

            return this.editor.seen;
        }

        const seen = this.sheetValue(row, col);
        this.sheetEditor = { row, col, seen };

        return seen;
    }

    /** A name / member cell's value as the model shows it: the team's name, the person id in the member column. */
    sheetValue(row, col) {
        const team = row === NEW_ROW ? null : this.model.team(row);

        if (team === null) {
            return null;
        }

        if (col === 'name') {
            return team.name;
        }

        return this.isMemberColumn(col) ? (this.slotsOf(row)[this.memberIndex(col)] ?? null) : null;
    }

    editorFor(row, col) {
        return this.editor !== null && this.editor.row === row && this.editor.col === col ? this.editor : null;
    }

    /** What the cell showed when its editor opened (the grid's `seen`, else the view's own record). */
    seenOf(row, col, extra) {
        if (this.sheetEditor !== null && this.sheetEditor.row === row && this.sheetEditor.col === col) {
            return this.sheetEditor.seen;
        }

        return extra?.seen !== undefined ? extra.seen : this.sheetValue(row, col);
    }

    /**
     * A change reached the cell being edited: results cells show "Saved meanwhile by …" in their list, name and member
     * cells a notice next to the editor ("Changed meanwhile to … · Keep mine / Use theirs").
     */
    refreshOpenEditor(rows) {
        const editing = this.grid?.editing;

        if (!editing || !rows.has(editing.row)) {
            return;
        }

        const team = this.model.team(editing.row);

        if (team === null) {
            return;
        }

        if (editing.col === 'result' || editing.col === 'table') {
            const editor = this.editorFor(editing.row, editing.col);

            if (editor !== null && this.results.meanwhile(editor, editing.col === 'result' ? team.result : team.table, team.enteredBy) !== null) {
                this.grid.openList(true);
            }

            return;
        }

        const own = this.sheetEditor;

        if (own === null || own.row !== editing.row || own.col !== editing.col || typeof this.grid.editorNotice !== 'function') {
            return;
        }

        const now = this.sheetValue(editing.row, editing.col);

        if (now === own.seen) {
            return;
        }

        const shown = editing.col === 'name' ? (now ?? this.core.t('team_no_name')) : (now === null ? this.t('member_empty') : (this.model.person(now)?.name ?? ''));
        this.grid.editorNotice({
            text: this.t('changed_meanwhile', { value: shown }),
            actions: [
                {
                    label: this.t('meanwhile_keep_mine'),
                    run: () => {
                        // The organiser has seen the other value: their save goes over it
                        own.seen = now;
                    },
                },
                { label: this.t('meanwhile_use_theirs'), run: () => this.grid.cancelEdit(true) },
            ],
        });
    }

    // ---------------------------------------------------------------- suggestions

    suggest(row, col, query, info = {}) {
        if (this.isMemberColumn(col)) {
            const teamId = row === NEW_ROW ? null : row;
            const options = personOptions(this.model, this.roundId, query, this.texts, { teamId, countries: this.context.countries });

            return { options, hint: options.length === 0 && query.trim() === '' ? this.t('member_hint') : '' };
        }

        if (row === NEW_ROW) {
            return [];
        }

        const team = this.model.team(row);

        if (col === 'result' && team !== null) {
            return this.results.resultSuggest(this.editorFor(row, col), team.result, team.enteredBy, query, info.explicit === true);
        }

        if (col === 'table' && team !== null) {
            return this.results.tableSuggest(this.editorFor(row, col), this.entryOf(row), query);
        }

        return [];
    }

    // ---------------------------------------------------------------- editing

    options() {
        return { countries: this.context.countryCodes };
    }

    reasonText(error) {
        const code = error?.reason ?? 'invalid_change';
        const change = error?.change ?? {};
        const person = this.model.person(change.participant ?? change.id ?? '');
        const teamId = change.team ?? parsePlace(change.to ?? '').teamId ?? null;
        const params = {
            name: person?.name ?? change.name ?? '',
            round: this.model.round(change.round ?? this.roundId)?.name ?? '',
            team: teamId ? teamShortLabel(this.model, teamId, this.texts) : '',
            max: 255,
            min: TEAM_SIZE_MIN,
            other: '',
        };

        if (code === 'invalid_team_size') {
            params.max = TEAM_SIZE_MAX;
        }

        return this.core.has(`reason_${code}`) ? this.core.t(`reason_${code}`, params) : this.context.reasonText(code);
    }

    /** An action performed through the controller; a client refusal of everything comes back as the editor's error. */
    perform(action) {
        const blocked = action.groups.length === 0 && (action.results ?? []).length === 0 && action.errors.length > 0;
        const outcome = this.context.act(action, { quiet: blocked });

        if (blocked) {
            return { error: this.reasonText(outcome.errors[0]) };
        }

        if (outcome.errors.length > 0) {
            this.context.announce(this.reasonText(outcome.errors[0]));
        }

        return undefined;
    }

    /** Enter landing on the new row starts the next pair/team at its name. */
    focusAfter() {
        return (move) => (this.grid.rows[move.row] === NEW_ROW ? { row: NEW_ROW, col: 'name' } : null);
    }

    /**
     * After a pair/team was made in the new row: Enter (the same column) = the new row again, the next one starts;
     * Tab = the new pair's/team's next cell.
     */
    focusAfterCreate(col, teamId) {
        return (move) => {
            const key = this.columns[move.col]?.key ?? 'name';

            return key === col ? { row: NEW_ROW, col: 'name' } : { row: teamId, col: key };
        };
    }

    commit(row, col, input, extra = {}) {
        if (col === 'name') {
            return this.commitName(row, input, this.seenOf(row, col, extra));
        }

        if (this.isMemberColumn(col)) {
            return this.commitMember(row, col, input, this.seenOf(row, col, extra));
        }

        if (col === 'table' && row !== NEW_ROW) {
            return this.commitTable(row, input);
        }

        if (col === 'result' && row !== NEW_ROW) {
            return this.commitResult(row, input);
        }

        return undefined;
    }

    commitName(row, input, seen) {
        if (row === NEW_ROW) {
            const name = cleanTeamName(input.text);

            if (name === null) {
                return undefined;
            }

            const action = newTeamRow(this.model, this.roundId, { name }, this.options());
            const error = this.perform(action);

            if (error) {
                return error;
            }

            this.announceSharedName(action.teamId, name);
            this.context.announce(this.tk('created', { name }));

            return { focus: this.focusAfterCreate('name', action.teamId) };
        }

        const to = cleanTeamName(input.text);

        if (to === seen || this.model.team(row) === null) {
            return undefined;
        }

        // `from` = the name the organiser saw - a rename by somebody else meanwhile comes back as a conflict
        const error = this.perform(buildAction(this.model, [[{ op: 'renameTeam', team: row, from: seen, to }]], { label: { key: 'rename_team' }, ...this.options() }));

        if (!error) {
            this.announceSharedName(row, to);
        }

        return error ?? { focus: this.focusAfter() };
    }

    announceSharedName(teamId, name) {
        if (name === null) {
            return;
        }

        const others = this.model.sameNameTeams(this.roundId).get(teamId) ?? [];

        if (others.length > 0) {
            this.context.announce(this.t('same_name_announce', { name, other: teamLabelText(this.model, others[0], this.texts) }));
        }
    }

    /** The person a member cell means: an id, a new person {name, country}, null (cleared) or {error}. */
    memberFrom(input) {
        const option = input.option;

        if (option?.create) {
            return { name: option.name ?? input.text.trim(), country: null };
        }

        if (option?.personId) {
            return option.personId;
        }

        const text = input.text.trim();

        if (text === '') {
            return null;
        }

        const found = matchPerson(this.model, text, this.roundId);

        if (found.status === 'one') {
            return found.ids[0];
        }

        return { error: this.t(found.status === 'several' ? 'member_ambiguous' : 'member_unknown', { name: cleanName(text) }) };
    }

    commitMember(row, col, input, seen = null) {
        const index = this.memberIndex(col);
        const teamId = row === NEW_ROW ? null : row;
        const team = teamId === null ? null : this.model.team(teamId);
        // The person the cell showed when the edit began: the one who leaves (from = this pair/team)
        const current = team === null ? null : (seen ?? null);
        const member = this.memberFrom(input);

        if (member !== null && typeof member === 'object' && member.error) {
            return { error: member.error };
        }

        if (member === null) {
            if (current === null) {
                return undefined;
            }

            if (hasOfficialData(team) && this.model.membersOf(teamId).length <= 1) {
                return { error: this.reasonText({ reason: 'team_has_result', change: { team: teamId } }) };
            }

            const cleared = buildAction(this.model, [[{ op: 'place', participant: current, round: this.roundId, from: teamPlace(teamId), to: IN }]], { label: { key: 'clear_member' }, ...this.options() });
            const error = this.perform(cleared);

            if (!error) {
                this.context.announce(this.tk('member_to_tray', { name: this.model.person(current)?.name ?? '' }));
            }

            return error ?? { focus: this.focusAfter() };
        }

        if (member === current) {
            return undefined;
        }

        if (typeof member === 'string') {
            const from = parsePlace(this.model.placeValue(member, this.roundId));
            const fromTeam = from.kind === 'team' ? this.model.team(from.teamId) : null;

            if (fromTeam !== null && from.teamId !== teamId && hasOfficialData(fromTeam) && this.model.membersOf(from.teamId).length <= 1) {
                return { error: this.reasonText({ reason: 'team_has_result', change: { team: from.teamId } }) };
            }
        }

        const moveText = typeof member === 'string' ? this.moveAnnouncement(member, teamId) : '';

        if (teamId === null) {
            const action = newTeamRow(this.model, this.roundId, { members: [member] }, this.options());
            const personId = typeof member === 'string' ? member : action.groups[0]?.changes.find((change) => change.op === 'newParticipant')?.id;

            if (personId) {
                this.intents.set(action.teamId, { personId, index: 0 });
            }

            const error = this.perform(action);

            if (error) {
                return error;
            }

            this.context.announce([this.tk('created_with', { name: this.personName(member) }), moveText].filter(Boolean).join(' '));

            return { focus: this.focusAfterMember(col, action.teamId, true) };
        }

        const changes = [];
        let personId = member;

        if (typeof member !== 'string') {
            personId = newClientId();
            changes.push({ op: 'newParticipant', id: personId, name: cleanName(member.name), country: member.country ?? null, externalId: null });
        }

        changes.push({ op: 'place', participant: personId, round: this.roundId, from: this.model.placeValue(personId, this.roundId), to: teamPlace(teamId) });

        if (current !== null) {
            changes.push({ op: 'place', participant: current, round: this.roundId, from: teamPlace(teamId), to: IN });
        }

        this.intents.set(teamId, { personId, index });
        const error = this.perform(buildAction(this.model, [changes], { label: { key: 'put_in_team' }, ...this.options() }));

        if (error) {
            this.intents.delete(teamId);

            return error;
        }

        this.context.announce([moveText, current !== null ? this.tk('member_to_tray', { name: this.model.person(current)?.name ?? '' }) : ''].filter(Boolean).join(' '));

        return { focus: this.focusAfterMember(col, teamId) };
    }

    /**
     * After a member was typed: Tab goes where the grid planned; Enter goes on with the same pair/team while it is short
     * of people (its next empty member column; always in a team round without a set size), then to the new row for the
     * next one - entering pairs is "person, Enter, person, Enter".
     */
    focusAfterMember(col, teamId, created = false) {
        return (move) => {
            const key = this.columns[move.col]?.key;

            if (key !== col) {
                // Tab: the grid's plan - for a pair/team just made in the new row, its row (the rows moved)
                return created && this.model.team(teamId) !== null && key !== undefined ? { row: teamId, col: key } : null;
            }

            const team = this.model.team(teamId);
            const count = team === null ? 0 : this.model.membersOf(teamId).length;
            const round = this.model.round(this.roundId);
            // A team round without a set size: its size is not known - Enter goes on in the team (Enter on an empty
            // member cell leaves it)
            const unknown = round?.category === 'team' && !Number.isInteger(round.teamSize);
            const expected = this.model.expectedSize(this.roundId) ?? 2;

            if (team !== null && (count < expected || unknown)) {
                return { row: teamId, col: count < this.memberCount ? `m${count}` : 'mplus' };
            }

            return { row: NEW_ROW, col: team?.name ? 'name' : 'm0' };
        };
    }

    personName(member) {
        return typeof member === 'string' ? (this.model.person(member)?.name ?? '') : cleanName(member.name);
    }

    moveAnnouncement(personId, teamId) {
        const where = whereInRound(this.model, personId, this.roundId, this.texts);

        return where.kind === 'team' && where.teamId !== teamId ? this.t('moves_announce', { name: this.personName(personId), where: where.text }) : '';
    }

    commitTable(row, input) {
        const answer = this.results.tableCommit(this.editorFor(row, 'table'), this.entryOf(row), input);

        return this.afterOfficialCommit(row, answer);
    }

    commitResult(row, input) {
        const team = this.model.team(row);
        const answer = this.results.resultCommit(this.editorFor(row, 'result'), team?.result ?? null, team?.enteredBy ?? null, input);

        return this.afterOfficialCommit(row, answer);
    }

    afterOfficialCommit(row, answer) {
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

        return this.perform(answer.action) ?? { focus: this.focusAfter() };
    }

    toggle(cells, value) {
        const entries = cells.filter((cell) => cell.col === 'qualified' && cell.row !== NEW_ROW).map((cell) => this.entryOf(cell.row));
        const action = this.results.qualifiedAction(entries, value);

        if (!isEmpty(action)) {
            this.context.act(action);
        }
    }

    clear(cells) {
        const actions = [];
        const results = [];

        for (const { row, col } of cells) {
            if (row === NEW_ROW) {
                continue;
            }

            const team = this.model.team(row);

            if (team === null) {
                continue;
            }

            if (col === 'name') {
                actions.push(renameTeam(this.model, row, null, this.options()));
            } else if (this.isMemberColumn(col)) {
                const personId = this.slotsOf(row)[this.memberIndex(col)] ?? null;

                if (personId !== null) {
                    actions.push({ personId, teamId: row });
                }
            } else if (col === 'table' && this.tablesUsed) {
                const entry = this.entryOf(row);

                if (entry.table !== null) {
                    results.push({ roundId: this.roundId, ref: entry.ref, field: 'table_number', from: entry.table, to: null });
                }
            } else if (col === 'result') {
                const entry = this.entryOf(row);

                if (entry.result !== null) {
                    results.push({ roundId: this.roundId, ref: entry.ref, field: 'result', from: entry.result, to: null });
                }
            } else if (col === 'qualified') {
                const entry = this.entryOf(row);

                if (entry.qualified) {
                    results.push({ roundId: this.roundId, ref: entry.ref, field: 'qualified', from: true, to: false });
                }
            }
        }

        // Members cleared together: one group per pair/team, so a pair/team with a result is never emptied
        const byTeam = new Map();

        for (const item of actions.filter((action) => action.personId)) {
            byTeam.set(item.teamId, [...(byTeam.get(item.teamId) ?? []), item.personId]);
        }

        const groups = [];

        for (const [teamId, people] of byTeam) {
            const team = this.model.team(teamId);

            if (hasOfficialData(team) && people.length >= this.model.membersOf(teamId).length) {
                this.context.announce(this.reasonText({ reason: 'team_has_result', change: { team: teamId } }));
                continue;
            }

            groups.push(people.map((personId) => ({ op: 'place', participant: personId, round: this.roundId, from: teamPlace(teamId), to: IN })));
        }

        const combined = combine(
            { key: 'clear' },
            ...actions.filter((action) => !action.personId),
            buildAction(this.model, groups, { label: { key: 'clear_member' }, ...this.options() }),
            results.length > 0 ? officialEdits(results) : null,
        );

        if (!isEmpty(combined) || combined.errors.length > 0) {
            this.context.act(combined);
        }
    }

    // ---------------------------------------------------------------- row actions

    activate(row, col) {
        if (row !== NEW_ROW && col === 'actions') {
            this.openRowMenu(row, this.grid.cellElement(row, 'actions'));
        }
    }

    onContextMenu(event) {
        const position = this.grid?.positionOf(event.target);

        if (!position || position.row === NEW_ROW || this.grid.isEditing()) {
            return;
        }

        event.preventDefault();
        this.grid.focusCell(position.row, position.col);
        this.openRowMenu(position.row, this.grid.cellElement(position.row, position.col));
    }

    async openRowMenu(teamId, anchor) {
        const team = this.model.team(teamId);

        if (team === null) {
            return;
        }

        const label = teamShortLabel(this.model, teamId, this.texts);
        const official = hasOfficialData(team);
        const membersHold = this.model.membersOf(teamId).find((person) => this.model.holdsDataInRound(person.id, this.roundId)) ?? null;
        const items = [
            { value: 'rename', label: this.tk('menu_rename'), icon: 'bi-pencil' },
            { value: 'delete', label: this.tk('menu_delete'), icon: 'bi-trash', detail: this.tk('menu_delete_detail'), disabled: official, reason: official ? this.reasonText({ reason: 'team_has_result', change: { team: teamId } }) : '' },
            {
                value: 'out',
                label: this.tk('menu_take_out'),
                icon: 'bi-box-arrow-right',
                danger: true,
                disabled: official || membersHold !== null,
                reason: official ? this.reasonText({ reason: 'team_has_result', change: { team: teamId } }) : (membersHold ? this.reasonText({ reason: 'has_result_in_round', change: { participant: membersHold.id, round: this.roundId } }) : ''),
            },
        ];
        const dialog = this.openDialog({
            title: this.t('menu_title', { team: label }),
            items,
            anchor,
            returnFocus: () => {
                if (this.grid && !this.grid.focusCell(teamId, this.grid.active.col)) {
                    this.grid.focusActive();
                }
            },
            onDisabled: (item) => this.context.announce(item.reason),
        });
        const choice = await dialog.result;

        if (choice === null) {
            return;
        }

        if (choice.value === 'rename') {
            if (this.grid.focusCell(teamId, 'name')) {
                this.grid.startEdit(false);
            }
        } else if (choice.value === 'delete') {
            this.perform(deleteTeam(this.model, teamId, this.options()));
            this.context.announce(this.tk('deleted', { team: label }));
        } else if (choice.value === 'out') {
            this.takeTeamOut(teamId, label);
        }
    }

    /** The whole pair/team out of the round: deleted, its members out of the round. */
    takeTeamOut(teamId, label) {
        const members = this.model.membersOf(teamId).map((person) => person.id);
        const changes = [{ op: 'deleteTeam', team: teamId }, ...members.map((personId) => ({ op: 'place', participant: personId, round: this.roundId, from: IN, to: OUT }))];
        const action = buildAction(this.model, [changes], { label: { key: 'round_out' }, ...this.options() });
        const error = this.perform(action);
        this.context.announce(error ? error.error : this.tk('taken_out', { team: label }));
    }

    openDialog(options) {
        this.dialog?.close();
        this.dialog = new RoundDialog({ host: this.context.root, closeLabel: this.t('dialog_close'), ...options }).open();

        return this.dialog;
    }

    // ---------------------------------------------------------------- toolbar

    renderToolbar() {
        const round = this.model.round(this.roundId);

        if (round === null) {
            return;
        }

        const problems = this.model.problems(this.roundId);
        const teams = this.model.teamsOf(this.roundId).length;
        const people = this.model.peopleIn(this.roundId).length;
        const filters = [
            ['incomplete', problems.incomplete, this.tc('filter_incomplete', problems.incomplete), true],
            ['tooMany', problems.tooMany, this.tc('filter_too_many', problems.tooMany), true],
            ['without', problems.withoutTeam, this.tc(this.duo() ? 'filter_without_pair' : 'filter_without_team', problems.withoutTeam), true],
            ['sameName', problems.sameName, this.tc('filter_same_name', problems.sameName), false],
        ].filter(([, count]) => count > 0);
        const filterHtml = filters.map(([key, , text, warn]) => `<button type="button" class="btn btn-sm sheet-round-filter${this.filter === key ? ' active' : ''}" data-filter="${key}" aria-pressed="${this.filter === key ? 'true' : 'false'}">${warn ? '<i class="bi bi-exclamation-triangle" aria-hidden="true"></i> ' : ''}${escapeHtml(text)}</button>`).join('');
        const sizeMarker = this.context.markerFor(`round:${this.roundId}:teamSize`);
        const teamSize = round.category === 'team' ? `<label class="sheet-round-size">${escapeHtml(this.t('team_size_label'))}
                <select class="form-select form-select-sm" data-team-size aria-describedby="sheet-team-size-hint-${escapeHtml(this.roundId)}">
                    <option value=""${round.teamSize === null ? ' selected' : ''}>${escapeHtml(this.t('team_size_not_set'))}</option>
                    ${Array.from({ length: TEAM_SIZE_MAX - TEAM_SIZE_MIN + 1 }, (_, index) => index + TEAM_SIZE_MIN).map((size) => `<option value="${size}"${round.teamSize === size ? ' selected' : ''}>${size}</option>`).join('')}
                </select></label><span class="sheet-round-hint small" id="sheet-team-size-hint-${escapeHtml(this.roundId)}">${escapeHtml(round.teamSize === null ? this.t('team_size_guess', { size: this.model.usualTeamSize(this.roundId) }) : '')}</span>${markerHtml(sizeMarker)}` : '';
        const links = this.linksHtml(round);
        const html = `<div class="sheet-round-summary">
                <span class="sheet-round-swatch" style="background-color:${escapeHtml(safeColor(round.color))}" aria-hidden="true"></span>
                <strong>${escapeHtml(round.name)}</strong>
                <span>${escapeHtml(this.core.tc(this.duo() ? 'tab_count_pairs' : 'tab_count_teams', teams))} · ${escapeHtml(this.core.tc('tab_count_people', people))}</span>
            </div>
            ${filterHtml ? `<div class="sheet-round-filters" role="group" aria-label="${escapeHtml(this.t('filters_label'))}">${filterHtml}${this.filter !== null ? `<button type="button" class="btn btn-sm btn-link" data-filter="">${escapeHtml(this.t('filter_clear'))}</button>` : ''}</div>` : ''}
            ${teamSize ? `<div class="sheet-round-size-wrap">${teamSize}</div>` : ''}
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

    linksHtml(round) {
        const urls = round.urls ?? {};
        const links = [
            [urls.liveEntry, 'link_live_entry', 'bi-broadcast'],
            [urls.resultsDesk, 'link_results_desk', 'bi-table'],
            [this.tablesUsed ? urls.seating : null, 'link_seating', 'bi-grid-3x3-gap'],
        ].filter(([url]) => typeof url === 'string' && url !== '');

        return links.map(([url, key, icon]) => `<a class="btn btn-sm btn-link" href="${escapeHtml(url)}" target="_blank" rel="noopener"><i class="bi ${icon}" aria-hidden="true"></i> ${escapeHtml(this.t(key))}<span class="visually-hidden"> ${escapeHtml(this.t('new_tab'))}</span></a>`).join('');
    }

    onToolbarClick(event) {
        const filter = event.target.closest('[data-filter]');

        if (filter) {
            const key = filter.dataset.filter || null;

            if (key === 'without') {
                const chip = this.tray.querySelector('[data-person]');
                chip?.scrollIntoView({ block: 'nearest' });
                chip?.focus();

                return;
            }

            this.filter = this.filter === key ? null : key;
            this.grid.setRows(this.rowKeys());
            this.renderToolbar();
            this.context.announce(this.filter === null ? this.t('filter_cleared') : this.tc('filter_shown', this.grid.rows.length - 1));

            return;
        }

        if (event.target.closest('[data-sort]')) {
            this.order = sortedTeamIds(this.model, this.roundId, this.collator);
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

    onToolbarChange(event) {
        const select = event.target.closest('[data-team-size]');

        if (select) {
            const value = select.value === '' ? null : Number(select.value);
            const error = this.perform(setTeamSize(this.model, this.roundId, value, this.options()));

            if (error) {
                this.context.announce(error.error);
            }
        }
    }

    // ---------------------------------------------------------------- the tray

    renderTray() {
        const tray = this.model.trayOf(this.roundId);
        const titleKey = this.duo() ? 'tray_title_pair' : 'tray_title_team';
        const chips = tray.map((person) => {
            const marker = this.context.markerFor(`place:${person.id}:${this.roundId}`);
            const waitlisted = this.model.isWaitlisted(person.id) ? ` <span class="sheet-badge sheet-badge-waitlist">${escapeHtml(this.t('waitlisted'))}</span>` : '';

            return `<li><button type="button" class="sheet-chip" data-person="${escapeHtml(person.id)}" aria-haspopup="dialog">${flagHtml(person.country)}${escapeHtml(person.name)}${waitlisted}${markerHtml(marker)}</button></li>`;
        }).join('');
        const html = `<h2 class="h6 sheet-round-tray-title" id="sheet-tray-title-${escapeHtml(this.roundId)}">${escapeHtml(this.tc(titleKey, tray.length))}</h2>
            ${tray.length > 0 ? `<ul class="sheet-chips list-unstyled">${chips}</ul>` : `<p class="sheet-muted small mb-2">${escapeHtml(this.tk('tray_empty'))}</p>`}
            <button type="button" class="btn btn-sm btn-outline-secondary" data-tray-add aria-haspopup="dialog"><i class="bi bi-person-plus" aria-hidden="true"></i> ${escapeHtml(this.t('add_people'))}</button>`;

        if (this.tray.dataset.html !== html) {
            const focused = this.tray.contains(document.activeElement) ? focusKey(document.activeElement) : null;
            this.tray.innerHTML = html;
            this.tray.dataset.html = html;

            if (focused) {
                (this.tray.querySelector(focused) ?? this.tray.querySelector('[data-person]') ?? this.tray.querySelector('[data-tray-add]'))?.focus();
            }
        }

        this.fitTray();
    }

    /** The grid's scroller leaves room for the tray below it (the scroller fills the viewport otherwise). */
    fitTray() {
        this.context.root.style.setProperty('--sheet-round-tray-h', `${Math.min(this.tray.offsetHeight, 240)}px`);
    }

    onTrayClick(event) {
        const chip = event.target.closest('[data-person]');

        if (chip) {
            this.openChipMenu(chip.dataset.person, chip);

            return;
        }

        const add = event.target.closest('[data-tray-add]');

        if (add) {
            this.addPeople(add);
        }
    }

    async openChipMenu(personId, anchor) {
        const person = this.model.person(personId);

        if (person === null) {
            return;
        }

        const holds = this.model.holdsDataInRound(personId, this.roundId);
        const items = [
            { value: 'pair', label: this.tk('chip_pair_with'), icon: 'bi-people' },
            { value: 'add', label: this.t('chip_add_to'), icon: 'bi-box-arrow-in-right', disabled: this.model.teamsOf(this.roundId).length === 0, reason: this.model.teamsOf(this.roundId).length === 0 ? this.tk('chip_add_to_none') : '' },
            { value: 'new', label: this.tk('chip_new'), icon: 'bi-plus-lg' },
            { value: 'out', label: this.t('chip_out'), icon: 'bi-box-arrow-right', danger: true, disabled: holds, reason: holds ? this.reasonText({ reason: 'has_result_in_round', change: { participant: personId, round: this.roundId } }) : '' },
        ];
        const choice = await this.openDialog({
            title: person.name,
            description: this.tk('chip_description'),
            items,
            anchor,
            returnFocus: () => this.focusTrayAfter(personId),
            onDisabled: (item) => this.context.announce(item.reason),
        }).result;

        if (choice === null) {
            return;
        }

        if (choice.value === 'pair') {
            this.pairWith(personId, anchor);
        } else if (choice.value === 'add') {
            this.addToTeam(personId, anchor);
        } else if (choice.value === 'new') {
            const action = newTeamRow(this.model, this.roundId, { members: [personId] }, this.options());
            this.perform(action);
            this.context.announce(this.tk('created_with', { name: person.name }));
        } else if (choice.value === 'out') {
            const error = this.perform(setPlace(this.model, personId, this.roundId, OUT, { label: { key: 'round_out' }, ...this.options() }));
            this.context.announce(error ? error.error : this.t('taken_out_person', { name: person.name }));
        }
    }

    /** After a tray action: the same chip if it is still there, else the next one, else "Add people". */
    focusTrayAfter(personId) {
        const chip = this.tray.querySelector(`[data-person="${CSS.escape(personId)}"]`) ?? this.tray.querySelector('[data-person]') ?? this.tray.querySelector('[data-tray-add]');
        chip?.focus({ preventScroll: true });
    }

    async pairWith(personId, anchor) {
        const person = this.model.person(personId);
        const picked = await this.openDialog({
            title: this.tk('pair_with_title', { name: person?.name ?? '' }),
            anchor,
            picker: {
                label: this.t('search_people'),
                placeholder: this.t('search_people_placeholder'),
                empty: this.tk('pair_with_empty'),
                none: this.t('no_matches'),
                options: (query) => personOptions(this.model, this.roundId, query, this.texts, { only: 'tray', exclude: new Set([personId]), create: false, countries: this.context.countries }).map((option) => ({ ...option, detail: '' })),
            },
            returnFocus: () => this.focusTrayAfter(personId),
        }).result;

        if (picked?.personId) {
            const action = newTeamRow(this.model, this.roundId, { members: [personId, picked.personId] }, this.options());
            this.perform(action);
            this.context.announce(this.tk('paired', { first: person?.name ?? '', second: picked.label }));
        }
    }

    async addToTeam(personId, anchor) {
        const person = this.model.person(personId);
        const picked = await this.openDialog({
            title: this.tk('add_to_title', { name: person?.name ?? '' }),
            anchor,
            picker: {
                label: this.tk('search_teams'),
                placeholder: this.tk('search_teams_placeholder'),
                none: this.t('no_matches'),
                options: (query) => teamOptions(this.model, this.roundId, query, this.texts),
            },
            returnFocus: () => this.focusTrayAfter(personId),
        }).result;

        if (picked?.teamId) {
            this.intents.set(picked.teamId, { personId, index: this.slotsOf(picked.teamId).length });
            const changes = [{ op: 'place', participant: personId, round: this.roundId, from: this.model.placeValue(personId, this.roundId), to: teamPlace(picked.teamId) }];
            this.perform(buildAction(this.model, [changes], { label: { key: 'put_in_team' }, ...this.options() }));
            this.context.announce(this.t('added_to', { name: person?.name ?? '', team: picked.label }));
        }
    }

    /** "Add people to this round": people of the event not in it (or a new person) → in the round, without a pair/team. */
    async addPeople(anchor) {
        const picked = await this.openDialog({
            title: this.t('add_people_title', { round: this.model.round(this.roundId)?.name ?? '' }),
            anchor,
            picker: {
                label: this.t('search_people'),
                placeholder: this.t('search_people_placeholder'),
                empty: this.t('add_people_hint'),
                none: this.t('no_matches'),
                options: (query) => (query.trim() === '' ? [] : personOptions(this.model, this.roundId, query, this.texts, { only: 'out', countries: this.context.countries })),
            },
            returnFocus: () => this.tray.querySelector('[data-tray-add]')?.focus({ preventScroll: true }),
        }).result;

        if (picked === null) {
            return;
        }

        if (picked.create) {
            const id = newClientId();
            const changes = [
                { op: 'newParticipant', id, name: cleanName(picked.name), country: null, externalId: null },
                { op: 'place', participant: id, round: this.roundId, from: OUT, to: IN },
            ];
            this.perform(buildAction(this.model, [changes], { label: { key: 'add_person' }, ...this.options() }));
            this.context.announce(this.t('added_new_to_round', { name: cleanName(picked.name) }));
        } else if (picked.personId) {
            this.perform(setPlace(this.model, picked.personId, this.roundId, IN, { label: { key: 'round_in' }, ...this.options() }));
            this.context.announce(this.t('added_to_round', { name: picked.label }));
        }
    }

    // ---------------------------------------------------------------- paste

    paste(anchor, block, selected) {
        const colKey = anchor.col;

        if (RESULT_COLUMNS.includes(colKey)) {
            if (colKey !== 'result' || block.some((row) => row.length > 1)) {
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

        if (colKey === 'size' || colKey === 'actions') {
            this.context.announce(this.t('paste_not_here'));

            return;
        }

        this.pasteTeams(anchor, block, selected);
    }

    /** What every pasted column means, from the anchor column; wider than the row allows = rows of pairs/teams. */
    pasteLayout(anchor, block) {
        const width = Math.max(...block.map((row) => row.length));
        const columns = [];
        const slots = [];
        let start = this.columns.findIndex((column) => column.key === anchor.col);
        let ignoredFirst = false;

        if (anchor.col === 'table') {
            const numbers = block.map((row) => (row[0] ?? '').trim()).filter((value) => value !== '');
            ignoredFirst = numbers.length > 0 && numbers.every((value) => /^#?\d{1,6}$/.test(value));

            if (ignoredFirst) {
                columns.push('ignore');
                slots.push(null);
            }

            start = this.columns.findIndex((column) => column.key === 'name') - (ignoredFirst ? 1 : 0);
        }

        const extra = this.columns.findIndex((column) => column.key === 'mplus');
        const allowed = extra - Math.max(start, 0) + 1;
        let rowsMode = anchor.row === NEW_ROW || width > allowed;

        for (let column = columns.length; column < width; column++) {
            const key = this.columns[start + column]?.key;

            if (key === 'name') {
                columns.push('name');
                slots.push(null);
            } else if (key && this.isMemberColumn(key)) {
                columns.push('member');
                slots.push(this.memberIndex(key));
            } else {
                // Past the member columns: more members (rows mode)
                columns.push('member');
                slots.push(null);
                rowsMode = true;
            }
        }

        return { columns, slots, rowsMode, ignoredFirst };
    }

    pasteTeams(anchor, block) {
        const layout = this.pasteLayout(anchor, block);
        const startRow = this.grid.rows.indexOf(anchor.row);
        const targets = block.map((row, index) => {
            if (layout.rowsMode) {
                return null;
            }

            const key = this.grid.rows[startRow + index];

            return key === undefined || key === NEW_ROW ? null : key;
        });

        if (layout.rowsMode) {
            // Rows of pairs/teams: member columns in order, no positions
            layout.slots = layout.slots.map(() => null);
        }

        const plan = planTeamPaste(this.model, this.roundId, block, { columns: layout.columns, slots: layout.slots, targets, slotsOf: (teamId) => this.slotsOf(teamId) });

        if (plan.lines.length === 0) {
            this.context.announce(this.t('paste_nothing'));

            return;
        }

        const cells = block.reduce((sum, row) => sum + row.length, 0);
        const decide = plan.counts.ambiguousTeams > 0 || plan.counts.ambiguousPeople > 0 || plan.newNames.length > 0;

        if (cells <= PREVIEW_ABOVE_CELLS && !decide && !layout.ignoredFirst) {
            const action = buildTeamPasteAction(this.model, this.roundId, plan, {}, { countries: this.context.countryCodes, slotsOf: (teamId) => this.slotsOf(teamId) });

            if (isEmpty(action) && action.errors.length === 0) {
                this.context.announce(this.t('paste_nothing'));

                return;
            }

            this.context.act(action);
            this.context.announce(this.tc('pasted_rows', action.groups.length));

            return;
        }

        this.previewTeams(plan, layout);
    }

    async previewTeams(plan, layout) {
        const slotsOf = (teamId) => this.slotsOf(teamId);
        const refusedLines = new Set();
        const build = (selection) => buildTeamPasteAction(this.model, this.roundId, plan, selection, { countries: this.context.countryCodes, slotsOf, skip: refusedLines });
        const defaults = this.defaultSelection(plan);
        const first = build(defaults);
        const changing = new Set(first.lineIds);
        // Rows that change nothing (the pair is as pasted already) say so
        const lines = this.teamPreviewLines(plan, layout).map((line) => (line.id.startsWith('t') && !changing.has(line.id) && line.status !== 'warning'
            ? { ...line, status: 'same', note: [line.note, this.t('paste_no_change')].filter(Boolean).join(' · ') }
            : line));
        const dialog = this.context.preview({
            title: this.tk('paste_title'),
            intro: this.t('paste_intro'),
            counts: this.teamPasteCounts(plan),
            lines,
            loading: first.groups.length > 0,
            confirmLabel: this.tc('paste_confirm', first.groups.length),
            returnFocus: () => this.grid?.focusActive({ scroll: false }),
        });

        const refused = new Map((first.refusedLines ?? []).map((refusal) => [refusal.lineId, { status: 'error', note: this.reasonText(refusal.error) }]));

        if (refused.size > 0 && first.groups.length === 0) {
            dialog.update({ lines: lines.map((line) => (refused.has(line.id) ? { ...line, ...refused.get(line.id) } : line)) });
        }

        if (first.groups.length > 0) {
            const answer = await this.context.queue.preview(first.groups);
            const notes = new Map([...refused, ...this.dryRunNotes(answer, first)]);

            for (const [lineId, note] of notes) {
                if (note.status === 'error') {
                    refusedLines.add(lineId);
                }
            }

            dialog.update({
                loading: false,
                lines: lines.map((line) => (notes.has(line.id) ? { ...line, status: notes.get(line.id).status, note: [line.note, notes.get(line.id).note].filter(Boolean).join(' · ') } : line)),
                confirmLabel: this.tc('paste_confirm', first.lineIds.filter((id) => !refusedLines.has(id)).length),
                message: answer.kind === 'ok' ? '' : this.t('paste_unchecked'),
            });
        }

        const selection = await dialog.result;

        if (selection === null) {
            this.context.announce(this.t('paste_cancelled'));

            return;
        }

        // Built again from the organiser's choices; the server checks every `from` again
        const action = build(selection);

        if (isEmpty(action)) {
            this.context.announce(this.t('paste_nothing'));

            return;
        }

        this.context.act(action);
        this.context.announce(this.tc('pasted_rows', action.groups.length));
    }

    defaultSelection(plan) {
        return { choices: {}, ticks: Object.fromEntries(plan.newNames.map((entry) => [`n${entry.key}`, true])) };
    }

    /** Per line: the server's dry run said no (a refusal) or warned. */
    dryRunNotes(answer, action) {
        const notes = new Map();

        if (answer.kind !== 'ok') {
            return notes;
        }

        const lineOf = new Map(action.groups.map((group, index) => [group.id, action.lineIds?.[index] ?? null]));

        for (const group of answer.data?.groups ?? []) {
            const lineId = lineOf.get(group.id);

            if (!lineId) {
                continue;
            }

            if (group.status === 'refused' || group.status === 'conflict') {
                const change = (group.changes ?? []).find((candidate) => candidate.status === 'refused' || candidate.status === 'conflict');
                notes.set(lineId, { status: 'error', note: change?.message ?? this.t('paste_not_possible') });
            } else if ((group.warnings ?? []).length > 0) {
                notes.set(lineId, { status: 'warning', note: group.warnings.map((warning) => warning.message).filter(Boolean).join(' ') });
            }
        }

        return notes;
    }

    teamPasteCounts(plan) {
        const counts = [];
        const add = (count, key, tone = null) => {
            if (count > 0) {
                counts.push({ text: this.tc(key, count), tone });
            }
        };
        const duo = this.duo();
        add(plan.counts.newTeams, duo ? 'count_new_pairs' : 'count_new_teams');
        add(plan.lines.filter((line) => line.match === 'existing').length, duo ? 'count_pairs_matched' : 'count_teams_matched');
        add(plan.counts.moves, 'count_moves');
        add(plan.counts.newPeople, 'count_new_people', 'warning');
        add(plan.counts.ambiguousTeams + plan.counts.ambiguousPeople, 'count_to_choose', 'warning');
        add(plan.counts.sharedNames, 'count_shared_names');

        return counts;
    }

    teamPreviewLines(plan, layout) {
        const lines = [];

        if (layout.ignoredFirst) {
            lines.push({ id: 'ignored', text: this.t('paste_tables_ignored'), status: 'skip' });
        }

        for (const line of plan.lines) {
            const members = line.members.filter((member) => member.status !== 'empty').map((member) => member.text);
            const title = [line.name ?? (line.match === 'existing' && !line.nameCovered ? (this.model.team(line.teamId)?.name ?? this.core.t('team_no_name')) : this.core.t('team_no_name')), members.join(', ')].filter(Boolean).join(' · ');
            const notes = [];
            let status = 'change';

            if (line.match === NEW_TEAM) {
                status = 'new';
                notes.push(this.tk('paste_new'));
            } else if (line.match === 'existing') {
                notes.push(this.t('paste_into', { team: teamLabelText(this.model, line.teamId, this.texts) }));

                if (line.byMembers && line.name !== null && line.name !== this.model.team(line.teamId)?.name) {
                    notes.push(this.t('paste_rename', { name: line.name }));
                }

                const leaving = line.membersCovered && !line.positional
                    ? this.model.membersOf(line.teamId).filter((person) => !line.members.some((member) => member.status === 'one' && member.ids[0] === person.id))
                    : [];

                if (leaving.length > 0) {
                    notes.push(this.tk('paste_to_tray', { names: leaving.map((person) => person.name).join(', ') }));
                }
            } else {
                status = 'warning';
            }

            for (const member of line.members) {
                if (member.status === 'one') {
                    const where = whereInRound(this.model, member.ids[0], this.roundId, this.texts);

                    if (where.kind === 'team' && where.teamId !== line.teamId) {
                        notes.push(this.t('moves_announce', { name: member.text, where: where.text }));
                    }
                }
            }

            if (line.twice.length > 0) {
                status = 'warning';
                notes.push(this.t('paste_twice', { names: line.twice.map((id) => this.model.person(id)?.name ?? '').join(', ') }));
            }

            const entry = { id: line.id, text: title, status, note: notes.join(' · ') };

            if (line.match === 'ambiguous') {
                entry.choices = {
                    label: this.tk('paste_which', { name: line.name ?? '' }),
                    options: [
                        ...line.candidates.map((teamId) => ({ value: teamId, label: teamLabelText(this.model, teamId, this.texts) })),
                        { value: NEW_TEAM, label: this.tk('paste_choice_new') },
                    ],
                    value: line.candidates[0],
                };
            }

            lines.push(entry);

            for (const member of line.members) {
                if (member.status === 'several') {
                    lines.push({
                        id: `p${line.index}:${member.column}`,
                        text: member.text,
                        status: 'warning',
                        note: this.t('paste_person_ambiguous'),
                        choices: {
                            label: this.t('paste_which_person'),
                            options: [
                                ...member.ids.map((id) => ({ value: id, label: this.personChoiceLabel(id) })),
                                { value: SKIP, label: this.t('paste_leave_out') },
                            ],
                            value: member.ids[0],
                        },
                    });
                }
            }
        }

        for (const entry of plan.newNames) {
            lines.push({
                id: `n${entry.key}`,
                text: entry.name,
                status: 'new',
                note: this.t('paste_new_person_note'),
                tick: { label: this.t('paste_add_new_person', { name: entry.name }), checked: true },
            });
        }

        return lines;
    }

    personChoiceLabel(personId) {
        const person = this.model.person(personId);
        const country = person?.country ? (this.context.countries[person.country] ?? person.country.toUpperCase()) : '';

        return [person?.name ?? '', country, whereInRound(this.model, personId, this.roundId, this.texts).text].filter(Boolean).join(' · ');
    }

    /** `name ⇥ result` rows (or one column onto Result): matched to this round's entries only, one confirm, one undo step. */
    async pasteResults(block, mode, anchor) {
        const entries = roundEntries(this.model, this.roundId, this.results.pending(), this.texts);
        const startRow = this.grid.rows.indexOf(anchor.row);
        const targets = mode === 'positional'
            ? block.map((row, index) => {
                const key = this.grid.rows[startRow + index];

                return key === undefined || key === NEW_ROW ? null : `team:${key}`;
            })
            : [];
        const plan = planResultsPaste(this.model, this.roundId, entries, block, { mode, targets, parse: this.results.parseOptions() });
        await previewResults(this, plan, entries);
    }
}
