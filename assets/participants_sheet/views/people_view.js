/**
 * The People tab on a desktop (D1, O4; docs/features/competitions-management/participants-spreadsheet.md §4 (C) and
 * "Client architecture (as built)") - one row per active person: name, country, MSP profile, a checkbox column per solo
 * round, a read-only pair/team label per pair/team round (Enter or a double click opens that round's tab at the person),
 * and the new-person row at the bottom (type a name, Enter, the next name).
 *
 * The basics of stream C; stream E extends this file (filters, search, selection + bulk bar, adding people by paste,
 * registration columns, the Columns menu). Every edit is an action of sheet_changes.js handed to `context.act()`.
 *
 * Profile cell (O9): the linked profile's name, #CODE and link when the viewer may see it (`player.visible`), else
 * "Linked to a MySpeedPuzzling profile"; link / unlink through a typeahead over `urls.playerSearch`
 * (`player_search_autocomplete?format=co-puzzler`).
 */

import { SheetGrid, escapeHtml } from '../sheet_grid.js';
import {
    addPerson,
    combine,
    isEmpty,
    linkProfile,
    setField,
    setFields,
    setInRound,
} from '../sheet_changes.js';
import { readBoolean, trimCell } from '../tsv.js';
import { foldSearchText } from '../../search_fold.js';
import { parsePlace } from '../sheet_model.js';

export const NEW_ROW = '__new';
// A paste or fill touching more rows than this is previewed first (§6)
export const PREVIEW_ABOVE_ROWS = 10;
const UNLINK = '__unlink';
const OPEN = '__open';
const NO_COUNTRY = '__none';

export default function createPeopleView(context) {
    return new PeopleView(context);
}

export class PeopleView {
    constructor(context) {
        this.context = context;
        this.model = context.model;
        this.texts = context.texts.core;
        this.grid = null;
        this.roundSignatures = new Map();
        this.searchController = null;
    }

    t(key, params) {
        return this.texts.t(key, params);
    }

    // ---------------------------------------------------------------- the view interface

    render() {
        this.context.root.classList.add('sheet-view', 'sheet-view-people');
        this.gridRoot = document.createElement('div');
        this.gridRoot.className = 'sheet-grid-host';
        this.context.root.replaceChildren(this.gridRoot);
        this.columns = this.buildColumns();
        this.rememberRounds();
        this.grid = this.context.createGrid({
            container: this.gridRoot,
            label: this.t('people_grid_label'),
            columns: this.columns,
            rows: this.rowKeys(),
            cell: (row, col) => this.cell(row, col),
            rowLabel: (row) => (row === NEW_ROW ? this.t('people_new_row') : this.model.person(row)?.name ?? ''),
            rowClass: (row) => this.rowClass(row),
            editValue: (row, col) => this.editValue(row, col),
            suggest: (row, col, query) => this.suggest(row, col, query),
            commit: (row, col, input, info) => this.commit(row, col, input, info),
            toggle: (cells, value) => this.toggle(cells, value),
            clear: (cells) => this.clear(cells),
            paste: (anchor, rows, selected) => this.paste(anchor, rows, selected),
            fill: (kind, range, active) => this.fill(kind, range, active),
            activate: (row, col) => this.activate(row, col),
            openPanel: (row) => {
                if (row !== NEW_ROW) {
                    this.context.openPersonEditor(row);
                }
            },
        });
    }

    /**
     * The model changed (or a cell marker): only the rows it names are re-rendered; the row list only when people came
     * or went; every row of a round only when its expected size changed.
     */
    update(delta) {
        if (this.grid === null) {
            return;
        }

        if (delta.all || this.roundsChanged()) {
            // Rounds came, went or were renamed: new columns - the focused cell is focused again
            const active = { ...this.grid.active };
            const focused = this.gridRoot.contains(document.activeElement);
            this.grid.destroy();
            this.render();

            if (focused) {
                this.grid.focusCell(active.row, active.col);
            }

            return;
        }

        const keys = this.rowKeys();

        if (delta.rows || keys.length !== this.grid.rows.length || keys.some((key, index) => this.grid.rows[index] !== key)) {
            this.grid.setRows(keys);
        }

        const rows = new Set(delta.people);

        for (const teamId of delta.teams) {
            this.model.membersOf(teamId).forEach((person) => rows.add(person.id));
        }

        for (const roundId of delta.rounds) {
            const signature = this.roundSignature(roundId);

            if (this.roundSignatures.get(roundId) !== signature) {
                this.roundSignatures.set(roundId, signature);
                this.model.peopleIn(roundId).forEach((person) => rows.add(person.id));
            }
        }

        this.grid.updateRows([...rows]);
    }

    focus(target = null) {
        if (this.grid === null) {
            return;
        }

        if (target?.personId && this.grid.focusCell(target.personId, target.col ?? 'name')) {
            return;
        }

        this.grid.focusActive();
    }

    /** Jump to the cell of a problem (the conflicts panel). */
    reveal(problem) {
        const key = problem?.target?.key ?? '';
        const [kind, id, rest] = key.split(':');

        if (kind === 'person') {
            const col = rest === 'country' ? 'country' : (rest === 'player' ? 'player' : 'name');

            return this.grid?.focusCell(id, col) ?? false;
        }

        if (kind === 'place') {
            const roundId = key.slice(`place:${id}:`.length);

            return this.grid?.focusCell(id, `round:${roundId}`) ?? false;
        }

        return false;
    }

    destroy() {
        this.searchController?.abort();
        this.grid?.destroy();
        this.grid = null;
    }

    // ---------------------------------------------------------------- columns and rows

    buildColumns() {
        const columns = [
            { key: 'name', label: this.t('people_col_name'), kind: 'text', width: 240, space: 'panel' },
            { key: 'country', label: this.t('people_col_country'), kind: 'list', width: 180 },
            { key: 'player', label: this.t('people_col_profile'), kind: 'list', width: 240 },
        ];

        for (const round of this.model.rounds()) {
            const solo = round.category === 'solo';
            const kindText = this.t(`round_kind_${round.category}`);
            columns.push({
                key: `round:${round.id}`,
                label: round.name,
                kind: solo ? 'checkbox' : 'action',
                width: solo ? 120 : 190,
                className: solo ? 'sheet-col-solo' : 'sheet-col-team',
                headerHtml: `<span class="sheet-round-head"><span class="sheet-round-swatch" style="background-color:${escapeHtml(safeColor(round.color))}" aria-hidden="true"></span><span class="sheet-round-name">${escapeHtml(round.name)}</span><span class="sheet-round-kind">${escapeHtml(kindText)}</span></span>`,
            });
        }

        return columns;
    }

    rowKeys() {
        return [...this.model.people().map((person) => person.id), NEW_ROW];
    }

    roundSignature(roundId) {
        return `${this.model.expectedSize(roundId)}|${this.model.isNamesOnly(roundId)}`;
    }

    rememberRounds() {
        this.roundKeys = this.model.rounds().map((round) => `${round.id}|${round.name}|${round.category}|${round.color ?? ''}`).join(',');
        this.model.rounds().forEach((round) => this.roundSignatures.set(round.id, this.roundSignature(round.id)));
    }

    roundsChanged() {
        return this.model.rounds().map((round) => `${round.id}|${round.name}|${round.category}|${round.color ?? ''}`).join(',') !== this.roundKeys;
    }

    rowClass(row) {
        if (row === NEW_ROW) {
            return 'sheet-row-new';
        }

        const classes = [];

        if (this.model.isWaitlisted(row)) {
            classes.push('sheet-row-waitlisted');
        }

        if (this.model.person(row)?.local) {
            classes.push('sheet-row-local');
        }

        return classes.join(' ');
    }

    marker(key) {
        return this.context.markerFor(key);
    }

    // ---------------------------------------------------------------- cells

    cell(row, col) {
        if (row === NEW_ROW) {
            if (col === 'name') {
                return { text: '', html: `<span class="sheet-placeholder"><i class="bi bi-plus-lg" aria-hidden="true"></i> ${escapeHtml(this.t('people_new_row'))}</span>`, copy: '' };
            }

            return { text: '', readonly: true, copy: '' };
        }

        const person = this.model.person(row);

        if (person === null) {
            return { text: '', readonly: true };
        }

        if (col === 'name') {
            return this.nameCell(person);
        }

        if (col === 'country') {
            const label = person.country ? (this.context.countries[person.country] ?? person.country.toUpperCase()) : '';

            return {
                text: label,
                html: person.country ? `<span class="fi fi-${escapeHtml(person.country)} shadow-custom" aria-hidden="true"></span> ${escapeHtml(label)}` : '',
                marker: this.marker(`person:${person.id}:country`),
            };
        }

        if (col === 'player') {
            return this.playerCell(person);
        }

        const roundId = col.slice('round:'.length);
        const round = this.model.round(roundId);

        if (round === null) {
            return { text: '', readonly: true };
        }

        if (round.category === 'solo') {
            return {
                checked: this.model.placeValue(person.id, roundId) !== 'out',
                label: this.t('people_in_round_label', { round: round.name, name: person.name }),
                marker: this.marker(`place:${person.id}:${roundId}`),
            };
        }

        return this.teamCell(person, round);
    }

    nameCell(person) {
        const badges = [];

        if (person.registration?.status === 'waitlisted') {
            badges.push(`<span class="sheet-badge sheet-badge-waitlist">${escapeHtml(this.t('people_badge_waitlisted'))}</span>`);
        }

        if (person.source === 'self_joined') {
            badges.push(`<span class="sheet-badge sheet-badge-joined"><i class="bi bi-dot" aria-hidden="true"></i>${escapeHtml(this.t('people_badge_joined'))}</span>`);
        }

        const marker = this.marker(`person:${person.id}:name`) ?? this.marker(`person:${person.id}:removed`);

        return {
            text: person.name,
            html: badges.length > 0 ? `${escapeHtml(person.name)}${badges.join('')}` : undefined,
            marker,
        };
    }

    playerCell(person) {
        const marker = this.marker(`person:${person.id}:player`);
        const player = person.player;

        if (player === null || player === undefined) {
            return { text: '', html: '', marker, copy: '' };
        }

        if (player.visible !== true) {
            const text = this.t('people_profile_hidden');

            return { text, html: `<span class="sheet-muted">${escapeHtml(text)}</span>`, marker, copy: '' };
        }

        const code = player.code ? `#${String(player.code).toUpperCase()}` : '';
        const name = player.name ?? code;
        const avatar = player.avatar
            ? `<img class="sheet-avatar" src="${escapeHtml(player.avatar)}" alt="" loading="lazy" width="20" height="20">`
            : '<i class="bi bi-person-circle sheet-avatar-icon" aria-hidden="true"></i>';
        const link = player.profileUrl
            ? ` <a class="sheet-profile-link" href="${escapeHtml(player.profileUrl)}" target="_blank" rel="noopener" tabindex="-1" aria-label="${escapeHtml(this.t('people_profile_open_label', { name }))}"><i class="bi bi-box-arrow-up-right" aria-hidden="true"></i></a>`
            : '';

        return {
            text: [name, code !== name ? code : ''].filter(Boolean).join(' '),
            html: `${avatar}${escapeHtml(name)}${code && code !== name ? ` <span class="sheet-muted">${escapeHtml(code)}</span>` : ''}${link}`,
            marker,
            copy: code,
        };
    }

    teamCell(person, round) {
        const place = parsePlace(this.model.placeValue(person.id, round.id));
        const marker = this.marker(`place:${person.id}:${round.id}`);

        if (place.kind === 'out') {
            // Empty, like a spreadsheet: most people are in few rounds, and every element costs layout time
            return { text: '', marker, copy: '' };
        }

        if (place.kind === 'in') {
            const text = this.t(round.category === 'duo' ? 'people_no_pair_yet' : 'people_no_team_yet');

            return {
                text,
                html: `<span class="sheet-attention"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i> ${escapeHtml(text)}</span>`,
                marker,
                copy: '',
            };
        }

        const team = this.model.team(place.teamId);
        const name = team?.name ?? this.t('team_no_name');
        const size = this.model.sizeStatus(place.teamId);
        let sizeHtml = '';
        let sizeText = '';

        if (size.status === 'incomplete' || size.status === 'too_many') {
            sizeText = this.t('team_size_short', { count: size.count, expected: size.expected });
            sizeHtml = ` <span class="sheet-attention"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i> ${escapeHtml(sizeText)}</span>`;
        }

        return {
            text: [name, sizeText].filter(Boolean).join(' '),
            html: `${team?.name ? escapeHtml(name) : `<span class="sheet-muted">${escapeHtml(name)}</span>`}${sizeHtml}`,
            marker,
            copy: team?.name ?? '',
        };
    }

    editValue(row, col) {
        const person = this.model.person(row);

        if (person === null) {
            return '';
        }

        if (col === 'name') {
            return person.name;
        }

        if (col === 'country') {
            return person.country ? (this.context.countries[person.country] ?? person.country) : '';
        }

        return '';
    }

    // ---------------------------------------------------------------- suggestions

    countryOptions(query, person) {
        const folded = foldSearchText(query);
        const options = [];

        if (person?.country && folded === '') {
            options.push({ value: NO_COUNTRY, label: this.t('people_no_country') });
        }

        const entries = Object.entries(this.context.countries)
            .map(([code, label]) => ({ code, label: String(label), folded: foldSearchText(label) }))
            .filter((country) => folded === '' || country.code === folded || country.folded.includes(folded))
            .sort((a, b) => {
                // A name starting with what was typed first, then by name
                const startA = a.code === folded || a.folded.startsWith(folded) ? 0 : 1;
                const startB = b.code === folded || b.folded.startsWith(folded) ? 0 : 1;

                return startA - startB || a.label.localeCompare(b.label, this.context.locale);
            });

        for (const country of entries) {
            options.push({
                value: country.code,
                label: country.label,
                html: `<span class="fi fi-${escapeHtml(country.code)} shadow-custom" aria-hidden="true"></span> ${escapeHtml(country.label)}`,
            });
        }

        return options;
    }

    suggest(row, col, query) {
        const person = this.model.person(row);

        if (col === 'country') {
            return this.countryOptions(query, person);
        }

        if (col === 'player') {
            return this.playerOptions(query, person);
        }

        return [];
    }

    async playerOptions(query, person) {
        const options = [];

        // Open / Unlink while nothing is typed (Enter, F2, Alt+Down on the cell); a search shows players only
        if (person?.player && query.trim() === '') {
            if (person.player.visible && person.player.profileUrl) {
                options.push({ value: OPEN, label: this.t('people_profile_open'), className: 'sheet-option-action' });
            }

            options.push({ value: UNLINK, label: this.t('people_profile_unlink'), className: 'sheet-option-action' });
        }

        const text = query.trim();

        if (text.length < 2 || !this.context.urls.playerSearch) {
            return { options, hint: text.length > 0 ? this.t('people_profile_min_chars') : this.t('people_profile_search_hint') };
        }

        this.searchController?.abort();
        this.searchController = typeof AbortController === 'undefined' ? null : new AbortController();
        const url = new URL(this.context.urls.playerSearch, window.location.href);
        url.searchParams.set('query', text);

        let players = [];

        try {
            const response = await fetch(url.toString(), {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                signal: this.searchController?.signal,
            });
            players = response.ok ? await response.json() : [];
        } catch (e) {
            return { options, hint: this.t('people_profile_search_failed') };
        }

        for (const player of Array.isArray(players) ? players : []) {
            const elsewhere = this.model.people().find((other) => other.id !== person?.id && other.player?.id === player.key);
            const country = player.country ? (this.context.countries[player.country] ?? '') : '';
            options.push({
                value: player.key,
                label: player.label,
                detail: [player.code ? `#${player.code}` : '', country, elsewhere ? this.t('people_profile_linked_to', { name: elsewhere.name }) : ''].filter(Boolean).join(' · '),
                html: `${player.country ? `<span class="fi fi-${escapeHtml(player.country)} shadow-custom" aria-hidden="true"></span> ` : ''}${escapeHtml(player.label)}`,
                player: { id: player.key, visible: true, name: player.label, code: player.code ?? null, country: player.country ?? null, avatar: player.avatar ?? null, profileUrl: null },
            });
        }

        return { options, hint: players.length === 0 ? this.t('people_profile_none') : '' };
    }

    // ---------------------------------------------------------------- editing

    /** An action performed through the controller; its client refusals come back as the editor's error. */
    perform(action) {
        const blocked = action.groups.length === 0 && action.errors.length > 0;
        const outcome = this.context.act(action, { quiet: blocked });

        if (blocked) {
            return { error: this.context.reasonText(outcome.errors[0].reason) };
        }

        return undefined;
    }

    commit(row, col, input, info) {
        if (row === NEW_ROW) {
            return this.commitNewPerson(col, input);
        }

        const person = this.model.person(row);

        if (person === null) {
            return undefined;
        }

        const rows = info.fill ? [...new Set(info.cells.filter((cell) => cell.col === col && cell.row !== NEW_ROW).map((cell) => cell.row))] : [row];

        if (col === 'name') {
            if (info.fill && rows.length > 1) {
                return this.perform(setFields(this.model, 'name', rows.map((personId) => ({ personId, value: input.text })), this.options()));
            }

            return this.perform(setField(this.model, row, 'name', input.text, this.options()));
        }

        if (col === 'country') {
            const code = this.countryFrom(input);

            if (code === undefined) {
                return { error: this.t('people_country_pick') };
            }

            return this.perform(setFields(this.model, 'country', rows.map((personId) => ({ personId, value: code })), this.options()));
        }

        if (col === 'player') {
            const option = input.option;

            if (option?.value === OPEN) {
                window.open(person.player?.profileUrl ?? '', '_blank', 'noopener');

                return undefined;
            }

            if (option?.value === UNLINK) {
                return this.perform(linkProfile(this.model, row, null, this.options()));
            }

            if (option?.player) {
                return this.perform(linkProfile(this.model, row, option.player, this.options()));
            }

            if (input.text.trim() === '') {
                return undefined;
            }

            return { error: this.t('people_profile_pick') };
        }

        return undefined;
    }

    commitNewPerson(col, input) {
        if (col !== 'name') {
            return undefined;
        }

        const name = input.text.trim();

        if (name === '') {
            return undefined;
        }

        const action = addPerson(this.model, { name }, this.options());
        const error = this.perform(action);

        if (error) {
            return error;
        }

        const same = this.model.peopleNamed(name).filter((other) => other.id !== action.personId);

        if (same.length > 0) {
            this.context.announce(this.t('people_same_name', { name: same[0].name }));
        }

        this.context.announce(this.t('people_added', { name }));

        return {
            // Enter: the next name goes into the new-person row again; Tab: the new person's next cell
            focus: (move) => {
                const columnKey = this.columns[move.col]?.key ?? 'name';

                return columnKey === 'name' ? { row: NEW_ROW, col: 'name' } : { row: action.personId, col: columnKey };
            },
        };
    }

    /** A country code from the editor: a picked option, an empty field (no country), a typed name or code. */
    countryFrom(input) {
        if (input.option) {
            return input.option.value === NO_COUNTRY ? null : input.option.value;
        }

        return this.readCountry(input.text);
    }

    /** A typed or pasted country: '' = none, a code ("cz", "CZ") or a name in the page's language; undefined = unknown. */
    readCountry(text) {
        const value = foldSearchText(trimCell(text));

        if (value === '') {
            return null;
        }

        if (value in this.context.countries) {
            return value;
        }

        for (const [code, label] of Object.entries(this.context.countries)) {
            if (foldSearchText(label) === value) {
                return code;
            }
        }

        return undefined;
    }

    options() {
        return { countries: this.context.countryCodes };
    }

    toggle(cells, value) {
        const byRound = new Map();

        for (const { row, col } of cells) {
            if (row === NEW_ROW || !col.startsWith('round:')) {
                continue;
            }

            const roundId = col.slice('round:'.length);
            byRound.set(roundId, [...(byRound.get(roundId) ?? []), row]);
        }

        if (byRound.size === 0) {
            return;
        }

        const first = cells.find(({ row, col }) => row !== NEW_ROW && col.startsWith('round:'));
        const target = value ?? (this.model.placeValue(first.row, first.col.slice('round:'.length)) === 'out');
        const actions = [...byRound].map(([roundId, people]) => setInRound(this.model, people, roundId, target, this.options()));
        this.performMany(actions, { key: target ? 'round_in' : 'round_out' });
    }

    clear(cells) {
        const byColumn = new Map();

        for (const { row, col } of cells) {
            if (row !== NEW_ROW) {
                byColumn.set(col, [...(byColumn.get(col) ?? []), row]);
            }
        }

        const actions = [];

        for (const [col, rows] of byColumn) {
            if (col === 'name') {
                this.context.announce(this.t('people_name_required'));
            } else if (col === 'country') {
                actions.push(setFields(this.model, 'country', rows.map((personId) => ({ personId, value: null })), this.options()));
            } else if (col === 'player') {
                actions.push(...rows.map((personId) => linkProfile(this.model, personId, null, this.options())));
            } else if (col.startsWith('round:') && this.model.round(col.slice(6))?.category === 'solo') {
                actions.push(setInRound(this.model, rows, col.slice(6), false, this.options()));
            } else if (col.startsWith('round:')) {
                this.context.announce(this.t('people_team_in_round_tab'));
            }
        }

        this.performMany(actions, { key: 'clear' });
    }

    /** Several builder actions as one undo step; client refusals announced (the rest goes on). */
    performMany(actions, label) {
        const action = combine(label, ...actions);

        if (isEmpty(action) && action.errors.length === 0) {
            return;
        }

        this.context.act(action);
    }

    fill(kind, range, active) {
        const rows = range.rows.filter((row) => row !== NEW_ROW);
        const cols = range.cols.filter((col) => col === 'country' || this.isSoloColumn(col));

        if (cols.length === 0) {
            this.context.announce(this.t('people_fill_columns'));

            return;
        }

        const actions = [];

        for (const col of cols) {
            let source;
            let targets;

            if (kind === 'down') {
                if (rows.length === 1) {
                    const index = this.grid.rows.indexOf(rows[0]);
                    source = this.grid.rows[index - 1];
                    targets = rows;
                } else {
                    [source, ...targets] = rows;
                }
            } else {
                source = active.row;
                targets = rows;
            }

            if (!source || source === NEW_ROW || this.model.person(source) === null) {
                continue;
            }

            if (col === 'country') {
                const value = this.model.person(source).country;
                actions.push(setFields(this.model, 'country', targets.map((personId) => ({ personId, value })), this.options()));
            } else {
                const roundId = col.slice('round:'.length);
                actions.push(setInRound(this.model, targets, roundId, this.model.placeValue(source, roundId) !== 'out', this.options()));
            }
        }

        this.performMany(actions, { key: 'fill' });
    }

    isSoloColumn(col) {
        return col.startsWith('round:') && this.model.round(col.slice('round:'.length))?.category === 'solo';
    }

    activate(row, col) {
        if (row === NEW_ROW || !col.startsWith('round:')) {
            return;
        }

        const roundId = col.slice('round:'.length);
        const place = parsePlace(this.model.placeValue(row, roundId));
        this.context.switchTab(roundId, { personId: row, teamId: place.teamId });
    }

    // ---------------------------------------------------------------- paste

    /**
     * A block from a spreadsheet: one value onto a selection fills it; otherwise the block lands at the active cell,
     * column by column - names, countries (a code or a name), solo rounds (TRUE/FALSE, x, yes/no...). Nothing invalid is
     * dropped silently: unknown countries, unreadable checkboxes, profiles and pair/team cells are listed. More than a
     * few rows - or anything left out - are previewed first (the server's dry run included).
     */
    paste(anchor, block, selected) {
        const plan = this.planPaste(anchor, block, selected);

        if (plan.changes === 0 && plan.problems.length === 0) {
            this.context.announce(this.t('people_paste_nothing'));

            return;
        }

        if (plan.rows <= PREVIEW_ABOVE_ROWS && plan.problems.length === 0) {
            this.performMany(plan.actions, { key: 'paste' });
            this.context.announce(this.texts.tc('people_pasted', plan.changes));

            return;
        }

        this.previewPaste(plan);
    }

    planPaste(anchor, block, selected) {
        const rows = this.grid.rows;
        const columns = this.columns;
        const startRow = rows.indexOf(anchor.row);
        const startCol = columns.findIndex((column) => column.key === anchor.col);
        const cells = [];

        if (block.length === 1 && block[0].length === 1 && selected.length > 1) {
            // One value onto a selection fills it
            for (const cell of selected) {
                cells.push({ row: cell.row, col: cell.col, value: block[0][0] });
            }
        } else {
            block.forEach((values, rowOffset) => {
                values.forEach((value, colOffset) => {
                    cells.push({ row: rows[startRow + rowOffset] ?? null, col: columns[startCol + colOffset]?.key ?? null, value });
                });
            });
        }

        const problems = [];
        const names = [];
        const countries = [];
        const rounds = new Map();
        let beyond = 0;
        const touchedRows = new Set();

        for (const { row, col, value } of cells) {
            if (row === null || row === NEW_ROW) {
                beyond++;
                continue;
            }

            const person = this.model.person(row);

            if (person === null || col === null) {
                continue;
            }

            touchedRows.add(row);

            if (col === 'name') {
                if (trimCell(value) === '') {
                    problems.push({ text: person.name, note: this.t('people_name_required'), status: 'error' });
                } else {
                    names.push({ personId: row, value: trimCell(value) });
                }
            } else if (col === 'country') {
                const code = this.readCountry(value);

                if (code === undefined) {
                    problems.push({ text: person.name, note: this.t('people_paste_unknown_country', { value: trimCell(value) }), status: 'error' });
                } else {
                    countries.push({ personId: row, value: code });
                }
            } else if (this.isSoloColumn(col)) {
                const inRound = readBoolean(value);
                const roundId = col.slice('round:'.length);

                if (inRound === null) {
                    problems.push({ text: person.name, note: this.t('people_paste_unknown_check', { value: trimCell(value), round: this.model.round(roundId)?.name ?? '' }), status: 'error' });
                } else {
                    const lists = rounds.get(roundId) ?? { in: [], out: [] };
                    lists[inRound ? 'in' : 'out'].push(row);
                    rounds.set(roundId, lists);
                }
            } else if (trimCell(value) !== '') {
                const column = columns.find((candidate) => candidate.key === col);
                problems.push({ text: person.name, note: this.t('people_paste_column_skipped', { column: column?.label ?? '' }), status: 'skip' });
            }
        }

        const actions = [
            setFields(this.model, 'name', names, this.options()),
            setFields(this.model, 'country', countries, this.options()),
            ...[...rounds].flatMap(([roundId, lists]) => [
                setInRound(this.model, lists.in, roundId, true, this.options()),
                setInRound(this.model, lists.out, roundId, false, this.options()),
            ]),
        ];

        for (const action of actions) {
            for (const error of action.errors) {
                problems.push({ text: this.model.person(error.change.participant ?? error.change.id)?.name ?? '', note: this.context.reasonText(error.reason), status: 'error' });
            }
        }

        if (beyond > 0) {
            problems.push({ text: this.texts.tc('people_paste_beyond', beyond), note: this.t('people_paste_beyond_note'), status: 'skip' });
        }

        return {
            actions,
            problems,
            rows: touchedRows.size,
            changes: actions.reduce((sum, action) => sum + action.groups.length, 0),
        };
    }

    async previewPaste(plan) {
        const action = combine({ key: 'paste' }, ...plan.actions);
        const lines = action.groups.map((group) => ({ id: group.id, ...this.describeGroup(group), status: 'change' }));
        const errorLines = plan.problems.map((problem, index) => ({ id: `p${index}`, ...problem }));
        let goes = action.groups.map((group) => group.id);
        const dialog = this.context.preview({
            title: this.t('people_paste_title'),
            intro: this.t('people_paste_intro'),
            counts: this.pasteCounts(lines.length, plan.problems),
            lines: [...errorLines, ...lines],
            loading: action.groups.length > 0,
            confirmLabel: this.texts.tc('people_paste_confirm', action.groups.length),
            returnFocus: () => this.grid?.focusActive({ scroll: false }),
        });

        if (action.groups.length > 0) {
            // The server's rules (results, profiles linked elsewhere) before anything is applied
            const answer = await this.context.queue.preview(action.groups);
            const refused = new Map();

            if (answer.kind === 'ok') {
                for (const group of answer.data?.groups ?? []) {
                    if (group.status === 'refused' || group.status === 'conflict') {
                        const change = group.changes.find((candidate) => candidate.status === 'refused' || candidate.status === 'conflict');
                        refused.set(group.id, change?.message ?? this.t('people_paste_not_possible'));
                    }
                }
            }

            const checked = lines.map((line) => (refused.has(line.id) ? { ...line, status: 'error', note: refused.get(line.id) } : line));
            goes = goes.filter((id) => !refused.has(id));
            dialog.update({
                loading: false,
                lines: [...errorLines, ...checked],
                counts: this.pasteCounts(goes.length, [...plan.problems, ...refused.values()]),
                confirmLabel: this.texts.tc('people_paste_confirm', goes.length),
                message: answer.kind === 'ok' ? '' : this.t('people_paste_unchecked'),
            });
        }

        const selection = await dialog.result;

        if (selection === null) {
            this.context.announce(this.t('people_paste_cancelled'));

            return;
        }

        const keep = new Set(goes);
        const groups = action.groups.filter((group) => keep.has(group.id));

        if (groups.length === 0) {
            return;
        }

        // Applied as previewed - from-values are rechecked by the server, a change meanwhile comes back as a conflict
        this.context.act({ ...action, groups, inverse: action.inverse.filter((group) => keep.has(group.inverseOf)), errors: [] });
        this.context.announce(this.texts.tc('people_pasted', groups.length));
    }

    pasteCounts(changes, problems) {
        const counts = [{ text: this.texts.tc('people_paste_count_changes', changes) }];

        if (problems.length > 0) {
            counts.push({ text: this.texts.tc('people_paste_count_problems', problems.length), tone: 'danger' });
        }

        return counts;
    }

    /** A line of the preview: whose what changes to what. */
    describeGroup(group) {
        const change = group.changes[0];
        const person = this.model.person(change.participant ?? change.id);
        const name = person?.name ?? '';

        if (change.op === 'field' && change.field === 'country') {
            return { text: name, note: this.t('people_change_country', { from: this.countryLabel(change.from), to: this.countryLabel(change.to) }) };
        }

        if (change.op === 'field') {
            return { text: name, note: this.t('people_change_name', { from: change.from ?? '', to: change.to ?? '' }) };
        }

        if (change.op === 'place') {
            const round = this.model.round(change.round)?.name ?? '';

            return { text: name, note: this.t(change.to === 'out' ? 'people_change_round_out' : 'people_change_round_in', { round }) };
        }

        return { text: name, note: '' };
    }

    countryLabel(code) {
        return code ? (this.context.countries[code] ?? code.toUpperCase()) : this.t('people_no_country');
    }
}

/** Round colours come from the server as #hex; anything else is not put into a style attribute. */
function safeColor(color) {
    return typeof color === 'string' && /^#[0-9a-f]{3,8}$/i.test(color) ? color : 'transparent';
}
