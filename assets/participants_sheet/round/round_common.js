/**
 * What the participants spreadsheet's round views share (team_round_view.js, solo_round_view.js, round_cards_view.js):
 * labels of pairs/teams (O1), where a person is in a round, the people/team pickers' options (member cells, the tray's
 * "Pair with…" / "Add to…", "Add people to this round"), the size text (O7), the row order, the round's entries for
 * ranks and results, and one small dialog widget (menus and pickers - a native modal `<dialog>`: focus inside, Esc
 * closes, focus back where it was).
 *
 * The functions are pure (pinned by tests/participants-sheet-round-harness.mjs); RoundDialog touches the DOM only when
 * opened, RoundResultsCells / previewResults() only through the view's context. Texts are the round texts
 * (`context.texts.round`) - `t(key, params)` / `tc(key, count, params)`.
 */

import { escapeHtml } from '../sheet_grid.js';
import { cleanTeamName, hasOfficialData, nameKey, parsePlace } from '../sheet_model.js';
import { keepMineInEditor, openEditor, savedMeanwhile } from '../../official_results_pending_changes.js';
import {
    enteredLabel,
    officialEdit,
    officialEdits,
    parseResultInput,
    parseTableNumber,
    parsedValue,
    resultKind,
    resultPreview,
    resultText,
    sameValue,
    swapAsResults,
    swapAssignments,
    tableHolder,
    wordList,
} from '../sheet_results.js';
import { SKIP, chosenResultChanges } from '../round_paste.js';

export const CREATE = '__create';
export const OPTION_LIMIT = 30;

/** A collator of the page's language for names (Intl may not know a locale - English then). */
export function nameCollator(locale) {
    try {
        return new Intl.Collator(locale, { sensitivity: 'base', numeric: true });
    } catch (e) {
        return new Intl.Collator('en', { sensitivity: 'base', numeric: true });
    }
}

function compareIds(a, b) {
    if (a === b) {
        return 0;
    }

    return a < b ? -1 : 1;
}

/** Round colours come from the server as #hex; anything else is not put into a style attribute. */
export function safeColor(color) {
    return typeof color === 'string' && /^#[0-9a-f]{3,8}$/i.test(color) ? color : 'transparent';
}

export function flagHtml(country) {
    return country && /^[a-z]{2}$/.test(country) ? `<span class="fi fi-${escapeHtml(country)} shadow-custom" aria-hidden="true"></span> ` : '';
}

export function isTeamRound(round) {
    return round !== null && round !== undefined && round.category !== 'solo';
}

/** The round uses table numbers (in person, not switched off) - else its first column is a plain row index. */
export function usesTables(model, round) {
    return model.competition?.isOnline !== true && round?.tableNumbersOff !== true;
}

// ---------------------------------------------------------------- labels

/**
 * O1: `Corners · Table 2 · Kim Example, Pat Sample` (no "Table n" without one, `(no name)` for an unnamed one).
 *
 * @param {{t: function}} texts round texts: team_no_name, label_table
 * @param {{members?: boolean, table?: number|null}} [options] `table` = the number the page shows (unsaved included)
 */
export function teamLabelText(model, teamId, texts, { members = true, table = undefined } = {}) {
    const label = model.teamLabel(teamId);
    const shownTable = table === undefined ? label.table : table;
    const parts = [label.name ?? texts.t('team_no_name')];

    if (shownTable !== null && shownTable !== undefined) {
        parts.push(texts.t('label_table', { table: shownTable }));
    }

    if (members && label.members.length > 0) {
        parts.push(label.members.join(', '));
    }

    return parts.join(' · ');
}

/** "Table 12 · Pinecones" - a pair/team without its members (where somebody is); an unnamed one keeps them (O1). */
export function teamShortLabel(model, teamId, texts) {
    return teamLabelText(model, teamId, texts, { members: model.team(teamId)?.name === null });
}

/**
 * Where a person is in a round, in words: `{kind: 'team'|'tray'|'out', teamId, text}` - "Table 12 · Pinecones",
 * "in the round, no pair yet", "not in this round".
 */
export function whereInRound(model, personId, roundId, texts) {
    const place = parsePlace(model.placeValue(personId, roundId));
    const round = model.round(roundId);

    if (place.kind === 'team') {
        return { kind: 'team', teamId: place.teamId, text: teamShortLabel(model, place.teamId, texts) };
    }

    if (place.kind === 'in') {
        return { kind: 'tray', teamId: null, text: texts.t(round?.category === 'duo' ? 'where_tray_pair' : (round?.category === 'team' ? 'where_tray_team' : 'where_in')) };
    }

    return { kind: 'out', teamId: null, text: texts.t('where_out') };
}

// ---------------------------------------------------------------- sizes (O7)

/**
 * The size cell: `2/2`, `⚠ 1/2 - incomplete`, `⚠ 3/2 - a pair has 2 people` / `- a team of this round has 4`,
 * `No members listed`, plus `· same name as Table 2` / `· same name as the pair with Kim Example`.
 *
 * @returns {{text: string, warn: boolean, status: string, sameAs: string}}
 */
export function sizeInfo(model, teamId, texts) {
    const team = model.team(teamId);
    const round = team ? model.round(team.roundId) : null;
    const size = model.sizeStatus(teamId);
    const duo = round?.category === 'duo';
    let text;
    let warn = false;

    switch (size.status) {
        case 'incomplete':
            text = texts.t('size_incomplete', { count: size.count, expected: size.expected });
            warn = true;
            break;
        case 'too_many':
            text = texts.t(duo ? 'size_too_many_pair' : 'size_too_many_team', { count: size.count, expected: size.expected });
            warn = true;
            break;
        case 'empty':
        case 'names_only':
            text = texts.t('size_no_members');
            break;
        default:
            text = texts.t('size_complete', { count: size.count, expected: size.expected ?? size.count });
    }

    let sameAs = '';
    const others = team ? (model.sameNameTeams(team.roundId).get(teamId) ?? []) : [];

    if (others.length > 0) {
        const other = model.team(others[0]);
        const members = model.membersOf(others[0]).map((person) => person.name);

        if (other?.table !== null && other?.table !== undefined) {
            sameAs = texts.t('size_same_name_table', { table: other.table });
        } else if (members.length > 0) {
            sameAs = texts.t(duo ? 'size_same_name_pair' : 'size_same_name_team', { members: members.join(', ') });
        } else {
            sameAs = texts.t(duo ? 'size_same_name_other_pair' : 'size_same_name_other_team');
        }
    }

    return { text, warn, status: size.status, sameAs };
}

// ---------------------------------------------------------------- order of rows

/**
 * Pairs/teams of a round by table number (none last), then name (the locale's collation; unnamed ones by their
 * members), then id.
 */
export function sortedTeamIds(model, roundId, collator) {
    const display = (team) => team.name ?? model.membersOf(team.id).map((person) => person.name).join(', ');

    return model.teamsOf(roundId).slice().sort((a, b) => compareTables(a.table, b.table)
        || collator.compare(display(a), display(b))
        || compareIds(a.id, b.id)).map((team) => team.id);
}

/** People of a solo round by table number (none last), then name, then id. */
export function sortedPeopleIds(model, roundId, collator) {
    return model.peopleIn(roundId).slice().sort((a, b) => compareTables(model.place(a.id, roundId)?.table, model.place(b.id, roundId)?.table)
        || collator.compare(a.name, b.name)
        || compareIds(a.id, b.id)).map((person) => person.id);
}

function compareTables(a, b) {
    const noneA = a === null || a === undefined;
    const noneB = b === null || b === undefined;

    if (noneA || noneB) {
        return (noneA ? 1 : 0) - (noneB ? 1 : 0);
    }

    return a - b;
}

/**
 * The rows keep their order while the organiser works ("no jumping"): known ids stay where they were, new ones join
 * at the end, gone ones leave. "Sort" orders them again.
 */
export function keepOrder(previous, current) {
    const wanted = new Set(current);
    const kept = previous.filter((id) => wanted.has(id));
    const known = new Set(kept);

    return [...kept, ...current.filter((id) => !known.has(id))];
}

/**
 * The member slots of a pair/team as the grid shows them: members keep their column while the organiser works, a
 * member typed into column k lands in column k (`intent` = {personId, index}), a cleared one leaves and the rest move
 * up - no gaps (members have no order on the server).
 */
export function memberSlots(previous, memberIds, intent = null) {
    const current = new Set(memberIds);
    const slots = (previous ?? []).filter((id) => current.has(id));
    const placed = new Set(slots);

    for (const id of memberIds) {
        if (placed.has(id)) {
            continue;
        }

        if (intent !== null && intent.personId === id) {
            slots.splice(Math.min(Math.max(0, intent.index), slots.length), 0, id);
        } else {
            slots.push(id);
        }

        placed.add(id);
    }

    return slots;
}

// ---------------------------------------------------------------- pickers

function matchRank(key, query) {
    if (query === '') {
        return 4;
    }

    if (key === query) {
        return 0;
    }

    if (key.startsWith(query)) {
        return 1;
    }

    if (key.split(' ').some((word) => word.startsWith(query))) {
        return 2;
    }

    return key.includes(query) ? 3 : -1;
}

const WHERE_ORDER = { tray: 0, out: 1, team: 2 };

/**
 * The people a member cell (or "+ Add partner", "Pair with…", "Add people to this round") offers: the event's active
 * people matching the query (accents, case and apostrophes ignored), exact names first, then people in the round
 * without a pair/team, then people not in it, then people of other pairs/teams ("moves from Table 12 · Pinecones");
 * the last option `+ Add "Jo Do" as a new participant` when nobody is called exactly that (D9 - never picked by Enter
 * alone). An empty query lists the round's people without a pair/team.
 *
 * @param {{teamId?: string|null, only?: 'tray'|'out'|null, exclude?: Set<string>, create?: boolean, limit?: number,
 *          countries?: object}} [options] teamId = the pair/team being edited (its own members say so)
 * @returns {Array<{value: string, label: string, detail: string, html: string, personId?: string, create?: boolean,
 *          name?: string, moveFrom?: string|null}>}
 */
export function personOptions(model, roundId, query, texts, { teamId = null, only = null, exclude = new Set(), create = true, limit = OPTION_LIMIT, countries = {} } = {}) {
    const folded = nameKey(query);
    const candidates = [];

    for (const person of model.people()) {
        if (exclude.has(person.id)) {
            continue;
        }

        const where = whereInRound(model, person.id, roundId, texts);

        if (only !== null && where.kind !== only) {
            continue;
        }

        const rank = matchRank(nameKey(person.name), folded);

        if (rank === -1 || (folded === '' && where.kind !== 'tray' && only === null)) {
            continue;
        }

        candidates.push({ person, where, rank });
    }

    const order = model.peopleRank();
    candidates.sort((a, b) => a.rank - b.rank
        || (WHERE_ORDER[a.where.kind] ?? 3) - (WHERE_ORDER[b.where.kind] ?? 3)
        || (order.get(a.person.id) ?? 0) - (order.get(b.person.id) ?? 0));

    const options = candidates.slice(0, limit).map(({ person, where }) => {
        const own = teamId !== null && where.teamId === teamId;
        const moves = where.kind === 'team' && !own;
        const parts = [];

        if (person.country) {
            parts.push(countries[person.country] ?? person.country.toUpperCase());
        }

        parts.push(own ? texts.t('where_this_team') : (moves && teamId !== undefined ? texts.t('moves_from', { where: where.text }) : where.text));

        if (model.isWaitlisted(person.id)) {
            parts.push(texts.t('waitlisted'));
        }

        return {
            value: person.id,
            personId: person.id,
            label: person.name,
            detail: parts.filter(Boolean).join(' · '),
            html: `${flagHtml(person.country)}${escapeHtml(person.name)}`,
            moveFrom: moves ? where.teamId : null,
            where: where.kind,
        };
    });

    const name = String(query ?? '').trim();

    if (create && name !== '' && model.peopleNamed(name).length === 0) {
        options.push({ value: CREATE, create: true, name, label: texts.t('add_new_person', { name }), detail: '' });
    }

    return options;
}

/**
 * The pairs/teams of a round a person can be added to ("Add to…"), labelled per O1, matching the query.
 */
export function teamOptions(model, roundId, query, texts, { exclude = new Set(), limit = OPTION_LIMIT } = {}) {
    const folded = nameKey(query);
    const options = [];

    for (const team of model.teamsOf(roundId)) {
        if (exclude.has(team.id)) {
            continue;
        }

        const label = teamLabelText(model, team.id, texts);

        if (folded !== '' && !nameKey(label).includes(folded)) {
            continue;
        }

        options.push({ value: team.id, teamId: team.id, label, detail: sizeInfo(model, team.id, texts).text });
    }

    return options.slice(0, limit);
}

// ---------------------------------------------------------------- entries (ranks, results)

/**
 * The round's results entries as the page shows them (the organiser's unsaved values included): solo = the people of
 * the round going to the event (waitlisted ones are ignored by the results tools - O8), pair/team = its pairs/teams.
 * `ref` is null for a place created on this page and not known to the server yet.
 *
 * @param {object} pending the round's PendingChanges (queue.results(roundId)) or null
 * @returns {Array<{id: string, ref: string|null, kind: 'person'|'team', personId?: string, teamId?: string,
 *          displayName: string, names: string[], table: number|null, tableNumber: number|null, result: object|null,
 *          serverResult: object|null, qualified: boolean, serverTable: number|null, serverQualified: boolean,
 *          enteredBy: string|null, enteredAt: string|null}>}
 */
export function roundEntries(model, roundId, pending, texts = null) {
    const round = model.round(roundId);
    const entries = [];
    const shown = (ref, field, value) => (ref !== null && pending ? pending.value(ref, field, value) : value);

    if (round === null) {
        return entries;
    }

    if (round.category === 'solo') {
        for (const person of model.peopleIn(roundId)) {
            if (model.isWaitlisted(person.id)) {
                continue;
            }

            const place = model.place(person.id, roundId);
            const ref = model.entryRef(person.id, roundId);
            const table = shown(ref, 'table_number', place.table);
            entries.push({
                id: place.id,
                ref,
                kind: 'person',
                personId: person.id,
                displayName: person.name,
                names: [person.name],
                table,
                tableNumber: table,
                result: shown(ref, 'result', place.result),
                serverResult: place.result,
                qualified: shown(ref, 'qualified', place.qualified) === true,
                serverTable: place.table,
                serverQualified: place.qualified === true,
                enteredBy: place.enteredBy,
                enteredAt: place.enteredAt,
            });
        }

        return entries;
    }

    for (const team of model.teamsOf(roundId)) {
        const ref = `team:${team.id}`;
        const members = model.membersOf(team.id).map((person) => person.name);
        const table = shown(ref, 'table_number', team.table);
        entries.push({
            id: team.id,
            ref,
            kind: 'team',
            teamId: team.id,
            displayName: team.name ?? (members.join(', ') || (texts ? texts.t('team_no_name') : '')),
            names: [team.name, ...members].filter(Boolean),
            table,
            tableNumber: table,
            result: shown(ref, 'result', team.result),
            serverResult: team.result,
            qualified: shown(ref, 'qualified', team.qualified) === true,
            serverTable: team.table,
            serverQualified: team.qualified === true,
            enteredBy: team.enteredBy,
            enteredAt: team.enteredAt,
        });
    }

    return entries;
}

/** The round holds official data (any result or qualified mark) - its results columns show by default (O3). */
export function roundHasResults(model, roundId) {
    const round = model.round(roundId);

    if (round === null) {
        return false;
    }

    if (round.category === 'solo') {
        return (model.placesByRound().get(roundId) ?? []).some((place) => hasOfficialData(place));
    }

    return model.teamsOf(roundId).some((team) => hasOfficialData(team));
}

/** Results / rank / qualified columns shown: the organiser's choice for the round, else started or holding results. */
export function resultsColumnsShown(model, roundId, storage = null) {
    const stored = readStored(storage, roundId);

    if (stored !== null) {
        return stored;
    }

    return model.round(roundId)?.started === true || roundHasResults(model, roundId);
}

const STORAGE_PREFIX = 'participants-sheet:results-columns:';

function readStored(storage, roundId) {
    try {
        const value = storage?.getItem(`${STORAGE_PREFIX}${roundId}`) ?? null;

        return value === '1' ? true : (value === '0' ? false : null);
    } catch (e) {
        return null;
    }
}

export function storeResultsColumns(storage, roundId, shown) {
    try {
        storage?.setItem(`${STORAGE_PREFIX}${roundId}`, shown ? '1' : '0');
    } catch (e) {
        // Private mode, blocked storage: the choice lasts until the page is left
    }
}

/** The cleaned name of a pasted / typed team, null = no name. */
export function teamNameOf(text) {
    return cleanTeamName(text);
}

/** The localStorage of the page, when there is one and it answers. */
export function pageStorage() {
    try {
        return typeof window !== 'undefined' ? window.localStorage : null;
    } catch (e) {
        return null;
    }
}

// ---------------------------------------------------------------- the dialog widget

let dialogCounter = 0;

/**
 * A small modal `<dialog>` for the round views - a **menu** (buttons, a disabled one says why) or a **picker** (a
 * search field + a list of options, combobox keys: arrows, Enter, Esc). Desktop: a panel next to the element it was
 * opened from; phone (`sheet: true`): a bottom sheet, the picker full screen. Resolves to the chosen item or null; the
 * focus goes back to `returnFocus` (default: where it was).
 *
 *   const choice = await new RoundDialog({host, title, items: [{value, label, detail?, disabled?, reason?, danger?}]}).open();
 *   const picked = await new RoundDialog({host, title, picker: {placeholder, options: (query) => [...], hint?}}).open();
 */
export class RoundDialog {
    /**
     * @param {object} options
     * @param {HTMLElement} options.host
     * @param {string} options.title
     * @param {string} [options.description]
     * @param {Array<object>} [options.items]       menu items
     * @param {{label: string, placeholder?: string, options: function(string): Array<object>, empty?: string,
     *          none?: string, extra?: {label: string, placeholder?: string}, submit?: {label: string}}} [options.picker]
     *        `submit` = a button resolving {submit: true, query, extra} (e.g. "Create with this name only")
     * @param {{label: string, value?: string, placeholder?: string, submitLabel: string}} [options.form] one text
     *        field (rename) - resolves {value}
     * @param {HTMLElement|null} [options.anchor]   desktop: shown next to it
     * @param {boolean} [options.sheet]             phone: bottom sheet / full screen
     * @param {string} options.closeLabel
     * @param {function(): void} [options.returnFocus]
     */
    constructor(options) {
        this.options = options;
        this.id = `sheet-round-dialog-${++dialogCounter}`;
        this.active = -1;
        this.list = [];
        this.result = new Promise((resolve) => {
            this.resolve = resolve;
        });
        this.settled = false;
    }

    open() {
        const { title, description, items, picker, form, sheet, closeLabel } = this.options;
        this.returnTo = document.activeElement;
        const dialog = document.createElement('dialog');
        dialog.className = `sheet-round-dialog${sheet ? ' is-sheet' : ''}${picker ? ' is-picker' : ''}`;
        dialog.setAttribute('aria-labelledby', `${this.id}-title`);

        const formBody = form
            ? `<form class="sheet-round-dialog-form" novalidate>
                <label class="form-label small mb-1" for="${this.id}-field">${escapeHtml(form.label)}</label>
                <input type="text" class="form-control mb-2" id="${this.id}-field" autocomplete="off" value="${escapeHtml(form.value ?? '')}" placeholder="${escapeHtml(form.placeholder ?? '')}">
                <button type="submit" class="btn btn-primary w-100">${escapeHtml(form.submitLabel)}</button>
            </form>`
            : '';
        const body = form ? formBody : picker
            ? `${picker.extra ? `<label class="form-label small mb-1" for="${this.id}-extra">${escapeHtml(picker.extra.label)}</label>
                <input type="text" class="form-control sheet-round-dialog-extra mb-2" id="${this.id}-extra" autocomplete="off" placeholder="${escapeHtml(picker.extra.placeholder ?? '')}">` : ''}
                <label class="form-label small mb-1" for="${this.id}-input">${escapeHtml(picker.label)}</label>
                <input type="text" class="form-control sheet-round-dialog-input" id="${this.id}-input" role="combobox" aria-autocomplete="list" aria-expanded="true" aria-controls="${this.id}-list" autocomplete="off" spellcheck="false" placeholder="${escapeHtml(picker.placeholder ?? '')}">
                <ul class="sheet-round-dialog-options list-unstyled" role="listbox" id="${this.id}-list" aria-label="${escapeHtml(picker.label)}"></ul>
                <p class="sheet-round-dialog-hint small text-body-secondary mb-0" aria-live="polite"></p>
                ${picker.submit ? `<button type="button" class="btn btn-outline-primary w-100 mt-2" data-dialog-submit>${escapeHtml(picker.submit.label)}</button>` : ''}`
            : `<ul class="sheet-round-dialog-menu list-unstyled mb-0">${(items ?? []).map((item, index) => `<li><button type="button" class="sheet-round-dialog-item${item.danger ? ' is-danger' : ''}" data-index="${index}"${item.disabled ? ' aria-disabled="true"' : ''}${item.reason ? ` aria-describedby="${this.id}-reason-${index}"` : ''}>${item.icon ? `<i class="bi ${escapeHtml(item.icon)}" aria-hidden="true"></i> ` : ''}<span>${escapeHtml(item.label)}</span>${item.detail ? `<small class="d-block text-body-secondary">${escapeHtml(item.detail)}</small>` : ''}${item.reason ? `<small class="d-block sheet-round-dialog-reason" id="${this.id}-reason-${index}">${escapeHtml(item.reason)}</small>` : ''}</button></li>`).join('')}</ul>`;

        dialog.innerHTML = `<div class="sheet-round-dialog-head">
                <h2 class="h6 mb-0" id="${this.id}-title">${escapeHtml(title)}</h2>
                <button type="button" class="btn-close" data-dialog-close aria-label="${escapeHtml(closeLabel)}"></button>
            </div>
            ${description ? `<p class="sheet-round-dialog-description small mb-2">${escapeHtml(description)}</p>` : ''}
            <div class="sheet-round-dialog-body">${body}</div>`;
        this.dialog = dialog;
        this.options.host.append(dialog);

        dialog.addEventListener('cancel', (event) => {
            event.preventDefault();
            this.finish(null);
        });
        dialog.addEventListener('click', (event) => this.onClick(event));
        dialog.addEventListener('keydown', (event) => this.onMenuKey(event));

        if (picker) {
            this.input = dialog.querySelector('.sheet-round-dialog-input');
            this.extra = dialog.querySelector('.sheet-round-dialog-extra');
            this.listElement = dialog.querySelector('.sheet-round-dialog-options');
            this.hint = dialog.querySelector('.sheet-round-dialog-hint');
            this.input.addEventListener('input', () => this.renderOptions());
            this.input.addEventListener('keydown', (event) => this.onInputKey(event));
            this.extra?.addEventListener('keydown', (event) => {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    this.input.focus();
                }
            });
            this.listElement.addEventListener('mousedown', (event) => event.preventDefault());
        }

        if (form) {
            this.field = dialog.querySelector(`#${this.id}-field`);
            dialog.querySelector('form').addEventListener('submit', (event) => {
                event.preventDefault();
                this.finish({ value: this.field.value });
            });
        }

        dialog.showModal();
        this.position();

        if (form) {
            this.field.focus();
            this.field.select();
        } else if (picker) {
            this.renderOptions();
            (this.extra ?? this.input).focus();
        } else {
            (dialog.querySelector('.sheet-round-dialog-item:not([aria-disabled="true"])') ?? dialog.querySelector('[data-dialog-close]'))?.focus();
        }

        return this;
    }

    /** Desktop: next to the element it was opened from, inside the viewport. */
    position() {
        const anchor = this.options.anchor;

        if (this.options.sheet || !anchor || typeof anchor.getBoundingClientRect !== 'function') {
            return;
        }

        const rect = anchor.getBoundingClientRect();
        const width = this.dialog.offsetWidth;
        const height = this.dialog.offsetHeight;
        const left = Math.max(8, Math.min(rect.left, window.innerWidth - width - 8));
        const below = rect.bottom + 4;
        const top = below + height > window.innerHeight - 8 ? Math.max(8, rect.top - height - 4) : below;
        this.dialog.classList.add('is-anchored');
        Object.assign(this.dialog.style, { left: `${Math.round(left)}px`, top: `${Math.round(top)}px` });
    }

    renderOptions() {
        const { picker } = this.options;
        const query = this.input.value;
        const answer = picker.options(query);
        this.list = Array.isArray(answer) ? answer : (answer?.options ?? []);
        const hint = Array.isArray(answer) ? '' : (answer?.hint ?? '');
        this.listElement.innerHTML = this.list.map((option, index) => `<li role="option" id="${this.id}-o${index}" class="sheet-round-dialog-option${option.create ? ' is-create' : ''}" data-index="${index}" aria-selected="false">${option.html ?? escapeHtml(option.label)}${option.detail ? ` <small class="d-block text-body-secondary">${escapeHtml(option.detail)}</small>` : ''}</li>`).join('');
        this.hint.textContent = this.list.length === 0 ? (hint || (query.trim() === '' ? (picker.empty ?? '') : (picker.none ?? ''))) : hint;
        this.highlight(this.list.length > 0 && !this.list[0].create ? 0 : -1);
    }

    highlight(index) {
        this.active = index;
        Array.from(this.listElement.children).forEach((element, position) => {
            element.setAttribute('aria-selected', position === index ? 'true' : 'false');
            element.classList.toggle('is-active', position === index);

            if (position === index) {
                element.scrollIntoView({ block: 'nearest' });
            }
        });

        if (index >= 0) {
            this.input.setAttribute('aria-activedescendant', `${this.id}-o${index}`);
        } else {
            this.input.removeAttribute('aria-activedescendant');
        }
    }

    onInputKey(event) {
        const count = this.list.length;

        if ((event.key === 'ArrowDown' || event.key === 'ArrowUp') && count > 0) {
            event.preventDefault();
            this.highlight((this.active + (event.key === 'ArrowDown' ? 1 : -1) + count) % count);

            return;
        }

        if (event.key === 'Enter') {
            event.preventDefault();

            if (this.active >= 0) {
                this.choose(this.list[this.active]);
            } else if (this.options.picker.onEnter) {
                const chosen = this.options.picker.onEnter(this.input.value, this.extra?.value ?? '');

                if (chosen) {
                    this.choose(chosen);
                }
            }
        }
    }

    /** A menu: arrows, Home and End move between its items (Tab too). */
    onMenuKey(event) {
        const items = [...this.dialog.querySelectorAll('.sheet-round-dialog-item')];
        const index = items.indexOf(document.activeElement);

        if (index === -1 || !['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) {
            return;
        }

        event.preventDefault();
        const next = { ArrowDown: index + 1, ArrowUp: index - 1, Home: 0, End: items.length - 1 }[event.key];
        items[(next + items.length) % items.length].focus();
    }

    onClick(event) {
        if (event.target === this.dialog || event.target.closest('[data-dialog-close]')) {
            this.finish(null);

            return;
        }

        if (event.target.closest('[data-dialog-submit]')) {
            this.finish({ submit: true, query: this.input?.value ?? '', extra: this.extra?.value ?? '' });

            return;
        }

        const option = event.target.closest('.sheet-round-dialog-option');

        if (option) {
            this.choose(this.list[Number(option.dataset.index)]);

            return;
        }

        const item = event.target.closest('.sheet-round-dialog-item');

        if (item) {
            const chosen = this.options.items[Number(item.dataset.index)];

            if (chosen.disabled) {
                // Says why instead of doing nothing
                this.options.onDisabled?.(chosen);

                return;
            }

            this.choose(chosen);
        }
    }

    choose(item) {
        if (!item) {
            return;
        }

        this.finish(this.extra ? { ...item, extra: this.extra.value } : item);
    }

    finish(value) {
        if (this.settled) {
            return;
        }

        this.settled = true;
        this.dialog?.close();
        this.dialog?.remove();
        this.resolve(value);

        if (typeof this.options.returnFocus === 'function') {
            this.options.returnFocus(value);
        } else if (this.returnTo && this.returnTo.isConnected) {
            this.returnTo.focus({ preventScroll: true });
        }
    }

    close() {
        this.finish(null);
    }
}

// ---------------------------------------------------------------- the results cells of the round views

/**
 * The texts, options and helpers the round views share with the results cells (team_round_view.js, solo_round_view.js).
 */
export class RoundResultsCells {
    constructor(context) {
        this.context = context;
        this.model = context.model;
        this.roundId = context.round.id;
        this.texts = context.texts.round;
    }

    t(key, params) {
        return this.texts.t(key, params);
    }

    round() {
        return this.model.round(this.roundId);
    }

    pending() {
        return this.context.queue.results(this.roundId);
    }

    resultTexts() {
        return {
            piecesPlaced: this.t('result_pieces_placed'),
            piecesPlacedOf: this.t('result_pieces_placed_of'),
            didNotStart: this.t('result_did_not_start'),
        };
    }

    previewTexts() {
        return {
            finished: this.t('result_preview_finished'),
            didNotFinish: this.t('result_preview_unfinished'),
            didNotStart: this.t('result_preview_dns'),
            noResult: this.t('result_preview_none'),
            invalid: this.t('result_invalid'),
            outOfRange: this.t('result_out_of_range'),
            piecesRange: this.t('result_pieces_range'),
        };
    }

    parseOptions() {
        return {
            piecesCount: this.round()?.piecesCount ?? null,
            piecesWords: wordList(this.t('result_pieces_words')),
            didNotStartWords: wordList(this.t('result_dns_words')),
        };
    }

    /** What a field of an entry shows: the organiser's unsaved value, else the server's. */
    shown(ref, field, serverValue) {
        return ref === null ? serverValue : this.pending().value(ref, field, serverValue);
    }

    marker(ref, field) {
        return ref === null ? null : this.context.markerFor(`result:${ref}:${field}`);
    }

    /** The cell of a result: its text, "entered by Eva · 10:42" as title and for screen readers. */
    resultCell(ref, server, enteredBy, enteredAt, { readonly = false, reason = '' } = {}) {
        const value = this.shown(ref, 'result', server);
        const text = resultText(value, this.round()?.piecesCount ?? null, this.resultTexts());
        const unsaved = ref !== null && !sameValue(value, server);
        const entered = !unsaved && value !== null ? enteredLabel(enteredBy, enteredAt, {
            locale: this.context.locale,
            timeZone: this.round()?.timezone || undefined,
            template: this.t('result_entered_by'),
        }) : '';
        let html = escapeHtml(text);

        if (entered) {
            html = `<span title="${escapeHtml(entered)}">${escapeHtml(text)}</span><span class="visually-hidden">, ${escapeHtml(entered)}</span>`;
        }

        if (readonly && reason) {
            html = `<span class="sheet-muted" title="${escapeHtml(reason)}">${escapeHtml(text || '–')}</span><span class="visually-hidden">, ${escapeHtml(reason)}</span>`;
        }

        return { text, html, copy: text, marker: this.marker(ref, 'result'), readonly, className: 'sheet-col-result' };
    }

    tableCell(ref, server, { readonly = false } = {}) {
        const value = this.shown(ref, 'table_number', server);
        const cell = ref === null ? null : this.pending().get(ref, 'table_number');
        let html = value === null || value === undefined ? '' : escapeHtml(String(value));

        if (cell !== null && cell.status === 'error' && cell.reason === 'table_number_taken') {
            const holder = this.holderOf(ref, value);
            const text = holder ? this.t('table_taken', { table: value, name: holder.displayName }) : this.t('table_taken_unknown', { table: value });
            html = `${html} <span class="sheet-attention"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i> ${escapeHtml(text)}</span>`;
        }

        return {
            text: value === null || value === undefined ? '' : String(value),
            html,
            marker: this.marker(ref, 'table_number'),
            readonly,
            className: 'sheet-col-table',
        };
    }

    rankCell(ref, rank, value, server) {
        if (rank === null || rank === undefined) {
            return { text: '', readonly: true, className: 'sheet-col-rank' };
        }

        const unsaved = ref !== null && !sameValue(value, server);

        return {
            text: String(rank),
            html: unsaved ? `${rank} <span class="sheet-muted">${escapeHtml(this.t('rank_unsaved'))}</span>` : String(rank),
            copy: String(rank),
            readonly: true,
            className: 'sheet-col-rank',
        };
    }

    qualifiedCell(ref, server, label, { readonly = false } = {}) {
        const checked = this.shown(ref, 'qualified', server) === true;

        if (readonly) {
            return { text: checked ? this.t('qualified_yes') : '', readonly: true, copy: checked ? 'TRUE' : 'FALSE' };
        }

        return { checked, label, marker: this.marker(ref, 'qualified') };
    }

    holderOf(ref, number) {
        return tableHolder(roundEntries(this.model, this.roundId, this.pending(), this.texts), number, ref);
    }

    // ---------------------------------------------------------------- editors

    /** An editor of an official field opened: what the organiser saw is the `from` of its save (openEditor()). */
    openEditor(ref, field, serverValue) {
        return ref === null ? null : openEditor(this.pending(), ref, field, serverValue);
    }

    /** Somebody else's value saved while the editor was open - {current, enteredBy} or null. */
    meanwhile(editor, serverValue, enteredBy) {
        if (editor === null) {
            return null;
        }

        const found = savedMeanwhile(this.pending(), editor, { value: serverValue, enteredBy });

        if (found === null) {
            return null;
        }

        const by = found.enteredBy;

        return { current: found.current, enteredBy: typeof by === 'object' && by !== null ? (by.name ?? null) : (by ?? null) };
    }

    meanwhileText(field, found) {
        const value = field === 'result'
            ? (resultText(found.current, this.round()?.piecesCount ?? null, this.resultTexts()) || this.t('result_preview_none'))
            : (found.current === null || found.current === undefined ? this.t('table_none') : String(found.current));

        return found.enteredBy
            ? this.t('meanwhile_by', { name: found.enteredBy, value })
            : this.t('meanwhile', { value });
    }

    /** The result column's list: the conflict's Keep mine / Take theirs, the four kinds (Alt+Down), or the parse hint. */
    resultSuggest(editor, serverValue, enteredBy, query, explicit) {
        const found = this.meanwhile(editor, serverValue, enteredBy);

        if (found !== null) {
            return {
                options: [
                    { value: 'keep', label: this.t('meanwhile_keep_mine'), detail: query.trim() === '' ? this.t('result_preview_none') : resultPreview(parseResultInput(query, this.parseOptions()), this.previewTexts()), className: 'sheet-option-action' },
                    { value: 'theirs', label: this.t('meanwhile_take_theirs'), detail: resultText(found.current, this.round()?.piecesCount ?? null, this.resultTexts()) || this.t('result_preview_none'), className: 'sheet-option-action' },
                ],
                hint: this.meanwhileText('result', found),
            };
        }

        if (explicit || query.trim() === '') {
            return {
                options: [
                    { value: 'kind:finished', label: this.t('result_kind_finished'), detail: this.t('result_kind_finished_hint') },
                    { value: 'kind:unfinished', label: this.t('result_kind_unfinished'), detail: this.t('result_kind_unfinished_hint') },
                    { value: 'kind:dns', label: this.t('result_kind_dns') },
                    { value: 'kind:none', label: this.t('result_kind_none') },
                ],
                hint: query.trim() === '' ? this.t('result_hint') : resultPreview(parseResultInput(query, this.parseOptions()), this.previewTexts()),
            };
        }

        return { options: [], hint: resultPreview(parseResultInput(query, this.parseOptions()), this.previewTexts()) };
    }

    /**
     * A result typed (or a kind picked): the action, `{error}` (the editor stays with the reason), `{theirs: true}`
     * (the organiser took the other value) or null (nothing to change).
     */
    resultCommit(editor, serverValue, enteredBy, input) {
        if (editor === null) {
            return { error: this.t('result_not_ready') };
        }

        const option = input.option?.value ?? null;
        let current = editor;

        if (option === 'theirs') {
            this.takeTheirs(editor);

            return { theirs: true };
        }

        const found = this.meanwhile(editor, serverValue, enteredBy);

        if (option === 'keep') {
            current = keepMineInEditor(this.pending(), editor, serverValue);
        } else if (found !== null) {
            return { error: `${this.meanwhileText('result', found)} ${this.t('meanwhile_choose')}` };
        }

        const parsed = parseResultInput(input.text, this.parseOptions());

        if (option === 'kind:dns') {
            return this.resultAction(current, { didNotStart: true });
        }

        if (option === 'kind:none') {
            return this.resultAction(current, null);
        }

        if (parsed.kind === 'error') {
            return { error: resultPreview(parsed, this.previewTexts()) };
        }

        const value = parsedValue(parsed);

        if (option === 'kind:finished' && resultKind(value) !== 'finished') {
            return { error: this.t('result_type_time') };
        }

        if (option === 'kind:unfinished' && resultKind(value) !== 'unfinished') {
            return { error: this.t('result_type_pieces') };
        }

        return this.resultAction(current, value);
    }

    resultAction(editor, value) {
        if (sameValue(value, editor.seen)) {
            return null;
        }

        return { action: officialEdit(this.roundId, editor.ref, 'result', editor.seen, value) };
    }

    /** Take theirs: the organiser's waiting value (and its problem) goes, the cell shows what was saved. */
    takeTheirs(editor) {
        const problemId = `result:${this.roundId}:${editor.ref}:${editor.field}`;

        if (this.context.queue.problem(problemId) !== null) {
            this.context.queue.dismiss(problemId);
        } else {
            this.pending().discard(editor.ref, editor.field);
        }
    }

    /** The table column's list: "Table 6 is Ben's" + Swap them, the conflict, or nothing. */
    tableSuggest(editor, entry, query) {
        const serverValue = entry?.serverTable ?? null;
        const found = this.meanwhile(editor, serverValue, null);

        if (found !== null) {
            return {
                options: [
                    { value: 'keep', label: this.t('meanwhile_keep_mine'), className: 'sheet-option-action' },
                    { value: 'theirs', label: this.t('meanwhile_take_theirs'), detail: found.current === null ? this.t('table_none') : String(found.current), className: 'sheet-option-action' },
                ],
                hint: this.meanwhileText('table_number', found),
            };
        }

        const parsed = parseTableNumber(query);

        if (parsed.kind === 'error') {
            return { options: [], hint: this.t('table_invalid') };
        }

        if (parsed.kind === 'number' && entry) {
            const holder = this.holderOf(entry.ref, parsed.value);

            if (holder !== null) {
                return {
                    options: [{ value: 'swap', label: this.t('table_swap'), detail: this.t('table_swap_detail', { name: holder.displayName, table: entry.table ?? this.t('table_none') }), className: 'sheet-option-action' }],
                    hint: this.t('table_taken', { table: parsed.value, name: holder.displayName }),
                };
            }
        }

        return { options: [], hint: '' };
    }

    /**
     * A table number typed: the action, `{swap: assignments}`, `{error}`, `{theirs: true}` or null.
     */
    tableCommit(editor, entry, input) {
        if (editor === null || !entry) {
            return { error: this.t('result_not_ready') };
        }

        const option = input.option?.value ?? null;
        let current = editor;

        if (option === 'theirs') {
            this.takeTheirs(editor);

            return { theirs: true };
        }

        const found = this.meanwhile(editor, entry.serverTable ?? null, null);

        if (option === 'keep') {
            current = keepMineInEditor(this.pending(), editor, entry.serverTable ?? null);
        } else if (found !== null) {
            return { error: `${this.meanwhileText('table_number', found)} ${this.t('meanwhile_choose')}` };
        }

        const parsed = parseTableNumber(input.text);

        if (parsed.kind === 'error') {
            return { error: this.t('table_invalid') };
        }

        const value = parsed.kind === 'number' ? parsed.value : null;
        const holder = this.holderOf(entry.ref, value);

        if (holder !== null) {
            if (option === 'swap') {
                return { swap: swapAssignments({ ref: entry.ref, table: current.seen ?? null }, value, holder), holder };
            }

            return { error: `${this.t('table_taken', { table: value, name: holder.displayName })} ${this.t('table_swap_choose')}` };
        }

        if (sameValue(value, current.seen)) {
            return null;
        }

        return { action: officialEdit(this.roundId, entry.ref, 'table_number', current.seen, value) };
    }

    /** "Swap them": one AssignTableNumbers write; its undo is the swap back as RecordRoundResults changes. */
    async swapTables(assignments, holder) {
        const answer = await this.context.queue.enqueueTables(this.roundId, assignments);

        if (answer.kind === 'ok') {
            const results = swapAsResults(this.roundId, assignments);
            this.context.undo.record({
                label: { key: 'results' },
                groups: [],
                inverse: [],
                errors: [],
                results,
                inverseResults: results.slice().reverse().map((change) => ({ ...change, from: change.to, to: change.from })),
            });
            this.context.announce(this.t('table_swapped', { name: holder.displayName, table: assignments[0].number }));

            return true;
        }

        const message = answer.problems?.[0]?.message ?? answer.message ?? this.t('table_swap_failed');
        this.context.announce(message);

        return false;
    }

    /** Qualified ticked/unticked on several rows: one undo step. */
    qualifiedAction(entries, value) {
        const changes = [];

        for (const entry of entries) {
            if (!entry || entry.ref === null) {
                continue;
            }

            const to = value ?? !entry.qualified;

            if (to !== entry.qualified) {
                changes.push({ roundId: this.roundId, ref: entry.ref, field: 'qualified', from: entry.qualified, to });
            }
        }

        return officialEdits(changes);
    }
}

/**
 * The results paste preview (both round views): lines with "replaces 1:24:00", names not found / not in the round /
 * unreadable listed, ambiguous names picked; Confirm = one RecordRoundResults step.
 */
export async function previewResults(view, plan, entries) {
    const t = (key, params) => view.texts.t(key, params);
    const tc = (key, count, params) => view.texts.tc(key, count, params);
    const byRef = new Map(entries.filter((entry) => entry.ref !== null).map((entry) => [entry.ref, entry]));
    const piecesCount = view.model.round(view.roundId)?.piecesCount ?? null;
    const describe = (result) => resultText(result, piecesCount, view.results.resultTexts()) || t('result_preview_none');

    if (plan.lines.length === 0) {
        view.context.announce(t('paste_nothing'));

        return;
    }

    const lines = plan.lines.map((line) => {
        const who = line.ref ? (byRef.get(line.ref)?.displayName ?? line.name) : line.name;
        const text = [who || t('paste_row', { number: line.index + 1 }), line.value].filter(Boolean).join(' ⇥ ');

        switch (line.status) {
            case 'change':
                return { id: line.id, text, status: 'change', note: line.from !== null && line.from !== undefined ? t('paste_replaces', { value: describe(line.from) }) : describe(line.to) };
            case 'same':
                return { id: line.id, text, status: 'same', note: t('paste_result_same') };
            case 'error':
                return { id: line.id, text, status: 'error', note: resultPreview(line.parsed, view.results.previewTexts()) };
            case 'ambiguous':
                return {
                    id: line.id,
                    text,
                    status: 'warning',
                    note: t('paste_result_ambiguous'),
                    choices: {
                        label: t('paste_which_entry'),
                        options: [...line.candidates.map((ref) => ({ value: ref, label: byRef.get(ref)?.displayName ?? ref })), { value: SKIP, label: t('paste_leave_out') }],
                        value: SKIP,
                    },
                };
            default:
                return { id: line.id, text, status: 'skip', note: t(`paste_result_${line.reason}`) };
        }
    });

    const counts = [];
    const add = (count, key, tone = null) => {
        if (count > 0) {
            counts.push({ text: tc(key, count), tone });
        }
    };
    add(plan.counts.changes, 'count_results');
    add(plan.counts.replaces, 'count_replaces', 'warning');
    add(plan.counts.skipped, 'count_skipped', 'danger');
    add(plan.counts.unreadable, 'count_unreadable', 'danger');
    add(plan.counts.ambiguous, 'count_to_choose', 'warning');

    const dialog = view.context.preview({
        title: t('paste_results_title'),
        intro: t('paste_results_intro'),
        counts,
        lines,
        confirmLabel: tc('paste_results_confirm', plan.counts.changes + plan.counts.ambiguous),
        returnFocus: () => view.grid?.focusActive({ scroll: false }),
    });
    const selection = await dialog.result;

    if (selection === null) {
        view.context.announce(t('paste_cancelled'));

        return;
    }

    const changes = chosenResultChanges(view.roundId, plan, entries, selection);

    if (changes.length === 0) {
        view.context.announce(t('paste_nothing'));

        return;
    }

    view.context.act(officialEdits(changes, { key: 'paste' }));
    view.context.announce(tc('pasted_results', changes.length));
}

/** A selector finding the same control again after a re-render. */
export function focusKey(element) {
    for (const attribute of ['data-filter', 'data-person', 'data-team', 'data-sort', 'data-results', 'data-team-size', 'data-tray-add', 'data-add', 'data-action']) {
        if (element.hasAttribute?.(attribute)) {
            const value = element.getAttribute(attribute);

            return value === '' ? `[${attribute}]` : `[${attribute}="${CSS.escape(value)}"]`;
        }
    }

    return null;
}
