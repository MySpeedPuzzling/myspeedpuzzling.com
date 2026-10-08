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
 * - Paste: rows of pairs/teams (`Team ⇥ member ⇥ member`) and results (`name ⇥ result`, `#code ⇥ result`,
 *   `table ⇥ result`, or a column onto Result) - round_paste.js, through the preview with the server's dry run; a
 *   one-line hint under the grid says how (BR7).
 * - Toolbar: problem filters (an active filter and "Show all" stay while it matches nothing; the row being worked on
 *   stays until the filter changes - review D-M2), members per team, "Results published …" while the round's results
 *   are public, "N qualified", Sort, Sort by rank (results columns shown), Results columns, the round's tools (BR6).
 * - Refusals and "nothing happened" are shown (feedback() → the core's notify(), BR1), never only read out.
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
    feedback,
    flagHtml,
    focusKey,
    keepOrder,
    matchPreview,
    memberSlots,
    newPersonLine,
    nameCollator,
    pageStorage,
    personOptions,
    previewResults,
    qualifiedCount,
    rankOrder,
    resultsColumnsShown,
    RoundDialog,
    roundEntries,
    roundRefusalText,
    RoundResultsCells,
    safeColor,
    sizeInfo,
    sortedTeamIds,
    storedResultsColumns,
    storeResultsColumns,
    takeTeamOutAction,
    teamLabelText,
    teamOptions,
    teamShortLabel,
    usesTables,
    whereInRound,
} from '../round/round_common.js';
import { officialEdits, resultEditText, roundRanks } from '../sheet_results.js';
import {
    CHOOSE,
    NEW_TEAM,
    SKIP,
    buildTeamPasteAction,
    headerWordSet,
    looksLikeResults,
    matchPerson,
    planResultsPaste,
    planTeamPaste,
    snapshotModel,
    undecidedChoices,
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
        // Rows a filter does not hide until it changes: the row being worked on, pairs/teams made meanwhile (D-M2)
        this.held = new Set();
        this.rankSort = false;
        this.resortTimer = null;
        this.editor = null;
        this.sheetEditor = null;
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

    /** A refusal or "nothing happened", shown next to `anchor` (a cell or an element) - BR1. */
    say(text, { kind = 'error', anchor = null } = {}) {
        feedback(this.context, text, { kind, anchor });
    }

    // ---------------------------------------------------------------- the view interface

    render() {
        const root = this.context.root;
        root.classList.add('sheet-view', 'sheet-view-round', 'sheet-view-teams');
        this.toolbar = document.createElement('div');
        this.toolbar.className = 'sheet-round-toolbar';
        this.gridRoot = document.createElement('div');
        this.gridRoot.className = 'sheet-grid-host';
        // How to paste, visible under the grid (BR7)
        this.hint = document.createElement('p');
        this.hint.className = 'sheet-round-paste-hint small';
        this.hint.innerHTML = `<i class="bi bi-clipboard" aria-hidden="true"></i> ${escapeHtml(this.tk('paste_hint'))}`;
        this.tray = document.createElement('section');
        this.tray.className = 'sheet-round-tray';
        this.tray.setAttribute('aria-labelledby', `sheet-tray-title-${this.roundId}`);
        root.replaceChildren(this.toolbar, this.gridRoot, this.hint, this.tray);

        this.on(this.toolbar, 'click', (event) => this.onToolbarClick(event));
        this.on(this.toolbar, 'change', (event) => this.onToolbarChange(event));
        this.on(this.tray, 'click', (event) => this.onTrayClick(event));

        if (typeof ResizeObserver !== 'undefined') {
            const observer = new ResizeObserver(() => this.fitTray());
            observer.observe(this.tray);
            observer.observe(this.hint);
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
        this.builtKey = this.columnsKey();
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
            // What the organiser saw when an edit began is the `from` of its save: remembered when the edit starts,
            // read back without side effects (review D NIT)
            editStart: (row, col) => this.captureSeen(row, col),
            seenValue: (row, col) => this.seenFor(row, col),
            suggest: (row, col, query, info) => this.suggest(row, col, query, info),
            commit: (row, col, input, info) => this.commit(row, col, input, info),
            toggle: (cells, value) => this.toggle(cells, value),
            clear: (cells) => this.clear(cells),
            paste: (anchor, rows, selected) => this.paste(anchor, rows, selected),
            fill: (direction, range, active) => this.say(this.t('fill_not_here'), { kind: 'warning', anchor: active ?? this.grid?.active ?? null }),
            activate: (row, col) => this.activate(row, col),
        });
        this.on(this.grid.table, 'contextmenu', (event) => this.onContextMenu(event));
    }

    /** What the columns depend on: a change means a new grid (the member count, table numbers, results, the name). */
    columnsKey() {
        const round = this.model.round(this.roundId);

        return [this.wantedMemberCount(), usesTables(this.model, round), this.showResults, round?.name ?? ''].join('|');
    }

    /** Columns changed (member count, results shown, the round's table usage): a new grid, the same cell focused. */
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
        // Never inside a commit: the grid finishes the edit first
        if (this.rebuildTimer === null) {
            this.rebuildTimer = setTimeout(() => this.rebuild(), 0);
        }
    }

    update(delta) {
        if (this.grid === null || this.model.round(this.roundId) === null) {
            return;
        }

        // The order first - a fetched state (delta.all) brings pairs/teams too (review D-m1)
        const ours = new Set(this.model.teamsOf(this.roundId).map((team) => team.id));
        const known = new Set(this.order);
        this.order = keepOrder(this.order, [...ours]);
        this.holdWorkedOn([...ours].filter((id) => !known.has(id)));
        this.autoShowResults();

        // New columns (a member typed into "+", the round's table usage, its name, results shown): a new grid once the
        // edit is done - the rows below are kept current on the old one meanwhile
        if (this.columnsKey() !== this.builtKey) {
            this.scheduleRebuild();
        }

        const touched = delta.all || delta.rounds.has(this.roundId) || [...delta.teams].some((id) => ours.has(id) || known.has(id));
        const keys = this.rowKeys();
        const rowsChanged = keys.length !== this.grid.rows.length || keys.some((key, index) => this.grid.rows[index] !== key);

        if (rowsChanged) {
            this.grid.setRows(keys);
        }

        const rows = new Set(delta.all ? ours : []);

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

            if (this.updateRanks() && this.rankSort) {
                this.scheduleResort();
            }
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

        // A renamed person shows in the tray, counts and published state in the toolbar (both compare their markup)
        if (touched || rowsChanged || delta.people.size > 0) {
            this.renderToolbar();
            this.renderTray();
        }

        this.refreshOpenEditor(rows);
    }

    /**
     * While a filter is on, the row being worked on stays (People's rule - D-M2): the focused row, the row being
     * edited and pairs/teams made meanwhile, until the filter changes.
     */
    holdWorkedOn(newIds = []) {
        if (this.filter === null || this.grid === null) {
            return;
        }

        for (const id of [this.grid.active?.row, this.grid.editing?.row, ...newIds]) {
            if (id && id !== NEW_ROW) {
                this.held.add(id);
            }
        }
    }

    /**
     * The results columns follow the round (review D NIT): they appear when the round starts or its first result
     * arrives - unless the organiser chose for this round (their choice wins).
     */
    autoShowResults() {
        if (this.showResults || storedResultsColumns(this.storage, this.roundId) !== null) {
            return;
        }

        if (resultsColumnsShown(this.model, this.roundId, null)) {
            this.showResults = true;
        }
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
        clearTimeout(this.resortTimer);
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
            ids = ids.filter((id) => this.matchesFilter(id) || this.held.has(id));
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

    entries() {
        return roundEntries(this.model, this.roundId, this.results.pending(), this.texts);
    }

    computeRanks() {
        return roundRanks(this.entries());
    }

    /** Ranks again (a result changed): the rank cells that say something else. Returns whether any rank changed. */
    updateRanks() {
        const previous = this.ranks;
        this.ranks = this.computeRanks();
        let changed = false;

        for (const id of new Set([...this.ranks.keys(), ...previous.keys()])) {
            if (this.ranks.get(id) !== previous.get(id)) {
                changed = true;

                if (this.showResults) {
                    this.grid.updateCell(id, 'rank');
                }
            }
        }

        return changed;
    }

    /** "Sort by rank" (BR6): ranked pairs/teams first, the rest by table and name. */
    rankedOrder() {
        return rankOrder(this.entries(), sortedTeamIds(this.model, this.roundId, this.collator), (entry) => entry.id);
    }

    /** Sorted by rank: the rows follow the ranks - after the edit the organiser is in, never under their cursor. */
    scheduleResort() {
        clearTimeout(this.resortTimer);
        this.resortTimer = setTimeout(() => {
            this.resortTimer = null;

            if (this.grid === null || !this.rankSort) {
                return;
            }

            if (this.grid.isEditing()) {
                this.scheduleResort();

                return;
            }

            this.order = this.rankedOrder();
            this.grid.setRows(this.rowKeys());

            if (!this.tablesUsed) {
                this.grid.rows.forEach((key) => this.grid.updateCell(key, 'table'));
            }
        }, this.grid?.isEditing() ? 300 : 0);
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

    /** The grid's `seenValue`: what the edit that just started remembered - read only, no side effects. */
    seenFor(row, col) {
        const official = this.editorFor(row, col);

        if (official !== null) {
            return official.seen;
        }

        if (this.sheetEditor !== null && this.sheetEditor.row === row && this.sheetEditor.col === col) {
            return this.sheetEditor.seen;
        }

        return col === 'table' || col === 'result' ? null : this.sheetValue(row, col);
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
            this.grid.editorNotice(null);

            return;
        }

        const shown = editing.col === 'name' ? (now ?? this.core.t('team_no_name')) : (now === null ? this.t('member_empty') : (this.model.person(now)?.name ?? ''));
        this.grid.editorNotice({
            text: this.t('changed_meanwhile', { value: shown }),
            actions: [
                {
                    label: this.t('meanwhile_keep_mine'),
                    run: () => {
                        // The organiser has seen the other value: their save goes over it, now (like the People tab)
                        own.seen = now;

                        if (typeof this.grid.commitWithSeen === 'function') {
                            this.grid.commitWithSeen(now);
                        }
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

    /** A client refusal in the server's words (cause variants, parameters - the core's refusalDetails()). */
    reasonText(error) {
        return roundRefusalText(this.context, this.model, this.texts, error);
    }

    /**
     * An action of an editor's commit: a client refusal of everything comes back as the editor's error (shown at the
     * editor); a part refused is shown next to the cell.
     */
    perform(action, anchor = null) {
        const blocked = action.groups.length === 0 && (action.results ?? []).length === 0 && action.errors.length > 0;
        const outcome = this.context.act(action, { quiet: true });

        if (blocked) {
            return { error: this.reasonText(outcome.errors[0]) };
        }

        if (outcome.errors.length > 0) {
            this.say(this.reasonText(outcome.errors[0]), { anchor });
        }

        return undefined;
    }

    /**
     * An action of a menu, the tray or the toolbar: a refusal shown next to `anchor`, `success` read out only when
     * something was done (review D NIT - never "Pair created" for a refused pair). Returns whether it was performed.
     */
    run(action, { success = '', anchor = null } = {}) {
        const outcome = this.context.act(action, { quiet: true });

        if (outcome.errors.length > 0) {
            this.say(this.reasonText(outcome.errors[0]), { anchor });
        }

        if (outcome.performed && success) {
            this.context.announce(success);
        }

        return outcome.performed === true;
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
        if (row !== NEW_ROW) {
            // The row being worked on stays while a filter is on (D-M2)
            this.holdWorkedOn([row]);
        }

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
            const error = this.perform(action, { row, col: 'name' });

            if (error) {
                return error;
            }

            this.holdWorkedOn([action.teamId]);
            this.announceSharedName(action.teamId, name);
            this.context.announce(this.tk('created', { name }));

            return { focus: this.focusAfterCreate('name', action.teamId) };
        }

        const to = cleanTeamName(input.text);

        if (to === seen || this.model.team(row) === null) {
            return undefined;
        }

        // `from` = the name the organiser saw - a rename by somebody else meanwhile comes back as a conflict
        const error = this.perform(buildAction(this.model, [[{ op: 'renameTeam', team: row, from: seen, to }]], { label: { key: 'rename_team' }, ...this.options() }), { row, col: 'name' });

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
    memberFrom(input, teamId = null) {
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
            const where = whereInRound(this.model, found.ids[0], this.roundId, this.texts);

            // Enter alone never moves somebody out of another pair/team (D-m2): the list's "moves from …" option does
            if (where.kind === 'team' && where.teamId !== teamId) {
                return { error: this.t('member_pick_to_move', { name: this.model.person(found.ids[0])?.name ?? cleanName(text), where: where.text }) };
            }

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
        const member = this.memberFrom(input, teamId);

        if (member !== null && typeof member === 'object' && member.error) {
            return { error: member.error };
        }

        if (member === null) {
            if (current === null) {
                return undefined;
            }

            // A pair/team with a result keeps a going member: the core's group check says why (review D-m9)
            const cleared = buildAction(this.model, [[{ op: 'place', participant: current, round: this.roundId, from: teamPlace(teamId), to: IN }]], { label: { key: 'clear_member' }, ...this.options() });
            const error = this.perform(cleared, { row, col });

            if (!error) {
                this.context.announce(this.tk('member_to_tray', { name: this.model.person(current)?.name ?? '' }));
            }

            return error ?? { focus: this.focusAfter() };
        }

        if (member === current) {
            return undefined;
        }

        const moveText = typeof member === 'string' ? this.moveAnnouncement(member, teamId) : '';

        if (teamId === null) {
            const action = newTeamRow(this.model, this.roundId, { members: [member] }, this.options());
            const personId = typeof member === 'string' ? member : action.groups[0]?.changes.find((change) => change.op === 'newParticipant')?.id;

            if (personId) {
                this.intents.set(action.teamId, { personId, index: 0 });
            }

            const error = this.perform(action, { row, col });

            if (error) {
                return error;
            }

            this.holdWorkedOn([action.teamId]);
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
        const error = this.perform(buildAction(this.model, [changes], { label: { key: 'put_in_team' }, ...this.options() }), { row, col });

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

        return this.afterOfficialCommit(row, answer, 'table');
    }

    commitResult(row, input) {
        const team = this.model.team(row);
        const answer = this.results.resultCommit(this.editorFor(row, 'result'), team?.result ?? null, team?.enteredBy ?? null, input);

        return this.afterOfficialCommit(row, answer, 'result');
    }

    afterOfficialCommit(row, answer, col) {
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

        return this.perform(answer.action, { row, col }) ?? { focus: this.focusAfter() };
    }

    toggle(cells, value) {
        const entries = cells.filter((cell) => cell.col === 'qualified' && cell.row !== NEW_ROW).map((cell) => this.entryOf(cell.row));
        this.holdWorkedOn(cells.map((cell) => cell.row));
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

        // Members cleared together: one group per pair/team - the core's group check refuses one that would leave a
        // pair/team holding a result without a going member, with the reason (review D-m9)
        const byTeam = new Map();

        for (const item of actions.filter((action) => action.personId)) {
            byTeam.set(item.teamId, [...(byTeam.get(item.teamId) ?? []), item.personId]);
        }

        const groups = [...byTeam].map(([teamId, people]) => people.map((personId) => ({ op: 'place', participant: personId, round: this.roundId, from: teamPlace(teamId), to: IN })));
        const combined = combine(
            { key: 'clear' },
            ...actions.filter((action) => !action.personId),
            buildAction(this.model, groups, { label: { key: 'clear_member' }, ...this.options() }),
            results.length > 0 ? officialEdits(results) : null,
        );

        this.holdWorkedOn(cells.map((cell) => cell.row));

        if (!isEmpty(combined) || combined.errors.length > 0) {
            const outcome = this.context.act(combined, { quiet: true });

            if (outcome.errors.length > 0) {
                const refused = outcome.errors[0];
                const teamId = refused.teamId ?? parsePlace(refused.change?.from ?? '').teamId ?? refused.change?.team ?? null;
                this.say(this.reasonText(refused), { anchor: teamId ? { row: teamId, col: cells.find((cell) => cell.row === teamId)?.col ?? 'name' } : null });
            }
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
            onDisabled: (item) => this.say(item.reason, { anchor: { row: teamId, col: 'actions' } }),
        });
        const choice = await dialog.result;

        if (choice === null) {
            return;
        }

        const where = { row: teamId, col: 'name' };

        if (choice.value === 'rename') {
            if (this.grid.focusCell(teamId, 'name')) {
                this.grid.startEdit(false);
            }
        } else if (choice.value === 'delete') {
            this.run(deleteTeam(this.model, teamId, this.options()), { success: this.tk('deleted', { team: label }), anchor: where });
        } else if (choice.value === 'out') {
            // Deleted and its people out of the round in one group - the undo gives the table number back (D-m5)
            this.run(takeTeamOutAction(this.model, this.roundId, teamId, this.options()), { success: this.tk('taken_out', { team: label }), anchor: where });
        }
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
        // An active filter stays - with "Show all" - while it matches nothing (D-M2: never a trap without a way out)
        const filters = [
            ['incomplete', problems.incomplete, this.tc('filter_incomplete', problems.incomplete), true],
            ['tooMany', problems.tooMany, this.tc('filter_too_many', problems.tooMany), true],
            ['without', problems.withoutTeam, this.tc(this.duo() ? 'filter_without_pair' : 'filter_without_team', problems.withoutTeam), true],
            ['sameName', problems.sameName, this.tc('filter_same_name', problems.sameName), false],
        ].filter(([key, count]) => count > 0 || this.filter === key);
        const filterHtml = filters.map(([key, , text, warn]) => `<button type="button" class="btn btn-sm sheet-round-filter${this.filter === key ? ' active' : ''}" data-filter="${key}" aria-pressed="${this.filter === key ? 'true' : 'false'}">${warn ? '<i class="bi bi-exclamation-triangle" aria-hidden="true"></i> ' : ''}${escapeHtml(text)}</button>`).join('');
        const clearFilter = this.filter !== null ? `<button type="button" class="btn btn-sm btn-link" data-filter="">${escapeHtml(this.t('filter_clear'))}</button>` : '';
        const sizeMarker = this.context.markerFor(`round:${this.roundId}:teamSize`);
        const teamSize = round.category === 'team' ? `<label class="sheet-round-size">${escapeHtml(this.t('team_size_label'))}
                <select class="form-select form-select-sm" data-team-size aria-describedby="sheet-team-size-hint-${escapeHtml(this.roundId)}">
                    <option value=""${round.teamSize === null ? ' selected' : ''}>${escapeHtml(this.t('team_size_not_set'))}</option>
                    ${Array.from({ length: TEAM_SIZE_MAX - TEAM_SIZE_MIN + 1 }, (_, index) => index + TEAM_SIZE_MIN).map((size) => `<option value="${size}"${round.teamSize === size ? ' selected' : ''}>${size}</option>`).join('')}
                </select></label><span class="sheet-round-hint small" id="sheet-team-size-hint-${escapeHtml(this.roundId)}">${escapeHtml(round.teamSize === null ? this.t('team_size_guess', { size: this.model.usualTeamSize(this.roundId) }) : '')}</span>${markerHtml(sizeMarker)}` : '';
        const links = this.linksHtml(round);
        const qualified = qualifiedCount(this.entries());
        const html = `<div class="sheet-round-summary">
                <span class="sheet-round-swatch" style="background-color:${escapeHtml(safeColor(round.color))}" aria-hidden="true"></span>
                <strong>${escapeHtml(round.name)}</strong>
                <span>${escapeHtml(this.core.tc(this.duo() ? 'tab_count_pairs' : 'tab_count_teams', teams))} · ${escapeHtml(this.core.tc('tab_count_people', people))}${qualified > 0 ? ` · ${escapeHtml(this.tc('qualified_count', qualified))}` : ''}</span>
            </div>
            ${filterHtml || clearFilter ? `<div class="sheet-round-filters" role="group" aria-label="${escapeHtml(this.t('filters_label'))}">${filterHtml}${clearFilter}</div>` : ''}
            ${teamSize ? `<div class="sheet-round-size-wrap">${teamSize}</div>` : ''}
            <div class="sheet-round-actions">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-sort title="${escapeHtml(this.t('sort_hint'))}"><i class="bi bi-sort-numeric-down" aria-hidden="true"></i> ${escapeHtml(this.t('sort'))}</button>
                ${this.showResults ? `<button type="button" class="btn btn-sm btn-outline-secondary" data-rank-sort aria-pressed="${this.rankSort ? 'true' : 'false'}"><i class="bi bi-trophy" aria-hidden="true"></i> ${escapeHtml(this.t('sort_by_rank'))}</button>` : ''}
                <button type="button" class="btn btn-sm btn-outline-secondary" data-results aria-pressed="${this.showResults ? 'true' : 'false'}"><i class="bi bi-stopwatch" aria-hidden="true"></i> ${escapeHtml(this.t('results_columns'))}</button>
                ${links}
            </div>
            ${round.resultsPublished === true ? `<p class="sheet-round-published small mb-0"><i class="bi bi-broadcast-pin" aria-hidden="true"></i> ${escapeHtml(this.t('results_published'))}</p>` : ''}`;

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
            // A new filter shows exactly what it matches
            this.held = new Set();
            this.grid.setRows(this.rowKeys());
            this.renderToolbar();
            this.context.announce(this.filter === null ? this.t('filter_cleared') : this.tc('filter_shown', this.grid.rows.length - 1));

            return;
        }

        if (event.target.closest('[data-sort]')) {
            this.rankSort = false;
            this.order = sortedTeamIds(this.model, this.roundId, this.collator);
            this.applyOrder();
            this.renderToolbar();
            this.context.announce(this.t('sorted'));

            return;
        }

        if (event.target.closest('[data-rank-sort]')) {
            this.rankSort = !this.rankSort;
            this.order = this.rankSort ? this.rankedOrder() : sortedTeamIds(this.model, this.roundId, this.collator);
            this.applyOrder();
            this.renderToolbar();
            this.context.announce(this.t(this.rankSort ? 'sorted_by_rank' : 'sorted'));

            return;
        }

        if (event.target.closest('[data-results]')) {
            this.toggleResults(!this.showResults);
        }
    }

    applyOrder() {
        this.grid.setRows(this.rowKeys());

        if (!this.tablesUsed) {
            this.grid.rows.forEach((key) => this.grid.updateCell(key, 'table'));
        }
    }

    toggleResults(shown) {
        this.showResults = shown;

        if (!shown) {
            this.rankSort = false;
        }

        storeResultsColumns(this.storage, this.roundId, shown);
        this.rebuild();
        this.renderToolbar();
        this.context.announce(this.t(shown ? 'results_shown' : 'results_hidden'));
    }

    onToolbarChange(event) {
        const select = event.target.closest('[data-team-size]');

        if (select) {
            const value = select.value === '' ? null : Number(select.value);
            const error = this.perform(setTeamSize(this.model, this.roundId, value, this.options()), select);

            if (error) {
                this.say(error.error, { anchor: select });
                this.renderToolbar();
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
        this.context.root.style.setProperty('--sheet-round-tray-h', `${Math.min(this.tray.offsetHeight, 240) + (this.hint?.offsetHeight ?? 0)}px`);
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
            onDisabled: (item) => this.say(item.reason, { anchor }),
        }).result;

        if (choice === null) {
            return;
        }

        if (choice.value === 'pair') {
            this.pairWith(personId, anchor);
        } else if (choice.value === 'add') {
            this.addToTeam(personId, anchor);
        } else if (choice.value === 'new') {
            this.run(newTeamRow(this.model, this.roundId, { members: [personId] }, this.options()), { success: this.tk('created_with', { name: person.name }), anchor });
        } else if (choice.value === 'out') {
            this.run(setPlace(this.model, personId, this.roundId, OUT, { label: { key: 'round_out' }, ...this.options() }), { success: this.t('taken_out_person', { name: person.name }), anchor });
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
                pick: this.t('picker_pick'),
                options: (query) => personOptions(this.model, this.roundId, query, this.texts, { only: 'tray', exclude: new Set([personId]), create: false, countries: this.context.countries }).map((option) => ({ ...option, detail: '' })),
            },
            returnFocus: () => this.focusTrayAfter(personId),
        }).result;

        if (picked?.personId) {
            this.run(newTeamRow(this.model, this.roundId, { members: [personId, picked.personId] }, this.options()), { success: this.tk('paired', { first: person?.name ?? '', second: picked.label }), anchor });
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
                pick: this.t('picker_pick'),
                options: (query) => teamOptions(this.model, this.roundId, query, this.texts),
            },
            returnFocus: () => this.focusTrayAfter(personId),
        }).result;

        if (picked?.teamId) {
            this.intents.set(picked.teamId, { personId, index: this.slotsOf(picked.teamId).length });
            const changes = [{ op: 'place', participant: personId, round: this.roundId, from: this.model.placeValue(personId, this.roundId), to: teamPlace(picked.teamId) }];

            if (!this.run(buildAction(this.model, [changes], { label: { key: 'put_in_team' }, ...this.options() }), { success: this.t('added_to', { name: person?.name ?? '', team: picked.label }), anchor })) {
                this.intents.delete(picked.teamId);
            }
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
                pick: this.t('picker_pick'),
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
            this.run(buildAction(this.model, [changes], { label: { key: 'add_person' }, ...this.options() }), { success: this.t('added_new_to_round', { name: cleanName(picked.name) }), anchor });
        } else if (picked.personId) {
            this.run(setPlace(this.model, picked.personId, this.roundId, IN, { label: { key: 'round_in' }, ...this.options() }), { success: this.t('added_to_round', { name: picked.label }), anchor });
        }
    }

    // ---------------------------------------------------------------- paste

    paste(anchor, block, selected) {
        const colKey = anchor.col;

        if (RESULT_COLUMNS.includes(colKey)) {
            if (colKey !== 'result' || block.some((row) => row.length > 1)) {
                this.say(this.t('paste_results_column'), { kind: 'warning', anchor });

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
            this.say(this.t('paste_not_here'), { kind: 'warning', anchor });

            return;
        }

        this.pasteTeams(anchor, block, selected);
    }

    /** The words a heading row of a pasted block is made of: the grid's column names and the texts' list. */
    headerWords() {
        return headerWordSet([...this.columns.map((column) => column.label), ...this.t('paste_header_words').split(',')]);
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

        // Planned, checked and built on the page as it is now - a live change meanwhile comes back as a conflict (D-m4)
        const snapshot = snapshotModel(this.model);
        const slots = new Map(this.model.teamsOf(this.roundId).map((team) => [team.id, this.slotsOf(team.id).slice()]));
        const slotsOf = (teamId) => slots.get(teamId) ?? snapshot.membersOf(teamId).map((person) => person.id);
        const plan = planTeamPaste(snapshot, this.roundId, block, {
            columns: layout.columns,
            slots: layout.slots,
            targets,
            headerWords: this.headerWords(),
            countryCodes: this.context.countryCodes ?? null,
        });

        if (plan.lines.length === 0) {
            this.say(this.t(plan.duplicates.length > 0 || plan.header !== null ? 'paste_no_change' : 'paste_nothing'), { kind: 'info', anchor });

            return;
        }

        const cells = block.reduce((sum, row) => sum + row.length, 0);
        const decide = plan.counts.ambiguousTeams > 0 || plan.counts.ambiguousPeople > 0 || plan.newNames.length > 0;

        if (cells <= PREVIEW_ABOVE_CELLS && !decide && !layout.ignoredFirst && plan.header === null && plan.duplicates.length === 0) {
            const action = buildTeamPasteAction(snapshot, this.roundId, plan, {}, { countries: this.context.countryCodes, slotsOf });

            if (isEmpty(action) && action.errors.length === 0) {
                this.say(this.t('paste_nothing'), { kind: 'info', anchor });

                return;
            }

            this.run(action, { success: this.tc('pasted_rows', action.groups.length), anchor });

            return;
        }

        this.previewTeams(snapshot, plan, layout, slotsOf, anchor);
    }

    async previewTeams(snapshot, plan, layout, slotsOf, anchor) {
        const action = await matchPreview(this.context, {
            title: this.tk('paste_title'),
            intro: this.t('paste_intro'),
            build: (selection, skip) => buildTeamPasteAction(snapshot, this.roundId, plan, selection, { countries: this.context.countryCodes, slotsOf, skip }),
            describe: (selection, notes, forward) => ({ lines: this.teamPreviewLines(snapshot, plan, layout, notes, forward), counts: this.teamPasteCounts(plan) }),
            undecided: (selection) => undecidedChoices(plan, selection),
            confirmLabel: (count) => this.tc('paste_confirm', count),
            refusalText: (error) => roundRefusalText(this.context, snapshot, this.texts, error),
            returnFocus: () => this.grid?.focusActive({ scroll: false }),
            texts: {
                checking: this.t('paste_checking'),
                unchecked: this.t('paste_unchecked'),
                notPossible: this.t('paste_not_possible'),
                choose: (count) => this.tc('paste_choose_first', count),
            },
        });

        if (action === null) {
            this.context.announce(this.t('paste_cancelled'));

            return;
        }

        if (isEmpty(action)) {
            this.say(this.t('paste_nothing'), { kind: 'info', anchor });

            return;
        }

        this.run(action, { success: this.tc('pasted_rows', action.groups.length), anchor });
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
        add(plan.duplicates.length, 'count_listed_twice');

        return counts;
    }

    /**
     * The preview's lines: a line per pasted row (what it does, the dry run's word on it), a line per same-named person
     * to choose ("Choose…" until chosen), a line per new name (ticked unless a close name exists or it looks like a
     * code, a number or an e-mail - BR9); the heading row and rows naming the same people again are listed as left out.
     */
    teamPreviewLines(model, plan, layout, notes = new Map(), forward = null) {
        const lines = [];
        const changing = forward === null ? null : new Set(forward.lineIds);

        if (layout.ignoredFirst) {
            lines.push({ id: 'ignored', text: this.t('paste_tables_ignored'), status: 'skip' });
        }

        if (plan.header !== null) {
            lines.push({ id: plan.header.id, text: plan.header.text, status: 'skip', note: this.t('paste_header_skipped') });
        }

        const rowNumber = new Map(plan.lines.map((line) => [line.id, line.index + 1]));

        for (const line of plan.lines) {
            const members = line.members.filter((member) => member.status !== 'empty').map((member) => member.text);
            const title = [line.name ?? (line.match === 'existing' && !line.nameCovered ? (model.team(line.teamId)?.name ?? this.core.t('team_no_name')) : this.core.t('team_no_name')), members.join(', ')].filter(Boolean).join(' · ');
            const parts = [];
            let status = 'change';

            if (line.match === NEW_TEAM) {
                status = 'new';
                parts.push(this.tk('paste_new'));
            } else if (line.match === 'existing') {
                parts.push(this.t('paste_into', { team: teamLabelText(model, line.teamId, this.texts) }));

                if (line.byMembers && line.name !== null && line.name !== model.team(line.teamId)?.name) {
                    parts.push(this.t('paste_rename', { name: line.name }));
                }

                const leaving = line.membersCovered && !line.positional
                    ? model.membersOf(line.teamId).filter((person) => !line.members.some((member) => member.status === 'one' && member.ids[0] === person.id))
                    : [];

                if (leaving.length > 0) {
                    parts.push(this.tk('paste_to_tray', { names: leaving.map((person) => person.name).join(', ') }));
                }
            } else {
                status = 'warning';
            }

            for (const member of line.members) {
                if (member.status === 'one') {
                    const where = whereInRound(model, member.ids[0], this.roundId, this.texts);

                    if (where.kind === 'team' && where.teamId !== line.teamId) {
                        parts.push(this.t('moves_announce', { name: member.text, where: where.text }));
                    }
                }
            }

            if (line.twice.length > 0) {
                status = 'warning';
                parts.push(this.t('paste_twice', { names: line.twice.map((id) => model.person(id)?.name ?? '').join(', ') }));
            }

            const note = notes.get(line.id) ?? null;

            if (note !== null) {
                status = note.status;
                parts.push(note.note);
            } else if (changing !== null && !changing.has(line.id) && status !== 'warning' && !(forward.undecided ?? []).includes(line.id)) {
                // The pair/team is as pasted already
                status = 'same';
                parts.push(this.t('paste_no_change'));
            }

            const entry = { id: line.id, text: title, status, note: parts.filter(Boolean).join(' · ') };

            if (line.match === 'ambiguous') {
                // Nothing is picked for the organiser (D-M1): "Choose…" until they do
                entry.choices = {
                    label: this.tk('paste_which', { name: line.name ?? '' }),
                    options: [
                        { value: CHOOSE, label: this.t('paste_choose') },
                        ...line.candidates.map((teamId) => ({ value: teamId, label: teamLabelText(model, teamId, this.texts) })),
                        { value: NEW_TEAM, label: this.tk('paste_choice_new') },
                    ],
                    value: CHOOSE,
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
                                { value: CHOOSE, label: this.t('paste_choose') },
                                ...member.ids.map((id) => ({ value: id, label: this.personChoiceLabel(model, id) })),
                                { value: SKIP, label: this.t('paste_leave_out') },
                            ],
                            value: CHOOSE,
                        },
                    });
                }
            }
        }

        for (const duplicate of plan.duplicates) {
            lines.push({ id: duplicate.id, text: duplicate.text, status: 'same', note: this.tk('paste_same_people', { row: rowNumber.get(duplicate.of) ?? '' }) });
        }

        for (const entry of plan.newNames) {
            lines.push(newPersonLine(this.texts, entry));
        }

        return lines;
    }

    personChoiceLabel(model, personId) {
        const person = model.person(personId);
        const country = person?.country ? (this.context.countries[person.country] ?? person.country.toUpperCase()) : '';

        return [person?.name ?? '', country, whereInRound(model, personId, this.roundId, this.texts).text].filter(Boolean).join(' · ');
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
        const plan = planResultsPaste(this.model, this.roundId, entries, block, {
            mode,
            targets,
            parse: this.results.parseOptions(),
            tables: this.tablesUsed,
            // A round of team names only (or none yet): unknown names may become its pairs/teams (BR16)
            createTeams: this.model.isNamesOnly(this.roundId) || this.model.teamsOf(this.roundId).length === 0,
            headerWords: this.headerWords(),
        });
        await previewResults(this, plan, entries);
    }
}
