/**
 * The People tab on a desktop (D1, O4, O5; docs/features/competitions-management/participants-spreadsheet.md §4 (C), §6,
 * §7 and "Client architecture (as built)") - one row per person:
 *
 * - a selection column (Shift+click ranges, the header box = every shown row) and the **bulk bar** for the selected
 *   people: in / out of a solo round, "Make a pair/team" in a pair/team round (a preview with the server's dry run when it
 *   moves anybody or the size is off), Mark paid / Check in on a managed event (a confirmation saying how many e-mails go
 *   out, then one request after the other with progress), Remove from event, Restore - each one undo step;
 * - **filters** with counts (All, Not in any round, In no solo round, In 2+ solo rounds, Joined by themselves, the
 *   registration filters of a managed event, Duplicate names, Removed), a **round select** ("In Group A" / "Not in
 *   Group A") and a **search** (name, external id, MSP name, #code - folded); a filter never hides a row that has the
 *   focus or is open in the person editor until the filter changes; `?filter=<key>` in the page URL opens the tab with
 *   that filter on (the waitlist note links `?tab=people&filter=waitlist`);
 * - **sortable headers** (name, country, external ID, registered, joined - a click toggles A→Z / Z→A, `aria-sort`,
 *   remembered per event in this browser); while the organiser works in the grid the rows keep their places (an edited
 *   name never jumps away) - only new rows are put where the sort says;
 * - the **Columns menu** (D10, remembered per event in this browser): name, country, MSP profile, rounds shown by
 *   default; external id, source, joined (MSP), the registration columns of a managed event, the private note hidden;
 * - name, country, MSP profile (O9: "Linked to a MySpeedPuzzling profile" when the viewer may not see it), a checkbox
 *   per solo round, a read-only pair/team label per pair/team round (Enter or a double click opens that round's tab at the
 *   person), external id, note (`field: note`, private to the organisers), the registration (Enter/Space opens the
 *   actions its state allows - registration_actions.js), registered, paid, checked in, the row actions (Edit, Remove /
 *   Restore);
 * - markers in text: "Joined by themselves", "On the waitlist", same names, "No round yet", removed rows struck through;
 * - the new-person row at the bottom (type a name, Enter, the next name) and **adding people by paste** (people_paste.js:
 *   a block pasted onto the new row - or reaching past the end of the list - is read as `name ⇥ country ⇥ external id`
 *   per line, previewed - names that look like a mistake unticked, "and put them into ▾ a solo round" - and added with
 *   one Confirm); pasting over names (renames) is always previewed;
 * - counters above the grid for a managed event ("Spots taken 180 / 200 · Waitlist 12") and the first-in-line hint.
 *
 * Every edit is an action of sheet_changes.js handed to `context.act()`; registration changes go only through the
 * registration endpoint. update(delta) re-renders only the rows the delta names (plus the rows whose same-name or
 * waitlist marker moved because of them) - never the whole grid on an edit. A refusal or a "nothing happened" is shown,
 * not only read out (`context.notify`, through notify()).
 *
 * Texts: `this.t()` = the core texts (C's keys), `this.say()` / `this.sayCount()` = the People texts
 * (_texts_people.html.twig).
 */

import { escapeHtml } from '../sheet_grid.js';
import {
    addPerson,
    cleanFieldValue,
    combine,
    isEmpty,
    linkProfile,
    newTeamRow,
    refusalDetails,
    removePeople,
    restorePeople,
    setField,
    setFields,
    setInRound,
} from '../sheet_changes.js';
import { readBoolean, trimCell } from '../tsv.js';
import { foldSearchText } from '../../search_fold.js';
import { nameKey, parsePlace } from '../sheet_model.js';
import {
    HINT_CLOSE,
    INTO_ALREADY,
    INTO_AMBIGUOUS,
    INTO_PUT,
    LINE_DUPLICATE,
    LINE_EXISTING,
    LINE_HEADER,
    LINE_INVALID,
    LINE_NEW,
    LINE_REMOVED,
    lineOfError,
    namePasteAction,
    placementOf,
    planNamePaste,
} from '../people_paste.js';
import {
    allowedActions,
    bulkRegistrationPlan,
    firstInLine,
    matchesRegistrationFilter,
    paidBefore,
    performRegistrationAction,
    registrationCounts,
    registrationStatus,
    runRegistrationBulk,
    waitlistPositions,
} from '../registration_actions.js';

export const NEW_ROW = '__new';
// A paste or fill touching more rows than this is previewed first (§6)
export const PREVIEW_ABOVE_ROWS = 10;
// Removing more than this share of the active people (and at least LARGE_REMOVAL_MIN) asks for the typed number (§6)
export const LARGE_REMOVAL_SHARE = 0.25;
export const LARGE_REMOVAL_MIN = 10;
// The result count of a search is read out once the typing pauses, not after every letter
export const SEARCH_ANNOUNCE_MS = 700;
const UNLINK = '__unlink';
const OPEN = '__open';
const NO_COUNTRY = '__none';
const SELECT = 'select';
const ACTIONS = 'actions';
const NAME_WIDTH = 240;
const SELECT_WIDTH = 44;

/**
 * The People filters in the order the toolbar shows them; `managed` ones only for a managed event, `inPerson` ones not
 * for an online one; `soloRounds` = how many solo rounds the event needs for it to mean something (`mixed`: and a
 * round of another kind - else "In no solo round" is "Not in any round").
 */
export const FILTERS = [
    { key: 'all' },
    { key: 'no_round' },
    { key: 'no_solo', soloRounds: 1, mixed: true },
    { key: 'multi_solo', soloRounds: 2 },
    { key: 'joined' },
    { key: 'waitlist', managed: true },
    { key: 'not_paid', managed: true },
    { key: 'paid', managed: true },
    { key: 'checked_in', managed: true, inPerson: true },
    { key: 'not_checked_in', managed: true, inPerson: true },
    { key: 'duplicates' },
    { key: 'removed' },
];

// Optional filters that would show nobody stay out of the way (All and the active one are always there)
export const HIDDEN_WHEN_EMPTY = ['joined', 'multi_solo', 'duplicates', 'removed', 'checked_in'];

/**
 * The columns of the Columns menu (D10): `rounds` = every round column; `name` is always shown. Registration columns
 * only for a managed event (check-in only in person).
 */
export const COLUMN_OPTIONS = [
    { key: 'name', fixed: true },
    { key: 'country', on: true },
    { key: 'player', on: true },
    { key: 'rounds', on: true },
    { key: 'externalId', on: false },
    { key: 'source', on: false },
    { key: 'joined', on: false },
    { key: 'registration', on: true, managed: true },
    { key: 'registered', on: false, managed: true },
    { key: 'paid', on: false, managed: true },
    { key: 'checkedIn', on: false, managed: true, inPerson: true },
    { key: 'note', on: false },
];

/** The columns a header click sorts by (BR4). */
export const SORTABLE = ['name', 'country', 'externalId', 'registered', 'joined'];

/** The filters and columns an event offers (`rounds` = the event's rounds, for the solo-round filters). */
export function offeredFor(list, competition, rounds = []) {
    const solo = rounds.filter((round) => round.category === 'solo').length;
    const other = rounds.length - solo;

    return list.filter((item) => (!item.managed || competition?.registrationManaged === true)
        && (!item.inPerson || competition?.isOnline !== true)
        && (!item.soloRounds || (solo >= item.soloRounds && (!item.mixed || other > 0))));
}

const searchTexts = new WeakMap();

/** What the search box looks through, folded once per person record: name, external id, MSP name, #code. */
export function personSearchText(person) {
    let text = searchTexts.get(person);

    if (text === undefined) {
        const player = person.player?.visible === true ? person.player : null;
        text = foldSearchText([person.name, person.externalId ?? '', player?.name ?? '', player?.code ? `#${player.code}` : ''].join(' \u0000 '));
        searchTexts.set(person, text);
    }

    return text;
}

/** The search: every word typed must be found ("kim ex", "#abc12", an external id). */
export function matchesSearch(person, query) {
    const folded = foldSearchText(String(query ?? '')).trim();

    if (folded === '') {
        return true;
    }

    const text = personSearchText(person);

    return folded.split(/\s+/).every((word) => text.includes(word));
}

/** How many solo rounds the person is in. */
export function soloRoundCount(model, personId) {
    let count = 0;

    for (const roundId of model.placesOf(personId).keys()) {
        if (model.round(roundId)?.category === 'solo') {
            count++;
        }
    }

    return count;
}

/**
 * A person under a filter (contract §5 stream E). `duplicateKeys` = the name keys shared by 2+ active people.
 */
export function matchesFilter(model, person, filter, duplicateKeys = null) {
    const removed = (person.removedAt ?? null) !== null;

    if (filter === 'removed') {
        return removed;
    }

    if (removed) {
        return false;
    }

    switch (filter) {
        case 'no_round':
            return model.placesOf(person.id).size === 0;
        case 'no_solo':
            return soloRoundCount(model, person.id) === 0;
        case 'multi_solo':
            return soloRoundCount(model, person.id) >= 2;
        case 'joined':
            return person.source === 'self_joined';
        case 'duplicates':
            return (duplicateKeys ?? new Set(model.duplicateNames().keys())).has(nameKey(person.name));
        case 'waitlist':
        case 'not_paid':
        case 'paid':
        case 'checked_in':
        case 'not_checked_in':
            return matchesRegistrationFilter(person, filter);
        default:
            return true;
    }
}

/** `in:<roundId>` / `out:<roundId>` (the round select) → {roundId, inRound}, else null (any round). */
export function parseRoundFilter(value) {
    const match = /^(in|out):(.+)$/.exec(String(value ?? ''));

    return match ? { roundId: match[2], inRound: match[1] === 'in' } : null;
}

/** A person under the round select: in the round (any place - solo, a pair/team, without one yet) or not in it. */
export function matchesRoundFilter(model, person, roundFilter) {
    const parsed = parseRoundFilter(roundFilter);

    if (parsed === null || model.round(parsed.roundId) === null) {
        return true;
    }

    return (model.placeValue(person.id, parsed.roundId) !== 'out') === parsed.inRound;
}

/** Counts per filter key (search and the round select applied). */
export function filterCounts(model, filters, query = '', roundFilter = '') {
    const duplicateKeys = new Set(model.duplicateNames().keys());
    const people = model.people({ includeRemoved: true }).filter((person) => matchesSearch(person, query) && matchesRoundFilter(model, person, roundFilter));
    const counts = {};

    for (const { key } of filters) {
        counts[key] = people.filter((person) => matchesFilter(model, person, key, duplicateKeys)).length;
    }

    return counts;
}

/** The ids a filter + search + round select shows, in the people order (state order, people added on the page at the end). */
export function visiblePeople(model, filter, query = '', held = null, roundFilter = '') {
    const duplicateKeys = new Set(model.duplicateNames().keys());

    return model.people({ includeRemoved: true })
        .filter((person) => (held?.has(person.id) ?? false)
            || (matchesFilter(model, person, filter, duplicateKeys) && matchesSearch(person, query) && matchesRoundFilter(model, person, roundFilter)))
        .map((person) => person.id);
}

/**
 * The people in a column's order (BR4): name, country (its name in the page's language), external ID (numbers as
 * numbers), registered (when they registered), joined (when they joined by themselves). Empty values go last both
 * ways; equal ones keep the list's order.
 *
 * @param {object[]} people
 * @param {{key: string, dir: 'asc'|'desc'}|null} sort
 * @param {{countries?: Object<string, string>, locale?: string}} [options]
 * @returns {object[]}
 */
export function sortPeople(people, sort, { countries = {}, locale = undefined } = {}) {
    if (!sort || !SORTABLE.includes(sort.key)) {
        return people.slice();
    }

    let collator;

    try {
        collator = new Intl.Collator(locale || undefined, { sensitivity: 'base', numeric: true });
    } catch (e) {
        collator = new Intl.Collator(undefined, { sensitivity: 'base', numeric: true });
    }

    const valueOf = {
        name: (person) => person.name ?? '',
        country: (person) => (person.country ? String(countries[person.country] ?? person.country) : null),
        externalId: (person) => person.externalId ?? null,
        registered: (person) => timeOf(person.registration?.registeredAt),
        joined: (person) => (person.source === 'self_joined' ? timeOf(person.connectedAt) : null),
    }[sort.key];
    const direction = sort.dir === 'desc' ? -1 : 1;
    const rows = people.map((person, index) => ({ person, index, value: valueOf(person) }));

    rows.sort((a, b) => {
        const emptyA = a.value === null || a.value === '';
        const emptyB = b.value === null || b.value === '';

        if (emptyA || emptyB) {
            return emptyA === emptyB ? a.index - b.index : (emptyA ? 1 : -1);
        }

        const order = typeof a.value === 'number' ? a.value - b.value : collator.compare(a.value, b.value);

        return order !== 0 ? order * direction : a.index - b.index;
    });

    return rows.map((row) => row.person);
}

function timeOf(value) {
    const time = value ? Date.parse(value) : Number.NaN;

    return Number.isNaN(time) ? null : time;
}

/**
 * The rows while the organiser works in the grid: the ones shown already keep their order (an edited name or a live
 * change never moves the row they are on), rows new to the list go where `sorted` puts them (after the row they follow
 * there).
 *
 * @param {string[]} sorted the rows in their sorted order
 * @param {string[]} previous the rows shown now
 */
export function keepOrder(sorted, previous) {
    const wanted = new Set(sorted);
    const result = previous.filter((id) => wanted.has(id));

    if (result.length === sorted.length) {
        return result;
    }

    const placed = new Set(result);

    sorted.forEach((id, index) => {
        if (placed.has(id)) {
            return;
        }

        let at = 0;

        for (let before = index - 1; before >= 0; before--) {
            const position = result.indexOf(sorted[before]);

            if (position !== -1) {
                at = position + 1;
                break;
            }
        }

        result.splice(at, 0, id);
        placed.add(id);
    });

    return result;
}

/** Stored per event, in this browser only (localStorage may be missing or throw - the defaults then). */
export function readColumnPrefs(storage, competitionId) {
    try {
        const raw = storage?.getItem(`participants-sheet:people-columns:${competitionId}`);
        const value = raw ? JSON.parse(raw) : null;

        return value && typeof value === 'object' ? value : {};
    } catch (e) {
        return {};
    }
}

export function writeColumnPrefs(storage, competitionId, prefs) {
    try {
        storage?.setItem(`participants-sheet:people-columns:${competitionId}`, JSON.stringify(prefs));
    } catch (e) {
        // Private mode, quota, blocked storage - the choice lasts for this page view
    }
}

/** The People sort, per event in this browser ({key, dir} or null). */
export function readSortPref(storage, competitionId) {
    try {
        const raw = storage?.getItem(`participants-sheet:people-sort:${competitionId}`);
        const value = raw ? JSON.parse(raw) : null;

        return value && SORTABLE.includes(value.key) && (value.dir === 'asc' || value.dir === 'desc') ? { key: value.key, dir: value.dir } : null;
    } catch (e) {
        return null;
    }
}

export function writeSortPref(storage, competitionId, sort) {
    try {
        if (sort === null) {
            storage?.removeItem(`participants-sheet:people-sort:${competitionId}`);
        } else {
            storage?.setItem(`participants-sheet:people-sort:${competitionId}`, JSON.stringify(sort));
        }
    } catch (e) {
        // The sort lasts for this page view
    }
}

export function browserStorage() {
    try {
        return typeof window !== 'undefined' ? window.localStorage : null;
    } catch (e) {
        return null;
    }
}

/**
 * `?filter=<key>` of the page URL (a link to the People tab with a filter on - e.g. the waitlist note's
 * `?tab=people&filter=waitlist`): read once and taken out of the URL, so the next visit of the tab starts from All.
 * A FILTERS key, or `in:<roundId>` / `out:<roundId>` for the round select; null without one.
 */
export function takeUrlFilter() {
    try {
        const url = new URL(window.location.href);
        const value = url.searchParams.get('filter');

        if (value === null) {
            return null;
        }

        url.searchParams.delete('filter');
        window.history.replaceState(window.history.state, '', url.toString());

        return value;
    } catch (e) {
        return null;
    }
}

/**
 * A refusal or a "nothing happened" shown to the organiser, not only read out: `context.notify` (the page's toast -
 * it reads the text out too) when the page has one, else the live region. `anchor` = the cell ({row, col}) or the
 * element it is about.
 */
export function notify(context, text, { kind = 'error', anchor = null } = {}) {
    if (!text) {
        return;
    }

    if (typeof context.notify === 'function') {
        context.notify(text, anchor ? { kind, anchor } : { kind });
    } else {
        context.announce(text);
    }
}

export default function createPeopleView(context) {
    return new PeopleView(context);
}

export class PeopleView {
    constructor(context) {
        this.context = context;
        this.model = context.model;
        this.texts = context.texts.core;
        this.people = context.texts.people;
        this.grid = null;
        this.roundSignatures = new Map();
        this.searchController = null;
        this.filter = 'all';
        this.query = '';
        this.roundFilter = '';
        this.selected = new Set();
        this.held = new Set();
        this.panelPersonId = null;
        this.menu = null;
        this.cleanups = [];
        this.chromeFrame = null;
        this.announceTimer = null;
        this.columnPrefs = readColumnPrefs(browserStorage(), this.competition.id ?? '');
        this.sort = readSortPref(browserStorage(), this.competition.id ?? '');
        this.duplicateIds = new Set();
        this.positions = new Map();
        this.competitionKey = this.competitionSignature();
    }

    /** The event as the model has it now (a fetched state may switch the managed registration, the capacity...). */
    get competition() {
        return this.model.competition ?? {};
    }

    competitionSignature() {
        const competition = this.competition;

        return `${competition.registrationManaged === true}|${competition.isOnline === true}|${competition.capacity ?? ''}`;
    }

    /** Shown or refused - see notify(). */
    notify(text, options) {
        notify(this.context, text, options);
    }

    t(key, params) {
        return this.texts.t(key, params);
    }

    /** A People text (_texts_people.html.twig). */
    say(key, params) {
        return this.people.t(key, params);
    }

    sayCount(key, count, params) {
        return this.people.tc(key, count, params);
    }

    get managed() {
        return this.competition.registrationManaged === true;
    }

    // ---------------------------------------------------------------- the view interface

    render() {
        const fromUrl = takeUrlFilter();

        if (fromUrl !== null && parseRoundFilter(fromUrl) !== null) {
            this.roundFilter = this.model.round(parseRoundFilter(fromUrl).roundId) !== null ? fromUrl : '';
        } else if (fromUrl !== null) {
            this.filter = this.offeredFilters().some((item) => item.key === fromUrl) ? fromUrl : 'all';
        }

        this.context.root.classList.add('sheet-view', 'sheet-view-people');
        this.host = document.createElement('div');
        this.host.className = 'sheet-people';
        this.host.innerHTML = this.chromeHtml();
        this.gridRoot = document.createElement('div');
        this.gridRoot.className = 'sheet-grid-host';
        this.host.append(this.gridRoot);
        this.context.root.replaceChildren(this.host);

        this.toolbar = this.host.querySelector('[data-people-toolbar]');
        this.searchInput = this.host.querySelector('[data-people-search]');
        this.filtersElement = this.host.querySelector('[data-people-filters]');
        this.roundSelect = this.host.querySelector('[data-people-round]');
        this.registrationElement = this.host.querySelector('[data-people-registration]');
        this.bulkElement = this.host.querySelector('[data-people-bulk]');
        this.columnsMenu = this.host.querySelector('[data-people-columns]');

        this.wireChrome();
        this.buildGrid();
        this.renderChrome();

        // A fetched state can change the event itself (managed registration, online, the capacity) and nothing else
        if (typeof this.context.queue?.subscribe === 'function') {
            this.cleanups.push(this.context.queue.subscribe((event) => {
                if (event?.type === 'state') {
                    this.checkCompetition();
                }
            }));
        }
    }

    /**
     * The event changed (registration management switched, online, the capacity): its columns, filters and counters
     * follow. Returns true when the grid was built again (nothing more to update).
     */
    checkCompetition() {
        const key = this.competitionSignature();

        if (key === this.competitionKey || !this.host) {
            return false;
        }

        const [managedBefore, onlineBefore] = this.competitionKey.split('|');
        this.competitionKey = key;
        const [managed, online] = key.split('|');
        this.columnsMenu.innerHTML = this.columnsMenuHtml();
        this.registrationElement.hidden = !this.managed;

        if (!this.managed) {
            this.registrationElement.innerHTML = '';
            this.registrationElement.dataset.html = '';
        }

        if (!this.offeredFilters().some((item) => item.key === this.filter)) {
            this.filter = 'all';
            this.held = new Set(this.panelPersonId ? [this.panelPersonId] : []);
        }

        const rebuild = managed !== managedBefore || online !== onlineBefore;

        if (rebuild) {
            // Registration columns came or went
            this.rebuildGrid();
        }

        this.renderChrome();
        this.grid?.fitHeight();

        return rebuild;
    }

    offeredFilters() {
        return offeredFor(FILTERS, this.competition, this.model.rounds());
    }

    /** `previousRows` = the rows of the grid this one replaces while the organiser works in it (they keep their places). */
    buildGrid(previousRows = null) {
        this.columns = this.buildColumns();
        this.rememberRounds();
        this.refreshMarkerSets();
        this.grid = this.context.createGrid({
            container: this.gridRoot,
            label: this.t('people_grid_label'),
            columns: this.columns,
            rows: previousRows !== null ? this.rowKeys({ previous: previousRows }) : this.rowKeys({ fresh: true }),
            cell: (row, col) => this.cell(row, col),
            rowLabel: (row) => (row === NEW_ROW ? this.t('people_new_row') : this.model.person(row)?.name ?? ''),
            rowClass: (row) => this.rowClass(row),
            editValue: (row, col) => this.editValue(row, col),
            seenValue: (row, col) => this.seenValue(row, col),
            suggest: (row, col, query) => this.suggest(row, col, query),
            commit: (row, col, input, info) => this.commit(row, col, input, info),
            toggle: (cells, value) => this.toggle(cells, value),
            clear: (cells) => this.clear(cells),
            paste: (anchor, rows, selected) => this.paste(anchor, rows, selected),
            fill: (kind, range, active) => this.fill(kind, range, active),
            activate: (row, col) => this.activate(row, col),
            openPanel: (row) => this.openEditor(row),
        });
        this.syncSelectAll();
        this.syncSortHeaders();
    }

    /**
     * New columns (rounds came or went, the Columns menu): the grid is built again, the focused cell focused again - an
     * open edit goes on with what was typed and what the cell showed when it opened (never lost, never sent by this).
     */
    rebuildGrid() {
        const active = this.grid ? { ...this.grid.active } : null;
        const focused = this.gridRoot.contains(document.activeElement);
        const edit = this.grid?.editState?.() ?? null;
        const previousRows = focused || edit !== null ? (this.grid?.rows ?? null) : null;
        this.grid?.destroy({ keepEdit: true });
        this.buildGrid(previousRows);

        if (edit !== null && this.grid.resumeEdit(edit)) {
            this.noticeChangedMeanwhile();

            return;
        }

        if (edit !== null) {
            // Its column went (the Columns menu): saved like a blur
            this.context.announce(this.t('editor_column_gone'));
        }

        if (focused && active !== null && !this.grid.focusCell(active.row, active.col)) {
            this.grid.focusCell(active.row, 'name');
        }
    }

    /**
     * The model changed (or a cell marker): only the rows it names are re-rendered (and rows whose same-name or
     * waitlist marker moved with them); the row list when the filter's answer changed; every row of a round only when
     * its expected size changed.
     */
    update(delta) {
        if (this.grid === null || this.grid.destroying) {
            return;
        }

        if (this.checkCompetition()) {
            return;
        }

        if (delta.all || this.roundsChanged()) {
            if (this.roundFilter !== '' && this.model.round(parseRoundFilter(this.roundFilter)?.roundId) === null) {
                // Its round is gone
                this.roundFilter = '';
            }

            this.rebuildGrid();
            this.scheduleChrome();

            return;
        }

        this.holdFocusedRow();
        this.noticeChangedMeanwhile();
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

        if (delta.people.size > 0) {
            this.refreshMarkerSets().forEach((id) => rows.add(id));
        }

        this.grid.updateRows([...rows]);
        this.scheduleChrome();
    }

    focus(target = null) {
        if (this.grid === null) {
            return;
        }

        if (target?.personId && this.model.person(target.personId) !== null) {
            // A jump from another tab: the filter and search let go of the person when they hide them
            if (!this.grid.rows.includes(target.personId)) {
                this.setFilter(this.model.isRemoved(target.personId) ? 'removed' : 'all', { query: '', roundFilter: '' });
            }

            const col = target.col && this.grid.colIndex(target.col) !== -1 ? target.col : 'name';
            this.grid.focusCell(target.personId, col);

            return;
        }

        this.grid.focusActive();
    }

    /** Jump to the cell of a problem (the conflicts panel) - the filter and search let go of it when they hide it. */
    reveal(problem) {
        const key = problem?.target?.key ?? '';
        const [kind, id, rest] = key.split(':');
        let col = 'name';

        if (kind === 'person') {
            col = { country: 'country', player: 'player', externalId: 'externalId', note: 'note', registration: 'registration' }[rest] ?? 'name';
        } else if (kind === 'place') {
            col = `round:${key.slice(`place:${id}:`.length)}`;
        } else {
            return false;
        }

        if (this.model.person(id) === null || this.grid === null) {
            return false;
        }

        if (!this.grid.rows.includes(id)) {
            this.setFilter(this.model.isRemoved(id) ? 'removed' : 'all', { query: '', roundFilter: '' });
        }

        if (this.grid.colIndex(col) === -1) {
            col = 'name';
        }

        return this.grid.focusCell(id, col);
    }

    onOutcome() {
        // Refusals and conflicts show on their cells through the markers; nothing more here
    }

    destroy() {
        this.searchController?.abort();
        this.closeMenu(false);
        cancelAnimationFrame(this.chromeFrame);
        clearTimeout(this.announceTimer);
        this.cleanups.forEach((cleanup) => cleanup());
        this.cleanups = [];
        this.grid?.destroy();
        this.grid = null;
    }

    listen(target, type, handler, options) {
        target.addEventListener(type, handler, options);
        this.cleanups.push(() => target.removeEventListener(type, handler, options));
    }

    // ---------------------------------------------------------------- chrome: toolbar, counters, bulk bar

    columnsMenuHtml() {
        return offeredFor(COLUMN_OPTIONS, this.competition).map((option) => {
            const checked = option.fixed || this.columnOn(option.key);

            return `<li role="none"><button type="button" class="dropdown-item sheet-columns-item" role="menuitemcheckbox" aria-checked="${checked ? 'true' : 'false'}" data-column="${escapeHtml(option.key)}"${option.fixed ? ' disabled' : ''}><i class="bi bi-check-lg" aria-hidden="true"></i> ${escapeHtml(this.say(`column_${option.key}`))}</button></li>`;
        }).join('');
    }

    chromeHtml() {
        return `<div class="sheet-people-toolbar" data-people-toolbar>
                <div class="sheet-people-search">
                    <i class="bi bi-search" aria-hidden="true"></i>
                    <input type="search" class="form-control form-control-sm" data-people-search autocomplete="off" spellcheck="false"
                           placeholder="${escapeHtml(this.say('search_placeholder'))}" aria-label="${escapeHtml(this.say('search_label'))}">
                </div>
                <select class="form-select form-select-sm sheet-people-round" data-people-round aria-label="${escapeHtml(this.say('round_filter_label'))}" hidden></select>
                <div class="sheet-people-filters" role="group" aria-label="${escapeHtml(this.say('filters_label'))}" data-people-filters></div>
                <div class="dropdown sheet-people-columns">
                    <button type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" aria-haspopup="true">
                        <i class="bi bi-layout-three-columns" aria-hidden="true"></i> ${escapeHtml(this.say('columns_button'))}
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end" role="menu" aria-label="${escapeHtml(this.say('columns_label'))}" data-people-columns>${this.columnsMenuHtml()}</ul>
                </div>
            </div>
            <div class="sheet-people-registration" data-people-registration${this.managed ? '' : ' hidden'}></div>
            <div class="sheet-bulk-bar" role="region" aria-label="${escapeHtml(this.say('bulk_label'))}" data-people-bulk hidden></div>`;
    }

    wireChrome() {
        this.listen(this.searchInput, 'input', () => {
            this.setFilter(this.filter, { query: this.searchInput.value, announce: 'later' });
        });
        this.listen(this.roundSelect, 'change', () => {
            this.setFilter(this.filter, { roundFilter: this.roundSelect.value });
        });
        this.listen(this.searchInput, 'keydown', (event) => {
            // Down from the search goes to the first row shown
            if (event.key === 'ArrowDown' && this.grid?.rows.length) {
                event.preventDefault();
                this.grid.focusCell(this.grid.rows[0], 'name');
            }
        });
        this.listen(this.filtersElement, 'click', (event) => {
            const button = event.target.closest('[data-filter]');

            if (button) {
                this.setFilter(button.dataset.filter);
            }
        });
        this.listen(this.columnsMenu, 'click', (event) => {
            const item = event.target.closest('[data-column]');

            if (item && !item.disabled) {
                this.toggleColumn(item.dataset.column);
                item.setAttribute('aria-checked', this.columnOn(item.dataset.column) ? 'true' : 'false');
            }
        });
        this.listen(this.registrationElement, 'click', (event) => {
            const button = event.target.closest('[data-promote]');

            if (button) {
                this.registrationAction(button.dataset.promote, 'promote');
            }
        });
        this.listen(this.bulkElement, 'click', (event) => this.onBulkClick(event));
        this.listen(this.gridRoot, 'click', (event) => {
            const sort = event.target.closest?.('[data-sort]');

            if (sort) {
                event.preventDefault();
                this.toggleSort(sort.dataset.sort);

                return;
            }

            const action = event.target.closest('[data-row-action]');

            if (action) {
                const row = action.closest('tr')?.dataset.row;

                if (row && row !== NEW_ROW) {
                    event.preventDefault();
                    this.rowAction(row, action.dataset.rowAction, action.closest('td, th'));
                }
            }
        });
        // The header's "select every shown row" box and the sort buttons: the grid never treats their click as a column
        // selection, nor their keys as the grid's (Enter / Space press the button, Tab moves on)
        this.listen(this.gridRoot, 'pointerdown', (event) => {
            if (event.target.closest?.('.sheet-select-all, .sheet-sort')) {
                event.stopPropagation();
            }
        }, true);
        this.listen(this.gridRoot, 'keydown', (event) => {
            if (event.target.closest?.('.sheet-sort')) {
                event.stopPropagation();
            }
        }, true);
        this.listen(this.gridRoot, 'change', (event) => {
            if (event.target.matches?.('.sheet-select-all')) {
                this.selectShown(event.target.checked);
            }
        });
    }

    scheduleChrome() {
        cancelAnimationFrame(this.chromeFrame);
        this.chromeFrame = requestAnimationFrame(() => this.renderChrome());
    }

    renderChrome() {
        if (!this.host) {
            return;
        }

        this.renderFilters();
        this.renderRoundSelect();
        this.renderRegistration();
        this.renderBulkBar();
        this.syncSelectAll();
    }

    renderFilters() {
        const filters = this.offeredFilters();
        const counts = filterCounts(this.model, filters, this.query, this.roundFilter);
        const html = filters.map(({ key }) => {
            const pressed = key === this.filter;
            // Optional filters that would show nobody stay out of the way (All and the active one are always there)
            const hidden = !pressed && key !== 'all' && counts[key] === 0 && HIDDEN_WHEN_EMPTY.includes(key);

            return `<button type="button" class="btn btn-sm sheet-filter${pressed ? ' active' : ''}" data-filter="${escapeHtml(key)}" aria-pressed="${pressed ? 'true' : 'false'}"${hidden ? ' hidden' : ''}>${escapeHtml(this.say(`filter_${key}`))} <span class="sheet-filter-count">${counts[key]}</span><span class="visually-hidden"> (${escapeHtml(this.sayCount('people_count', counts[key]))})</span></button>`;
        }).join('');

        if (this.filtersElement.dataset.html !== html) {
            const focused = this.filtersElement.contains(document.activeElement) ? document.activeElement.dataset.filter : null;
            this.filtersElement.innerHTML = html;
            this.filtersElement.dataset.html = html;

            if (focused) {
                this.filtersElement.querySelector(`[data-filter="${CSS.escape(focused)}"]`)?.focus();
            }
        }
    }

    /** "Round: any / In Group A / Not in Group A …" - only with rounds. */
    renderRoundSelect() {
        const rounds = this.model.rounds();
        const html = rounds.length === 0 ? '' : [
            `<option value="">${escapeHtml(this.say('round_filter_any'))}</option>`,
            ...rounds.map((round) => `<option value="in:${escapeHtml(round.id)}">${escapeHtml(this.say('round_filter_in', { round: round.name }))}</option><option value="out:${escapeHtml(round.id)}">${escapeHtml(this.say('round_filter_out', { round: round.name }))}</option>`),
        ].join('');

        if (this.roundSelect.dataset.html !== html && document.activeElement !== this.roundSelect) {
            this.roundSelect.innerHTML = html;
            this.roundSelect.dataset.html = html;
        }

        this.roundSelect.hidden = rounds.length === 0;

        if (this.roundSelect.value !== this.roundFilter && document.activeElement !== this.roundSelect) {
            this.roundSelect.value = this.roundFilter;
        }
    }

    renderRegistration() {
        if (!this.managed) {
            return;
        }

        const html = registrationSummaryHtml(this.model, this.competition, (key, params) => this.say(key, params), (key, count, params) => this.sayCount(key, count, params));
        replaceKeepingFocus(this.registrationElement, html, () => this.grid?.fitHeight());
    }

    // ---------------------------------------------------------------- filters and search

    /**
     * A filter, the search or the round select changed: the rows again, in the sort's order. `announce: 'later'` (typing
     * in the search) reads the count out once the typing pauses.
     */
    setFilter(filter, { query = this.query, roundFilter = this.roundFilter, announce = 'now' } = {}) {
        const offered = this.offeredFilters().some((item) => item.key === filter);
        this.filter = offered ? filter : 'all';
        this.query = String(query ?? '');
        this.roundFilter = parseRoundFilter(roundFilter) !== null && this.model.round(parseRoundFilter(roundFilter).roundId) !== null ? roundFilter : '';

        if (this.searchInput && this.searchInput.value !== this.query) {
            this.searchInput.value = this.query;
        }

        // A new filter starts over: only the person open in the editor stays shown whatever the filter says
        this.held = new Set(this.panelPersonId ? [this.panelPersonId] : []);

        this.grid?.setRows(this.rowKeys({ fresh: true }));

        this.renderChrome();
        clearTimeout(this.announceTimer);
        const say = () => this.context.announce(this.sayCount('shown_count', this.visibleIds().length));

        if (announce === 'later') {
            this.announceTimer = setTimeout(say, SEARCH_ANNOUNCE_MS);
        } else {
            say();
        }
    }

    /** A sortable header clicked: by that column A→Z, again Z→A (remembered for the event in this browser). */
    toggleSort(key) {
        if (!SORTABLE.includes(key)) {
            return;
        }

        this.sort = this.sort?.key === key ? { key, dir: this.sort.dir === 'asc' ? 'desc' : 'asc' } : { key, dir: 'asc' };
        writeSortPref(browserStorage(), this.competition.id ?? '', this.sort);
        this.grid?.setRows(this.rowKeys({ fresh: true }));
        this.syncSortHeaders();
        const column = this.columns.find((candidate) => candidate.key === key)?.label ?? key;
        this.context.announce(this.say(`sort_done_${this.sort.dir}`, { column }));
    }

    /** `aria-sort` on the sorted column's header and the buttons' icons (no rebuild of the grid). */
    syncSortHeaders() {
        const head = this.gridRoot?.querySelector('thead tr');

        if (!head || !this.columns) {
            return;
        }

        this.columns.forEach((column, index) => {
            const th = head.cells[index];

            if (!th || !SORTABLE.includes(column.key)) {
                return;
            }

            const dir = this.sort?.key === column.key ? this.sort.dir : null;

            if (dir === null) {
                th.removeAttribute('aria-sort');
            } else {
                th.setAttribute('aria-sort', dir === 'asc' ? 'ascending' : 'descending');
            }

            const icon = th.querySelector('.sheet-sort .bi');

            if (icon) {
                icon.className = `bi ${sortIcon(dir)}`;
            }
        });
    }

    /** The row with the focus (or an edit) stays until the filter changes (§ "never hides a row with focus"). */
    holdFocusedRow() {
        if (this.grid === null) {
            return;
        }

        const row = this.grid.active?.row;

        if (row && row !== NEW_ROW && (this.grid.isEditing() || this.gridRoot.contains(document.activeElement))) {
            this.held.add(row);
        }
    }

    /** Who the filter, the search and the round select show, in the sort's order. */
    visibleIds() {
        const ids = visiblePeople(this.model, this.filter, this.query, this.held, this.roundFilter);

        if (!this.sortActive()) {
            return ids;
        }

        return sortPeople(ids.map((id) => this.model.person(id)), this.sort, { countries: this.context.countries, locale: this.context.locale }).map((person) => person.id);
    }

    /** A stored sort by a column the event does not offer (registered on an event without management) is no sort. */
    sortActive() {
        if (this.sort === null) {
            return false;
        }

        return this.sort.key !== 'registered' || this.managed;
    }

    // ---------------------------------------------------------------- columns and rows

    columnOn(key) {
        const option = COLUMN_OPTIONS.find((candidate) => candidate.key === key);

        if (option === undefined || option.fixed) {
            return true;
        }

        if (!offeredFor([option], this.competition).length) {
            return false;
        }

        return typeof this.columnPrefs[key] === 'boolean' ? this.columnPrefs[key] : option.on;
    }

    toggleColumn(key) {
        this.columnPrefs = { ...this.columnPrefs, [key]: !this.columnOn(key) };
        writeColumnPrefs(browserStorage(), this.competition.id ?? '', this.columnPrefs);
        this.rebuildGrid();
        this.context.announce(this.say(this.columnOn(key) ? 'column_shown' : 'column_hidden', { column: this.say(`column_${key}`) }));
    }

    buildColumns() {
        const columns = [
            {
                key: SELECT,
                label: this.say('col_select'),
                kind: 'checkbox',
                width: SELECT_WIDTH,
                className: 'sheet-col-select',
                headerHtml: `<input type="checkbox" class="form-check-input sheet-select-all" tabindex="-1" aria-label="${escapeHtml(this.say('select_all_shown'))}" title="${escapeHtml(this.say('select_all_shown'))}">`,
            },
            this.sortable({ key: 'name', label: this.t('people_col_name'), kind: 'text', width: NAME_WIDTH, space: 'panel', className: 'sheet-col-name' }),
        ];

        if (this.columnOn('country')) {
            columns.push(this.sortable({ key: 'country', label: this.t('people_col_country'), kind: 'list', width: 180 }));
        }

        if (this.columnOn('player')) {
            // A profile is picked by Enter or a click only: Tab or a click elsewhere never links a search hit or unlinks
            columns.push({ key: 'player', label: this.t('people_col_profile'), kind: 'list', width: 240, autoHighlight: false, commitOnBlur: false });
        }

        if (this.columnOn('rounds')) {
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
        }

        const optional = [
            ['externalId', { kind: 'text', width: 150 }],
            ['source', { kind: 'readonly', width: 170 }],
            ['joined', { kind: 'readonly', width: 170 }],
            ['registration', { kind: 'action', width: 170 }],
            ['registered', { kind: 'readonly', width: 170 }],
            ['paid', { kind: 'readonly', width: 190 }],
            ['checkedIn', { kind: 'readonly', width: 150 }],
            ['note', { kind: 'text', width: 240, title: this.say('col_note_title') }],
        ];

        for (const [key, column] of optional) {
            if (this.columnOn(key)) {
                const label = this.say(`col_${key}`);
                columns.push(this.sortable({
                    key,
                    label,
                    ...column,
                    headerHtml: column.title
                        ? `<span title="${escapeHtml(column.title)}"><i class="bi bi-lock me-1" aria-hidden="true"></i>${escapeHtml(label)}<span class="visually-hidden"> (${escapeHtml(column.title)})</span></span>`
                        : undefined,
                }));
            }
        }

        columns.push({ key: ACTIONS, label: this.say('col_actions'), kind: 'action', width: 104, className: 'sheet-col-actions' });

        return columns;
    }

    /**
     * The grid's rows: who is shown, in the sort's order - while the organiser works in the grid (the focus in it, an
     * open edit) the rows shown already keep their places (`fresh` = a filter or sort change: everything in order).
     */
    /** A sortable column's header: a button (a click or Enter / Space toggles the order) - `aria-sort` on its cell. */
    sortable(column) {
        if (!SORTABLE.includes(column.key)) {
            return column;
        }

        const dir = this.sort?.key === column.key ? this.sort.dir : null;

        return {
            ...column,
            headerHtml: `<button type="button" class="sheet-sort" data-sort="${escapeHtml(column.key)}" title="${escapeHtml(this.say('sort_by', { column: column.label }))}">${escapeHtml(column.label)} <i class="bi ${sortIcon(dir)}" aria-hidden="true"></i></button>`,
        };
    }

    rowKeys({ fresh = false, previous = null } = {}) {
        let keys = this.visibleIds();

        if (previous !== null) {
            keys = keepOrder(keys, previous.filter((row) => row !== NEW_ROW));
        } else if (!fresh && this.grid && (this.grid.isEditing() || this.gridRoot.contains(document.activeElement))) {
            keys = keepOrder(keys, this.grid.rows.filter((row) => row !== NEW_ROW));
        }

        // Nobody is added to the removed people
        return this.filter === 'removed' ? keys : [...keys, NEW_ROW];
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

    /**
     * The people with a same-name marker and the waitlist positions - recomputed after people changed; returns the ids
     * whose marker or position moved (their rows are re-rendered too).
     */
    refreshMarkerSets() {
        const changed = new Set();
        const duplicates = new Set();

        for (const people of this.model.duplicateNames().values()) {
            people.forEach((person) => duplicates.add(person.id));
        }

        for (const id of new Set([...duplicates, ...this.duplicateIds])) {
            if (duplicates.has(id) !== this.duplicateIds.has(id)) {
                changed.add(id);
            }
        }

        this.duplicateIds = duplicates;

        if (this.managed) {
            const positions = waitlistPositions(this.model.people());

            for (const id of new Set([...positions.keys(), ...this.positions.keys()])) {
                if (positions.get(id) !== this.positions.get(id)) {
                    changed.add(id);
                }
            }

            this.positions = positions;
        }

        return changed;
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

        if (this.model.isRemoved(row)) {
            classes.push('sheet-row-removed');
        }

        if (this.selected.has(row)) {
            classes.push('sheet-row-selected');
        }

        if (this.panelPersonId === row) {
            classes.push('sheet-row-open');
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

        if (col === SELECT) {
            return { checked: this.selected.has(row), label: this.say('select_person', { name: person.name }), copy: '' };
        }

        if (col === ACTIONS) {
            return this.actionsCell(person);
        }

        const removed = person.removedAt !== null;
        const content = this.dataCell(person, col);

        // A removed person is read only until restored (the server refuses `participant_removed`)
        return removed && col !== 'name' ? { ...content, readonly: true, checked: undefined } : content;
    }

    dataCell(person, col) {
        switch (col) {
            case 'name':
                return this.nameCell(person);
            case 'country': {
                const label = person.country ? (this.context.countries[person.country] ?? person.country.toUpperCase()) : '';

                return {
                    text: label,
                    html: person.country ? `<span class="fi fi-${escapeHtml(person.country)} shadow-custom" aria-hidden="true"></span> ${escapeHtml(label)}` : '',
                    marker: this.marker(`person:${person.id}:country`),
                };
            }
            case 'player':
                return this.playerCell(person);
            case 'externalId':
                return { text: person.externalId ?? '', marker: this.marker(`person:${person.id}:externalId`) };
            case 'note':
                return { text: person.note ?? '', marker: this.marker(`person:${person.id}:note`) };
            case 'source':
                return { text: this.say(`source_${person.source === 'self_joined' || person.source === 'imported' ? person.source : 'manual'}`) };
            case 'joined':
                return { text: person.source === 'self_joined' && person.connectedAt ? formatDate(person.connectedAt, this.context.locale, true) : '' };
            case 'registration':
                return this.registrationCell(person);
            case 'registered':
                return { text: person.registration?.registeredAt ? formatDate(person.registration.registeredAt, this.context.locale, true) : '' };
            case 'paid':
                return this.paidCell(person);
            case 'checkedIn':
                return { text: person.registration?.checkedInAt ? formatDate(person.registration.checkedInAt, this.context.locale, true) : (person.registration ? '-' : '') };
            default:
                return this.roundCell(person, col);
        }
    }

    roundCell(person, col) {
        const roundId = col.slice('round:'.length);
        const round = this.model.round(roundId);

        if (round === null) {
            return { text: '', readonly: true };
        }

        let content;

        if (round.category === 'solo') {
            const checked = this.model.placeValue(person.id, roundId) !== 'out';
            content = {
                checked,
                label: this.t('people_in_round_label', { round: round.name, name: person.name }),
                marker: this.marker(`place:${person.id}:${roundId}`),
                // What a removed (read only) row shows instead of the box
                text: checked ? this.say('in_round_short') : '',
            };
        } else {
            content = this.teamCell(person, round);
        }

        // "No round yet": said once, in the first round column of somebody in no round at all
        if (!content.marker && person.removedAt === null && this.model.rounds()[0]?.id === roundId && this.model.placesOf(person.id).size === 0) {
            content = { ...content, marker: { state: 'info', text: this.say('no_round_yet'), title: this.say('no_round_yet_title') } };
        }

        return content;
    }

    nameCell(person) {
        const badges = [];

        if (person.removedAt !== null) {
            badges.push(`<span class="sheet-badge sheet-badge-removed">${escapeHtml(this.say('badge_removed'))}</span>`);
        }

        if (person.registration?.status === 'waitlisted') {
            badges.push(`<span class="sheet-badge sheet-badge-waitlist">${escapeHtml(this.t('people_badge_waitlisted'))}</span>`);
        }

        if (person.source === 'self_joined') {
            badges.push(`<span class="sheet-badge sheet-badge-joined"><i class="bi bi-dot" aria-hidden="true"></i>${escapeHtml(this.t('people_badge_joined'))}</span>`);
        }

        if (person.removedAt === null && this.duplicateIds.has(person.id)) {
            const others = this.model.peopleNamed(person.name).filter((other) => other.id !== person.id);
            const text = this.say('same_name_as', { name: others.map((other) => other.name).join(', ') });
            badges.push(`<span class="sheet-badge sheet-badge-duplicate" title="${escapeHtml(text)}"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i> ${escapeHtml(this.say('badge_same_name'))}<span class="visually-hidden">: ${escapeHtml(text)}</span></span>`);
        }

        const marker = this.marker(`person:${person.id}:name`) ?? this.marker(`person:${person.id}:removed`);
        const name = person.removedAt !== null ? `<s>${escapeHtml(person.name)}</s>` : escapeHtml(person.name);

        return {
            text: person.name,
            html: badges.length > 0 || person.removedAt !== null ? `${name}${badges.join('')}` : undefined,
            marker,
            readonly: person.removedAt !== null,
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

    registrationCell(person) {
        const status = registrationStatus(person);
        const marker = this.marker(`person:${person.id}:registration`);

        if (status === null) {
            return { text: '', readonly: true, marker };
        }

        const before = paidBefore(person);
        const beforeText = before ? this.say('paid_before', { date: formatDate(before, this.context.locale, false) }) : '';

        if (person.removedAt !== null) {
            // Cancelled: no status any more - only a payment's record ("paid on …, before the registration was cancelled")
            return beforeText
                ? { text: beforeText, html: `<span class="sheet-muted">${escapeHtml(beforeText)}</span>`, marker, copy: '' }
                : { text: '', readonly: true, marker };
        }

        const label = this.statusLabel(person);

        return {
            text: [label, beforeText].filter(Boolean).join(' - '),
            html: `<span class="sheet-reg sheet-reg-${escapeHtml(status)}">${escapeHtml(label)}</span>${before ? ` <i class="bi bi-cash-coin sheet-muted" title="${escapeHtml(beforeText)}" aria-hidden="true"></i><span class="visually-hidden">${escapeHtml(beforeText)}</span>` : ''}`,
            marker,
            copy: label,
        };
    }

    /** Reserved / Paid / Waitlist #3 */
    statusLabel(person) {
        const status = registrationStatus(person);

        if (status === 'waitlisted') {
            const position = this.positions.get(person.id);

            return position ? this.say('status_waitlisted_position', { position }) : this.say('status_waitlisted');
        }

        return this.say(`status_${status}`);
    }

    paidCell(person) {
        if (person.registration === null || person.registration === undefined) {
            return { text: '' };
        }

        const status = registrationStatus(person);

        if (status === 'paid' && person.registration.paidAt && person.removedAt === null) {
            return { text: formatDate(person.registration.paidAt, this.context.locale, false) };
        }

        const before = paidBefore(person);

        if (before) {
            const text = this.say('paid_before', { date: formatDate(before, this.context.locale, false) });

            return { text, html: `<span class="sheet-muted" title="${escapeHtml(text)}">${escapeHtml(text)}</span>` };
        }

        return { text: '-' };
    }

    actionsCell(person) {
        if (person.removedAt !== null) {
            const label = this.say('row_restore_label', { name: person.name });

            return {
                text: this.say('row_restore'),
                html: `<button type="button" class="btn btn-sm btn-outline-success sheet-row-action" data-row-action="restore" tabindex="-1" aria-label="${escapeHtml(label)}"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> ${escapeHtml(this.say('row_restore'))}</button>`,
                copy: '',
                marker: this.marker(`person:${person.id}:removed`),
            };
        }

        const label = this.say('row_actions_label', { name: person.name });

        return {
            text: this.say('row_actions'),
            html: `<button type="button" class="btn btn-sm btn-link sheet-row-action" data-row-action="menu" tabindex="-1" aria-label="${escapeHtml(label)}" title="${escapeHtml(label)}"><i class="bi bi-three-dots" aria-hidden="true"></i></button>`,
            copy: '',
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

        if (col === 'externalId' || col === 'note') {
            return person[col] ?? '';
        }

        return '';
    }

    /**
     * What the organiser sees in a cell when its editor opens (the model's value, their own pending one included) - the
     * grid hands it back with the commit as `seen`, sent as the change's `from`: somebody else's change reaching the
     * open cell comes back as a conflict instead of being overwritten.
     */
    seenValue(row, col) {
        const person = row === NEW_ROW ? null : this.model.person(row);

        if (person === null) {
            return undefined;
        }

        if (col === 'name' || col === 'country' || col === 'externalId' || col === 'note') {
            return person[col] ?? null;
        }

        if (col === 'player') {
            return person.player?.id ?? null;
        }

        return undefined;
    }

    /** The builders' options of a commit: `from` = what the organiser saw when the editor opened (one cell only). */
    commitOptions(info, rows) {
        return info?.seen !== undefined && rows.length === 1 ? { ...this.options(), from: info.seen } : this.options();
    }

    /** A fill from the editor (Ctrl+Enter): the edited cell's `from` is what its editor showed, the others' what they show. */
    fillValues(rows, row, value, info) {
        return rows.map((personId) => ({ personId, value, from: personId === row ? info?.seen : undefined }));
    }

    /**
     * The open editor's cell changed under it (a live update, a refused save of an earlier value): said next to the
     * editor - Keep mine sends the edit over the new value knowingly, Use theirs closes the editor; equal again = the
     * note goes. Enter without a choice sends over what the editor opened with (the server answers with a conflict).
     */
    noticeChangedMeanwhile() {
        const edit = this.grid?.editState?.() ?? null;

        if (edit === null || edit.row === NEW_ROW || edit.seen === undefined) {
            return;
        }

        const now = this.seenValue(edit.row, edit.col);

        if (now === undefined || JSON.stringify(now ?? null) === JSON.stringify(edit.seen ?? null)) {
            this.grid.editorNotice(null);

            return;
        }

        const player = this.model.person(edit.row)?.player;
        const shown = edit.col === 'country'
            ? (now === null ? this.t('people_no_country') : this.countryLabel(now))
            : (edit.col === 'player' ? (now === null ? '' : (player?.visible === true && player.id === now ? player.name : this.t('people_profile_hidden'))) : (now ?? ''));
        this.grid.editorNotice({
            text: this.t('editor_changed_meanwhile', { value: shown === '' || shown === null ? this.t('value_empty') : shown }),
            actions: [
                { label: this.t('editor_keep_mine'), run: () => this.grid?.commitWithSeen(this.seenValue(edit.row, edit.col)) },
                { label: this.t('editor_use_theirs'), run: () => this.grid?.cancelEdit(true) },
            ],
        });
    }

    // ---------------------------------------------------------------- suggestions

    countryOptions(query, person) {
        return countryOptions(this.context, query, person, (key) => this.t(key));
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
                options.push({ value: OPEN, label: this.t('people_profile_open'), className: 'sheet-option-action', action: true });
            }

            options.push({ value: UNLINK, label: this.t('people_profile_unlink'), className: 'sheet-option-action', action: true });
        }

        const text = query.trim();

        if (text.length < 2 || !this.context.urls.playerSearch) {
            return { options, hint: text.length > 0 ? this.t('people_profile_min_chars') : this.t('people_profile_search_hint') };
        }

        this.searchController?.abort();
        this.searchController = typeof AbortController === 'undefined' ? null : new AbortController();
        const found = await searchPlayers(this.context, this.model, text, person?.id ?? null, this.searchController?.signal);

        if (found === null) {
            return { options, hint: this.t('people_profile_search_failed') };
        }

        for (const player of found) {
            options.push({
                value: player.id,
                label: player.name,
                detail: [player.code ? `#${player.code}` : '', player.countryLabel, player.linkedTo ? this.t('people_profile_linked_to', { name: player.linkedTo }) : ''].filter(Boolean).join(' · '),
                html: `${player.country ? `<span class="fi fi-${escapeHtml(player.country)} shadow-custom" aria-hidden="true"></span> ` : ''}${escapeHtml(player.name)}`,
                player: player.player,
            });
        }

        return { options, hint: found.length === 0 ? this.t('people_profile_none') : '' };
    }

    // ---------------------------------------------------------------- editing

    /** An action performed through the controller; its client refusals come back as the editor's error. */
    perform(action) {
        const blocked = action.groups.length === 0 && action.errors.length > 0;
        const outcome = this.context.act(action, { quiet: blocked });

        if (blocked) {
            return { error: reasonFor(this.context, outcome.errors[0]) };
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
        const options = this.commitOptions(info, rows);

        if (col === 'name' || col === 'externalId' || col === 'note') {
            if (info.fill && rows.length > 1) {
                return this.perform(setFields(this.model, col, this.fillValues(rows, row, input.text, info), options));
            }

            // Nothing typed over what was shown: nothing is sent (whatever arrived meanwhile stays)
            if (options.from !== undefined && cleanFieldValue(col, input.text) === options.from) {
                return undefined;
            }

            return this.perform(setField(this.model, row, col, input.text, options));
        }

        if (col === 'country') {
            const code = this.countryFrom(input);

            if (code === undefined) {
                return { error: this.t('people_country_pick') };
            }

            if (options.from !== undefined && rows.length === 1 && code === options.from) {
                return undefined;
            }

            return this.perform(setFields(this.model, 'country', rows.length > 1 ? this.fillValues(rows, row, code, info) : rows.map((personId) => ({ personId, value: code })), options));
        }

        if (col === 'player') {
            const option = input.option;

            if (option?.value === OPEN) {
                window.open(person.player?.profileUrl ?? '', '_blank', 'noopener');

                return undefined;
            }

            if (option?.value === UNLINK) {
                return this.perform(linkProfile(this.model, row, null, options));
            }

            if (option?.player) {
                return this.perform(linkProfile(this.model, row, option.player, options));
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
        // Shown whatever the filter says - the organiser just typed them
        this.held.add(action.personId);
        const error = this.perform(action);

        if (error) {
            this.held.delete(action.personId);

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

                return columnKey === 'name' || columnKey === SELECT ? { row: NEW_ROW, col: 'name' } : { row: action.personId, col: columnKey };
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
        return readCountry(this.context.countries, text);
    }

    options() {
        return { countries: this.context.countryCodes };
    }

    toggle(cells, value) {
        // The selection column never mixes with data: a range over it changes only the selection
        const selection = cells.filter(({ row, col }) => col === SELECT && row !== NEW_ROW);

        if (selection.length > 0) {
            const target = value ?? !this.selected.has(selection[0].row);
            this.setSelected(selection.map(({ row }) => row), target);

            return;
        }

        const byRound = new Map();

        for (const { row, col } of cells) {
            if (row === NEW_ROW || !col.startsWith('round:') || this.model.isRemoved(row)) {
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
            if (row !== NEW_ROW && !this.model.isRemoved(row)) {
                byColumn.set(col, [...(byColumn.get(col) ?? []), row]);
            }
        }

        const actions = [];

        for (const [col, rows] of byColumn) {
            if (col === 'name') {
                this.notify(this.t('people_name_required'), { anchor: { row: rows[0], col } });
            } else if (col === SELECT) {
                this.setSelected(rows, false);
            } else if (col === 'country' || col === 'externalId' || col === 'note') {
                actions.push(setFields(this.model, col, rows.map((personId) => ({ personId, value: null })), this.options()));
            } else if (col === 'player') {
                actions.push(...rows.map((personId) => linkProfile(this.model, personId, null, this.options())));
            } else if (col.startsWith('round:') && this.model.round(col.slice(6))?.category === 'solo') {
                actions.push(setInRound(this.model, rows, col.slice(6), false, this.options()));
            } else if (col.startsWith('round:')) {
                this.notify(this.t('people_team_in_round_tab'), { kind: 'warning', anchor: { row: rows[0], col } });
            }
        }

        this.performMany(actions, { key: 'clear' });
    }

    /** Several builder actions as one undo step; client refusals announced (the rest goes on). */
    performMany(actions, label) {
        const action = combine(label, ...actions);

        if (isEmpty(action) && action.errors.length === 0) {
            return { performed: false, errors: [] };
        }

        return this.context.act(action);
    }

    fill(kind, range, active) {
        const rows = range.rows.filter((row) => row !== NEW_ROW && !this.model.isRemoved(row));
        const cols = range.cols.filter((col) => col === 'country' || this.isSoloColumn(col));

        if (cols.length === 0) {
            this.notify(this.t('people_fill_columns'), { kind: 'warning', anchor: active ? { row: active.row, col: active.col } : null });

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
        if (row === NEW_ROW) {
            return;
        }

        if (col === ACTIONS) {
            this.rowAction(row, this.model.isRemoved(row) ? 'restore' : 'menu', this.grid.cellElement(row, col));

            return;
        }

        if (col === 'registration') {
            this.openRegistrationMenu(row, this.grid.cellElement(row, col));

            return;
        }

        if (!col.startsWith('round:')) {
            return;
        }

        const roundId = col.slice('round:'.length);
        const place = parsePlace(this.model.placeValue(row, roundId));
        this.context.switchTab(roundId, { personId: row, teamId: place.teamId });
    }

    // ---------------------------------------------------------------- selection and the bulk bar

    setSelected(ids, value) {
        const changed = [];

        for (const id of ids) {
            if (value && !this.selected.has(id) && this.model.person(id) !== null) {
                this.selected.add(id);
                changed.push(id);
            } else if (!value && this.selected.has(id)) {
                this.selected.delete(id);
                changed.push(id);
            }
        }

        if (changed.length === 0) {
            return;
        }

        this.grid?.updateRows(changed);
        this.renderBulkBar();
        this.syncSelectAll();
        this.context.announce(this.selected.size > 0 ? this.sayCount('selected_count', this.selected.size) : this.say('selection_cleared'));
    }

    /** The header box: every shown row in or out of the selection. */
    selectShown(value) {
        this.setSelected(this.visibleIds(), value);
    }

    clearSelection() {
        this.setSelected([...this.selected], false);
    }

    syncSelectAll() {
        const box = this.gridRoot?.querySelector('.sheet-select-all');

        if (!box) {
            return;
        }

        const shown = this.grid?.rows.filter((row) => row !== NEW_ROW) ?? [];
        const count = shown.filter((row) => this.selected.has(row)).length;
        box.checked = shown.length > 0 && count === shown.length;
        box.indeterminate = count > 0 && count < shown.length;
    }

    selectedPeople() {
        return [...this.selected].map((id) => this.model.person(id)).filter(Boolean);
    }

    renderBulkBar() {
        const people = this.selectedPeople();

        this.context.root.classList.toggle('has-selection', people.length > 0);

        if (people.length === 0) {
            if (!this.bulkElement.hidden) {
                // The bar goes - a focus inside it goes back to the grid instead of the page
                const hadFocus = this.bulkElement.contains(document.activeElement);
                this.bulkElement.hidden = true;
                this.bulkElement.innerHTML = '';
                this.bulkElement.dataset.html = '';

                if (hadFocus) {
                    this.grid?.focusActive({ scroll: false });
                }
            }

            return;
        }

        const active = people.filter((person) => person.removedAt === null);
        const removed = people.length - active.length;
        const shown = new Set(this.grid?.rows ?? []);
        const notShown = people.filter((person) => !shown.has(person.id)).length;
        const solo = this.model.rounds().filter((round) => round.category === 'solo');
        const teamRounds = this.model.rounds().filter((round) => round.category !== 'solo');
        const parts = [`<span class="sheet-bulk-count"><strong>${escapeHtml(this.sayCount('selected_count', people.length))}</strong>${notShown > 0 ? ` <span class="sheet-bulk-hidden">${escapeHtml(this.sayCount('selected_not_shown', notShown))}</span>` : ''}</span>`];

        if (active.length > 0 && solo.length === 1) {
            const round = solo[0];
            parts.push(`<button type="button" class="btn btn-sm btn-light" data-bulk="in" data-round="${escapeHtml(round.id)}">${escapeHtml(this.say('bulk_solo_in', { round: round.name }))}</button>`);
            parts.push(`<button type="button" class="btn btn-sm btn-light" data-bulk="out" data-round="${escapeHtml(round.id)}">${escapeHtml(this.say('bulk_solo_out', { round: round.name }))}</button>`);
        } else if (active.length > 0 && solo.length > 1) {
            const items = solo.map((round) => `<li><button type="button" class="dropdown-item" data-bulk="in" data-round="${escapeHtml(round.id)}">${escapeHtml(this.say('bulk_solo_in', { round: round.name }))}</button></li><li><button type="button" class="dropdown-item" data-bulk="out" data-round="${escapeHtml(round.id)}">${escapeHtml(this.say('bulk_solo_out', { round: round.name }))}</button></li>`).join('<li><hr class="dropdown-divider"></li>');
            parts.push(`<div class="dropup"><button type="button" class="btn btn-sm btn-light dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">${escapeHtml(this.say('bulk_solo_menu'))}</button><ul class="dropdown-menu">${items}</ul></div>`);
        }

        if (active.length > 0 && teamRounds.length > 0) {
            const items = teamRounds.map((round) => `<li><button type="button" class="dropdown-item" data-bulk="team" data-round="${escapeHtml(round.id)}">${escapeHtml(this.say(round.category === 'duo' ? 'bulk_make_pair_in' : 'bulk_make_team_in', { round: round.name }))}</button></li>`).join('');
            parts.push(`<div class="dropup"><button type="button" class="btn btn-sm btn-light dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">${escapeHtml(this.say('bulk_make_team'))}</button><ul class="dropdown-menu">${items}</ul></div>`);
        }

        if (active.length > 0 && this.managed) {
            // Registration of several people at once (BR13) - a confirmation says who and how many e-mails go out
            parts.push(`<button type="button" class="btn btn-sm btn-light" data-bulk="markPaid">${escapeHtml(this.say('bulk_reg_markPaid'))}</button>`);

            if (this.competition.isOnline !== true) {
                parts.push(`<button type="button" class="btn btn-sm btn-light" data-bulk="checkIn">${escapeHtml(this.say('bulk_reg_checkIn'))}</button>`);
            }
        }

        if (active.length > 0) {
            parts.push(`<button type="button" class="btn btn-sm btn-outline-light" data-bulk="remove">${escapeHtml(this.say('bulk_remove'))}</button>`);
        }

        if (removed > 0) {
            parts.push(`<button type="button" class="btn btn-sm btn-light" data-bulk="restore">${escapeHtml(this.sayCount('bulk_restore', removed))}</button>`);
        }

        parts.push(`<button type="button" class="btn btn-sm btn-link sheet-bulk-clear" data-bulk="clear">${escapeHtml(this.say('bulk_clear'))}</button>`);

        const html = parts.join('');

        if (this.bulkElement.dataset.html !== html) {
            const focusedBulk = this.bulkElement.contains(document.activeElement) ? document.activeElement.dataset.bulk ?? null : null;
            this.bulkElement.innerHTML = html;
            this.bulkElement.dataset.html = html;

            if (focusedBulk) {
                this.bulkElement.querySelector(`[data-bulk="${CSS.escape(focusedBulk)}"]`)?.focus();
            }
        }

        this.bulkElement.hidden = false;
    }

    onBulkClick(event) {
        const button = event.target.closest('[data-bulk]');

        if (!button) {
            return;
        }

        const ids = this.selectedPeople().filter((person) => person.removedAt === null).map((person) => person.id);
        const roundId = button.dataset.round ?? null;

        switch (button.dataset.bulk) {
            case 'in':
            case 'out':
                this.bulkInRound(ids, roundId, button.dataset.bulk === 'in');
                break;
            case 'team':
                this.makeTeam(ids, roundId);
                break;
            case 'remove':
                this.removeWithCheck(ids);
                break;
            case 'markPaid':
            case 'checkIn':
                this.bulkRegistration(ids, button.dataset.bulk);
                break;
            case 'restore':
                this.restore(this.selectedPeople().filter((person) => person.removedAt !== null).map((person) => person.id));
                break;
            case 'clear':
                this.clearSelection();
                this.grid?.focusActive();
                break;
            default:
        }

        // An item of a closed dropup (or a button the bar re-rendered) leaves the focus nowhere: back to the bar, else the grid
        requestAnimationFrame(() => {
            const active = document.activeElement;

            if (active !== null && active !== document.body && active.isConnected && active.offsetParent !== null) {
                return;
            }

            const target = this.bulkElement.hidden ? null : (this.bulkElement.querySelector('.dropdown-toggle, [data-bulk]') ?? null);

            if (target !== null) {
                target.focus();
            } else {
                this.grid?.focusActive({ scroll: false });
            }
        });
    }

    bulkInRound(ids, roundId, inRound) {
        const round = this.model.round(roundId);
        const action = setInRound(this.model, ids, roundId, inRound, this.options());
        const outcome = this.context.act(action, { quiet: true });

        if (outcome.performed) {
            this.context.announce(this.sayCount(inRound ? 'bulk_solo_in_done' : 'bulk_solo_out_done', action.groups.length, { round: round?.name ?? '' }));
        } else if (action.errors.length === 0) {
            this.notify(this.say('bulk_nothing'), { kind: 'warning' });
        }

        this.announceRefused(action.errors);
    }

    /** Client refusals of a bulk action: how many and the reason of the first - shown, not only read out. */
    announceRefused(errors) {
        if (errors.length === 0) {
            return;
        }

        const personId = errors[0].change.participant ?? errors[0].change.id;
        const first = this.model.person(personId)?.name ?? '';
        const anchor = this.grid?.rows.includes(personId) ? { row: personId, col: 'name' } : null;
        // One person: the reason already names them
        this.notify(errors.length === 1
            ? reasonFor(this.context, errors[0])
            : this.sayCount('bulk_refused', errors.length, { name: first, reason: reasonFor(this.context, errors[0]) }), { anchor });
    }

    /**
     * "Make a pair/team": a new pair/team of the round with exactly the selected people, each moved out of their current
     * pair/team of that round. Previewed (with the server's dry run) when it moves anybody or the size is off.
     */
    async makeTeam(ids, roundId) {
        const round = this.model.round(roundId);

        if (round === null || ids.length === 0) {
            return;
        }

        const action = newTeamRow(this.model, roundId, { members: ids }, this.options());
        const moves = ids.filter((id) => parsePlace(this.model.placeValue(id, roundId)).kind === 'team');
        const expected = this.model.expectedSize(roundId);
        const sizeOff = expected !== null && ids.length !== expected && !this.model.isNamesOnly(roundId);
        const kind = round.category === 'duo' ? 'pair' : 'team';

        if (action.groups.length === 0) {
            this.announceRefused(action.errors);

            return;
        }

        if (moves.length > 0 || sizeOff || action.errors.length > 0) {
            const confirmed = await this.previewTeam(action, round, ids, moves, expected);

            if (!confirmed) {
                this.context.announce(this.say('make_team_cancelled'));

                return;
            }
        }

        const outcome = this.context.act(action, { quiet: true });

        if (outcome.performed) {
            const names = ids.map((id) => this.model.person(id)?.name ?? '').join(', ');
            this.context.announce(this.say(`make_${kind}_done`, { round: round.name, names }));
        }
    }

    async previewTeam(action, round, ids, moves, expected) {
        const kind = round.category === 'duo' ? 'pair' : 'team';
        const lines = ids.map((id) => {
            const person = this.model.person(id);
            const place = parsePlace(this.model.placeValue(id, round.id));
            let note = this.say('make_team_line_new', { round: round.name });
            let status = 'new';

            if (place.kind === 'team') {
                note = this.say('make_team_line_moves', { from: this.teamLabelText(place.teamId) });
                status = 'change';
            } else if (place.kind === 'in') {
                note = this.say(`make_team_line_from_tray_${kind}`);
                status = 'change';
            }

            return { id: `p-${id}`, text: person?.name ?? '', note, status };
        });

        if (expected !== null && ids.length !== expected) {
            lines.unshift({ id: 'size', text: this.say(`make_team_size_${kind}`, { count: ids.length, expected }), status: 'warning' });
        }

        let blocked = false;
        const dialog = this.context.preview({
            title: this.say(`make_${kind}_title`, { round: round.name }),
            intro: this.say('make_team_intro'),
            counts: [{ text: this.sayCount('make_team_count_people', ids.length) }, ...(moves.length > 0 ? [{ text: this.sayCount('make_team_count_moves', moves.length), tone: 'warning' }] : [])],
            lines,
            loading: true,
            confirmLabel: this.say(`make_${kind}_confirm`),
            // What the server refused stays refused: the dialog cannot be confirmed
            onConfirm: () => (blocked ? { error: this.say('make_team_blocked') } : undefined),
            returnFocus: () => this.grid?.focusActive({ scroll: false }),
        });
        holdConfirm(dialog, () => blocked);

        const answer = await this.context.queue.preview(action.groups);
        const extra = [];

        if (answer.kind === 'ok') {
            for (const group of answer.data?.groups ?? []) {
                for (const change of group.changes ?? []) {
                    if ((change.status === 'refused' || change.status === 'conflict') && change.message) {
                        extra.push({ id: `r${extra.length}`, text: change.message, status: 'error' });
                        blocked = true;
                    }
                }

                for (const warning of group.warnings ?? []) {
                    if (warning.message && warning.code !== 'team_size_off') {
                        extra.push({ id: `w${extra.length}`, text: warning.message, status: 'warning' });
                    }
                }
            }
        }

        dialog.update({
            loading: false,
            lines: [...extra, ...lines],
            message: answer.kind === 'ok' ? (blocked ? this.say('make_team_blocked') : '') : this.t('people_paste_unchecked'),
            confirmLabel: this.say(`make_${kind}_confirm`),
        });

        const selection = await dialog.result;

        return selection !== null;
    }

    /** O1: `Corners · Table 2 · Kim Example, Pat Sample` (no "Table n" without one, "(no name)" for an unnamed one). */
    teamLabelText(teamId) {
        return teamLabelText(this.model, teamId, (key, params) => this.t(key, params), (key, params) => this.say(key, params));
    }

    /**
     * Remove from the event. The large-removal check counts who would really go (the built action's groups - people the
     * sheet refuses, e.g. with a recorded result, are not counted and are said).
     */
    async removeWithCheck(ids) {
        const active = ids.filter((id) => !this.model.isRemoved(id));

        if (active.length === 0) {
            return;
        }

        let action = removePeople(this.model, active, this.options());
        const count = action.groups.length;
        const total = this.model.people().length;

        if (count === 0) {
            this.announceRefused(action.errors);

            return;
        }

        if (count >= LARGE_REMOVAL_MIN && count > total * LARGE_REMOVAL_SHARE) {
            const confirmed = await confirmTyped({
                host: this.context.root,
                title: this.sayCount('remove_many_title', count),
                text: this.say('remove_many_text', { count, total }),
                prompt: this.say('remove_many_prompt', { count }),
                expected: String(count),
                confirmLabel: this.sayCount('remove_many_confirm', count),
                cancelLabel: this.t('preview_cancel'),
                closeLabel: this.t('preview_close'),
            });

            if (!confirmed) {
                this.context.announce(this.say('remove_cancelled'));
                this.grid?.focusActive({ scroll: false });

                return;
            }

            // Built again from what the page shows now (a live change during the dialog); the refused ones said below
            const rebuilt = removePeople(this.model, action.groups.map((group) => group.changes[0].participant), this.options());
            action = { ...rebuilt, errors: [...action.errors, ...rebuilt.errors] };
        }

        const outcome = this.context.act(action, { quiet: true });

        if (outcome.performed) {
            this.setSelected(action.groups.map((group) => group.changes[0].participant), false);
            this.context.announce(this.sayCount('removed_count', action.groups.length));
        }

        this.announceRefused(action.errors);
    }

    restore(ids) {
        const action = restorePeople(this.model, ids, this.options());
        const outcome = this.context.act(action, { quiet: true });

        if (outcome.performed) {
            this.setSelected(action.groups.map((group) => group.changes[0].participant), false);
            this.context.announce(this.sayCount('restored_count', action.groups.length));
        }

        this.announceRefused(action.errors);
    }

    // ---------------------------------------------------------------- row actions, registration menu

    rowAction(personId, kind, cell) {
        if (kind === 'restore') {
            this.restore([personId]);

            return;
        }

        const person = this.model.person(personId);

        if (person === null) {
            return;
        }

        const items = [
            { label: this.say('row_edit'), icon: 'bi-pencil', run: () => this.openEditor(personId) },
        ];

        if (this.managed && allowedActions(person, { checkIn: this.competition.isOnline !== true }).length > 0) {
            items.push({ label: this.say('row_registration'), icon: 'bi-ticket-perforated', run: () => this.openRegistrationMenu(personId, cell) });
        }

        items.push({ label: this.say('row_remove'), icon: 'bi-person-x', danger: true, run: () => this.removeWithCheck([personId]) });
        this.openMenu(cell, items, this.say('row_actions_label', { name: person.name }));
    }

    openRegistrationMenu(personId, cell) {
        const person = this.model.person(personId);

        if (person === null || !this.managed) {
            return;
        }

        const actions = allowedActions(person, { checkIn: this.competition.isOnline !== true });

        if (actions.length === 0) {
            this.notify(this.say('registration_no_actions', { name: person.name }), { kind: 'warning', anchor: { row: personId, col: 'registration' } });

            return;
        }

        const items = actions.map((action) => ({
            label: this.say(`reg_${action}`),
            description: this.say(`reg_${action}_help`),
            run: () => this.registrationAction(personId, action),
        }));
        this.openMenu(cell, items, this.say('registration_menu_label', { name: person.name, status: this.statusLabel(person) }));
    }

    registrationAction(personId, action) {
        const anchor = this.grid?.rows.includes(personId) && this.grid.colIndex('registration') !== -1 ? { row: personId, col: 'registration' } : null;

        return performRegistrationAction(this.context, personId, action, { anchor });
    }

    /**
     * Mark paid / Check in for the selected people (BR13): who it applies to, how many e-mails go out (Mark paid sends
     * one per person linked to a MySpeedPuzzling account, Check in none) - confirmed, then sent one by one through the
     * registration endpoint with progress in the dialog (Stop stops after the request on its way), summed up in one
     * message. Signed out or no rights any more: stopped, the rest not sent.
     */
    async bulkRegistration(ids, action) {
        const people = ids.map((id) => this.model.person(id)).filter(Boolean);
        const plan = bulkRegistrationPlan(people, action, { checkIn: this.competition.isOnline !== true });

        if (plan.eligible.length === 0) {
            this.notify(this.say(`bulk_reg_none_${action}`), { kind: 'warning' });

            return null;
        }

        let emails;

        if (action !== 'markPaid') {
            emails = this.say('bulk_reg_no_emails');
        } else if (plan.emails === 0) {
            emails = this.say('bulk_reg_no_email_linked');
        } else {
            emails = this.sayCount('bulk_reg_emails', plan.emails);
        }

        const result = await runInDialog({
            host: this.context.root,
            title: this.sayCount(`bulk_reg_title_${action}`, plan.eligible.length),
            lines: [emails, ...(plan.skipped.length > 0 ? [this.sayCount('bulk_reg_skipped', plan.skipped.length)] : [])],
            confirmLabel: this.sayCount(`bulk_reg_confirm_${action}`, plan.eligible.length),
            cancelLabel: this.t('preview_cancel'),
            closeLabel: this.t('preview_close'),
            stopLabel: this.say('bulk_reg_stop'),
            progress: (done, total) => this.say('bulk_reg_progress', { done, count: total }),
            run: (control, onProgress) => runRegistrationBulk(this.context, plan.eligible, action, { control, onProgress }),
        });

        if (result === null) {
            this.context.announce(this.say('make_team_cancelled'));
            this.grid?.focusActive({ scroll: false });

            return null;
        }

        this.notify(this.bulkRegistrationSummary(action, result), { kind: result.failed.length > 0 || result.stopped !== null ? 'error' : 'info' });

        return result;
    }

    /** "12 people marked paid. 2 not changed, e.g. Kim Example - … Stopped - you were signed out. 5 were not sent." */
    bulkRegistrationSummary(action, result) {
        const parts = [];

        if (result.done.length > 0) {
            parts.push(this.sayCount(`bulk_reg_done_${action}`, result.done.length));
        }

        const refused = result.failed.filter((failure) => result.stopped === null || result.stopped === 'user' || failure !== result.failed.at(-1));

        if (refused.length > 0) {
            parts.push(this.sayCount('bulk_reg_failed', refused.length, { name: this.model.person(refused[0].id)?.name ?? '', reason: refused[0].message }));
        }

        if (result.stopped === 'user') {
            parts.push(this.sayCount('bulk_reg_stopped_by_you', result.notSent.length));
        } else if (result.stopped !== null) {
            // Signed out / no rights any more: the one that met it and the rest
            parts.push(this.sayCount(`bulk_reg_stopped_${result.stopped}`, result.notSent.length + 1));
        }

        return parts.join(' ');
    }

    /**
     * A small menu at a cell (row actions, registration): arrows / Home / End move, Enter or Space picks, Esc or Tab
     * closes and the focus goes back to the cell.
     */
    openMenu(anchor, items, label) {
        this.closeMenu(false);

        const menu = document.createElement('div');
        // Not a Bootstrap .dropdown-menu: its document-level keyboard handler would take the arrows to another dropdown
        menu.className = 'sheet-cell-menu';
        menu.setAttribute('role', 'menu');
        menu.setAttribute('aria-label', label);
        menu.innerHTML = items.map((item, index) => `<button type="button" class="dropdown-item${item.danger ? ' text-danger' : ''}" role="menuitem" data-item="${index}" tabindex="-1">${item.icon ? `<i class="bi ${escapeHtml(item.icon)} me-2" aria-hidden="true"></i>` : ''}${escapeHtml(item.label)}${item.description ? `<small class="sheet-cell-menu-help">${escapeHtml(item.description)}</small>` : ''}</button>`).join('');
        this.host.append(menu);

        const rect = (anchor ?? this.gridRoot).getBoundingClientRect();
        const width = menu.offsetWidth;
        const height = menu.offsetHeight;
        const left = Math.max(8, Math.min(rect.left, window.innerWidth - width - 8));
        const below = rect.bottom + 2;
        menu.style.left = `${left}px`;
        menu.style.top = `${below + height > window.innerHeight - 8 ? Math.max(8, rect.top - height - 2) : below}px`;

        const buttons = [...menu.querySelectorAll('[role="menuitem"]')];
        const close = (refocus) => this.closeMenu(refocus);
        menu.addEventListener('click', (event) => {
            const button = event.target.closest('[data-item]');

            if (button) {
                close(true);
                items[Number(button.dataset.item)].run();
            }
        });
        menu.addEventListener('keydown', (event) => {
            const index = buttons.indexOf(document.activeElement);
            const next = { ArrowDown: index + 1, ArrowUp: index - 1, Home: 0, End: buttons.length - 1 }[event.key];

            if (next !== undefined) {
                event.preventDefault();
                buttons[(next + buttons.length) % buttons.length].focus();
            } else if (event.key === 'Escape' || event.key === 'Tab') {
                event.preventDefault();
                event.stopPropagation();
                close(true);
            }
        });
        const outside = (event) => {
            if (!menu.contains(event.target)) {
                close(false);
            }
        };
        document.addEventListener('pointerdown', outside, true);
        this.menu = { element: menu, outside };
        buttons[0]?.focus();
    }

    closeMenu(refocus) {
        if (this.menu === null) {
            return;
        }

        document.removeEventListener('pointerdown', this.menu.outside, true);
        this.menu.element.remove();
        this.menu = null;

        if (refocus) {
            this.grid?.focusActive({ scroll: false });
        }
    }

    // ---------------------------------------------------------------- the person editor

    openEditor(personId) {
        if (personId === NEW_ROW || this.model.person(personId) === null) {
            return;
        }

        this.context.openPersonEditor(personId, {
            // Previous / next walk the rows as shown (filtered, sorted)
            list: () => (this.grid ? this.grid.rows.filter((row) => row !== NEW_ROW) : this.visibleIds()),
            onShow: (id) => this.panelShows(id),
            returnFocus: (id) => {
                if (this.grid === null) {
                    return;
                }

                if (!this.grid.focusCell(id, 'name')) {
                    this.grid.focusActive();
                }
            },
        });
    }

    /** The editor shows a person (or closed - null): the row is marked and stays shown whatever the filter says. */
    panelShows(personId) {
        const previous = this.panelPersonId;
        this.panelPersonId = personId;

        if (personId !== null) {
            this.held.add(personId);
        }

        if (this.grid !== null) {
            const keys = this.rowKeys();

            if (keys.length !== this.grid.rows.length || keys.some((key, index) => this.grid.rows[index] !== key)) {
                this.grid.setRows(keys);
            }

            this.grid.updateRows([previous, personId].filter(Boolean));
        }
    }

    // ---------------------------------------------------------------- paste

    /**
     * A block from a spreadsheet: one value onto a selection fills it; otherwise the block lands at the active cell,
     * column by column - names, countries (a code or a name), solo rounds (TRUE/FALSE, x, yes/no...), external ids,
     * notes. A block pasted onto the new-person row - or the part of a block in the name column that reaches past the
     * end of the list - adds people (`name ⇥ country ⇥ external id`, people_paste.js). Nothing invalid is dropped
     * silently: unknown countries, unreadable checkboxes, profiles and pair/team cells are listed. More than a few rows -
     * or anything left out, new people, or a name changed (renames, BR12) - are previewed first (the server's dry run
     * included). The selection column takes no values: a block pasted there lands on the names (review M1).
     */
    paste(anchor, block, selected) {
        if (anchor.col === SELECT) {
            anchor = { row: anchor.row, col: 'name' };
        }

        const rows = this.grid.rows;
        const people = rows.filter((row) => row !== NEW_ROW);
        let names = [];
        let edits = block;

        if (anchor.row === NEW_ROW) {
            names = block;
            edits = [];
        } else if (anchor.col === 'name' && !(block.length === 1 && block[0].length === 1 && selected.length > 1)) {
            const fits = Math.max(0, people.length - rows.indexOf(anchor.row));
            names = block.slice(fits);
            edits = block.slice(0, fits);
        }

        if (this.filter === 'removed' && names.length > 0) {
            // The removed people's list adds nobody - said, nothing applied
            this.notify(this.say('paste_no_add_removed'), { anchor });

            return;
        }

        const plan = edits.length > 0 ? this.planPaste(anchor, edits, selected) : { actions: [], problems: [], rows: 0, changes: 0 };
        const namePlan = names.length > 0 ? planNamePaste(this.model, names, { readCountry: (text) => this.readCountry(text), headerNames: this.headerNames() }) : null;

        if (namePlan !== null) {
            this.previewPaste(plan, namePlan);

            return;
        }

        if (plan.changes === 0 && plan.problems.length === 0) {
            this.notify(this.t('people_paste_nothing'), { kind: 'warning', anchor });

            return;
        }

        // Renames always go through the preview, whatever the size (BR12) - a paste one row off renames everybody
        if (plan.rows <= PREVIEW_ABOVE_ROWS && plan.problems.length === 0 && plan.renames === 0) {
            this.performMany(plan.actions, { key: 'paste' });
            this.context.announce(this.texts.tc('people_pasted', plan.changes));

            return;
        }

        this.previewPaste(plan, null);
    }

    /** The column names a header line of a pasted list would carry (left out when it is the first line). */
    headerNames() {
        return [this.t('people_col_name'), this.say('col_name_alt'), this.t('people_col_country'), this.say('col_externalId')];
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
        const fields = { name: [], country: [], externalId: [], note: [] };
        const rounds = new Map();
        let beyond = 0;
        let onSelection = 0;
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

            if (col === SELECT) {
                // Never written: the selection is not data (listed, so the paste is previewed - review M1)
                if (trimCell(value) !== '') {
                    onSelection++;
                }

                continue;
            }

            if (person.removedAt !== null) {
                if (trimCell(value) !== '' && col !== SELECT && col !== ACTIONS) {
                    problems.push({ text: person.name, note: reasonFor(this.context, { reason: 'participant_removed', change: { participant: row } }), status: 'skip' });
                }

                continue;
            }

            touchedRows.add(row);

            if (col === 'name') {
                if (trimCell(value) === '') {
                    problems.push({ text: person.name, note: this.t('people_name_required'), status: 'error' });
                } else {
                    fields.name.push({ personId: row, value: trimCell(value) });
                }
            } else if (col === 'country') {
                const code = this.readCountry(value);

                if (code === undefined) {
                    problems.push({ text: person.name, note: this.t('people_paste_unknown_country', { value: trimCell(value) }), status: 'error' });
                } else {
                    fields.country.push({ personId: row, value: code });
                }
            } else if (col === 'externalId' || col === 'note') {
                fields[col].push({ personId: row, value: trimCell(value) });
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
            } else if (trimCell(value) !== '' && col !== SELECT) {
                const column = columns.find((candidate) => candidate.key === col);
                problems.push({ text: person.name, note: this.t('people_paste_column_skipped', { column: column?.label ?? '' }), status: 'skip' });
            }
        }

        const renames = setFields(this.model, 'name', fields.name, this.options());
        const actions = [
            renames,
            ...['country', 'externalId', 'note'].map((field) => setFields(this.model, field, fields[field], this.options())),
            ...[...rounds].flatMap(([roundId, lists]) => [
                setInRound(this.model, lists.in, roundId, true, this.options()),
                setInRound(this.model, lists.out, roundId, false, this.options()),
            ]),
        ];

        for (const action of actions) {
            for (const error of action.errors) {
                problems.push({ text: this.model.person(error.change.participant ?? error.change.id)?.name ?? '', note: reasonFor(this.context, error), status: 'error' });
            }
        }

        if (beyond > 0) {
            problems.push({ text: this.texts.tc('people_paste_beyond', beyond), note: this.t('people_paste_beyond_note'), status: 'skip' });
        }

        if (onSelection > 0) {
            problems.push({ text: this.sayCount('paste_select_skipped', onSelection), note: this.say('paste_select_skipped_note'), status: 'skip' });
        }

        return {
            actions,
            problems,
            rows: touchedRows.size,
            changes: actions.reduce((sum, action) => sum + action.groups.length, 0),
            renames: renames.groups.length,
        };
    }

    /**
     * The preview of a paste: the edits of existing rows (a line per change) and/or the people a list of names adds
     * (a line per name: new - ticked, unless it looks like a mistake (a close name - "Did you mean …?", a country code,
     * a number, an e-mail - unticked with the reason, BR9); already on the list, removed earlier - "restore?" unticked,
     * twice in the paste, not possible). For names, "And put them into ▾" offers the solo rounds (BR3): the new and
     * restored people go into it, and so do the people already on the list. The server's dry run checks it all before
     * anything is applied (again when the round changes); one Confirm, one undo step.
     */
    async previewPaste(plan, namePlan) {
        const edits = combine({ key: 'paste' }, ...plan.actions);
        const editLines = edits.groups.map((group) => ({ id: group.id, ...this.describeGroup(group), status: 'change' }));
        const errorLines = plan.problems.map((problem, index) => ({ id: `p${index}`, ...problem }));
        const soloRounds = namePlan ? this.model.rounds().filter((round) => round.category === 'solo') : [];
        const allTicked = namePlan ? Object.fromEntries(namePlan.lines.map((line) => [line.id, true])) : {};
        let roundId = null;
        let refusedEdits = new Set();
        let refusedLines = new Map();
        let checks = 0;
        let dialog = null;

        // The lines' ticks as the organiser left them (the plan's defaults until they touch one)
        const ticksNow = () => (namePlan ? Object.fromEntries(namePlan.lines.map((line) => [line.id, dialog !== null && line.id in dialog.ticks ? dialog.ticks[line.id] : line.tick])) : {});
        const namesAction = (ticks) => namePasteAction(this.model, namePlan, ticks, { ...this.options(), roundId, skip: new Set(refusedLines.keys()) });
        const confirmLabel = () => this.texts.tc('people_paste_confirm', editLines.length - refusedEdits.size + (namePlan ? namesAction(ticksNow()).action.groups.length : 0));
        const round = () => (roundId ? this.model.round(roundId) : null);
        const counts = () => this.pasteCounts(editLines.length - refusedEdits.size, namePlan, refusedLines, plan.problems.length, round(), namePlan ? namesAction(ticksNow()).placed.length : 0);
        const linesNow = () => [
            ...errorLines,
            ...editLines.map((line) => (refusedEdits.has(line.id) ? { ...line, status: 'error', note: line.refusal } : line)),
            ...(namePlan ? namePlan.lines : []).map((line) => (refusedLines.has(line.id)
                ? { ...this.nameLine(line, round()), status: 'error', note: refusedLines.get(line.id), tick: undefined }
                : this.nameLine(line, round()))),
        ];

        // The server's rules (results, profiles linked elsewhere) before anything is applied - everything that could
        // go: the edits and every name (ticked or not), into the chosen round
        const check = async () => {
            const run = ++checks;
            const candidates = namePlan ? namePasteAction(this.model, namePlan, allTicked, { ...this.options(), roundId }) : null;
            const lineRefusals = new Map();

            for (const error of candidates?.action.errors ?? []) {
                const lineId = lineOfError(error, namePlan, candidates.lineOfPerson);

                if (lineId !== null) {
                    lineRefusals.set(lineId, reasonFor(this.context, error));
                }
            }

            const dryGroups = [...edits.groups, ...(candidates?.action.groups ?? [])];
            let answer = { kind: 'ok', data: { groups: [] } };

            if (dryGroups.length > 0) {
                dialog.update({ loading: true });
                answer = await this.context.queue.preview(dryGroups);
            }

            if (run !== checks || dialog.settled) {
                // A newer check (another round chosen) or the dialog went
                return;
            }

            const editRefusals = new Set();

            if (answer.kind === 'ok') {
                for (const group of answer.data?.groups ?? []) {
                    if (group.status !== 'refused' && group.status !== 'conflict') {
                        continue;
                    }

                    const change = group.changes.find((candidate) => candidate.status === 'refused' || candidate.status === 'conflict');
                    const message = change?.message ?? this.t('people_paste_not_possible');
                    const editLine = editLines.find((line) => line.id === group.id);

                    if (editLine) {
                        editLine.refusal = message;
                        editRefusals.add(group.id);
                    } else if (candidates?.lineOfGroup.has(group.id)) {
                        lineRefusals.set(candidates.lineOfGroup.get(group.id), message);
                    }
                }
            }

            refusedEdits = editRefusals;
            refusedLines = lineRefusals;
            dialog.update({
                loading: false,
                lines: linesNow(),
                counts: counts(),
                confirmLabel: confirmLabel(),
                message: answer.kind === 'ok' ? '' : this.t('people_paste_unchecked'),
            });
        };

        dialog = this.context.preview({
            title: namePlan ? this.say('paste_names_title') : this.t('people_paste_title'),
            intro: namePlan ? this.say('paste_names_intro') : this.t('people_paste_intro'),
            counts: counts(),
            lines: linesNow(),
            loading: true,
            confirmLabel: confirmLabel(),
            returnFocus: () => this.grid?.focusActive({ scroll: false }),
        });

        // The Confirm button counts what the ticks say
        dialog.dialog?.addEventListener('change', (event) => {
            if (event.target.closest('[data-tick]')) {
                const label = confirmLabel();
                dialog.options.confirmLabel = label;
                dialog.dialog.querySelector('[data-preview-confirm]').textContent = label;
            }
        });

        if (soloRounds.length > 0 && dialog.dialog) {
            // "And put them into ▾ Group A" (BR3) - outside the lines, which are drawn again on every check
            const id = `${dialog.id ?? 'sheet-preview'}-round`;
            const choice = document.createElement('div');
            choice.className = 'sheet-paste-round';
            choice.innerHTML = `<label class="form-label mb-0" for="${escapeHtml(id)}">${escapeHtml(this.say('paste_round_label'))}</label>
                <select class="form-select form-select-sm" id="${escapeHtml(id)}" data-paste-round>
                    <option value="">${escapeHtml(this.say('paste_round_none'))}</option>
                    ${soloRounds.map((candidate) => `<option value="${escapeHtml(candidate.id)}">${escapeHtml(candidate.name)}</option>`).join('')}
                </select>`;
            const intro = dialog.dialog.querySelector('[data-preview-intro]');

            if (intro) {
                intro.after(choice);
            } else {
                dialog.dialog.querySelector('[data-preview-lines]')?.before(choice);
            }

            choice.querySelector('select').addEventListener('change', (event) => {
                roundId = event.target.value || null;
                check();
            });
        }

        await check();
        const selection = await dialog.result;

        if (selection === null) {
            this.context.announce(this.t('people_paste_cancelled'));

            return;
        }

        const keptEdits = edits.groups.filter((group) => !refusedEdits.has(group.id));
        const keptIds = new Set(keptEdits.map((group) => group.id));
        const editAction = { ...edits, groups: keptEdits, inverse: edits.inverse.filter((group) => keptIds.has(group.inverseOf)), errors: [] };
        let added = null;

        if (namePlan !== null) {
            added = namesAction({ ...Object.fromEntries(namePlan.lines.map((line) => [line.id, line.tick])), ...selection.ticks });
            added.peopleIds.forEach((id) => this.held.add(id));
        }

        // Applied as previewed - from-values are rechecked by the server, a change meanwhile comes back as a conflict
        const action = combine({ key: 'paste' }, editAction, added?.action ?? null);
        action.errors = [];

        if (isEmpty(action)) {
            this.notify(this.t('people_paste_nothing'), { kind: 'warning' });

            return;
        }

        this.context.act(action);
        const parts = [];

        if (keptEdits.length > 0) {
            parts.push(this.texts.tc('people_pasted', keptEdits.length));
        }

        if (added !== null && added.peopleIds.length > 0) {
            parts.push(this.sayCount('paste_names_done', added.peopleIds.length));
        }

        if (added !== null && added.placed.length > 0) {
            parts.push(this.sayCount('paste_round_done', added.placed.length, { round: round()?.name ?? '' }));
        }

        this.context.announce(parts.join(' '));
    }

    /** A line of the names preview (`round` = the round they go into, or null). */
    nameLine(line, round = null) {
        const details = [];

        if (line.country) {
            details.push(this.countryLabel(line.country));
        }

        if (line.externalId) {
            details.push(this.say('paste_external_id', { id: line.externalId }));
        }

        if (line.countryText) {
            details.push(this.t('people_paste_unknown_country', { value: line.countryText }));
        }

        const named = (ids) => ids.map((id) => this.model.person(id)?.name ?? '').filter(Boolean).join(', ');
        const placement = placementOf(this.model, line, round?.id ?? null);
        const into = round && placement === INTO_PUT ? this.say('paste_line_into', { round: round.name }) : '';

        switch (line.status) {
            case LINE_NEW: {
                if (line.hint) {
                    const hint = line.hint.kind === HINT_CLOSE
                        ? this.say('paste_close', { name: named(line.hint.ids.slice(0, 2)) })
                        : this.say(`paste_looks_${line.hint.kind}`);

                    return { id: line.id, text: line.name, note: [hint, ...details, into].filter(Boolean).join(' · '), status: 'warning', tick: { label: this.say('paste_tick_add'), checked: false } };
                }

                return { id: line.id, text: line.name, note: [...details, into].filter(Boolean).join(' · '), status: line.countryText ? 'warning' : 'new', tick: { label: this.say('paste_tick_add'), checked: true } };
            }
            case LINE_EXISTING: {
                const as = named(line.matches);
                const known = as && as !== line.name ? this.say('paste_existing_as', { name: as }) : this.say('paste_existing');

                if (placement === INTO_PUT) {
                    return { id: line.id, text: line.name, note: this.say('paste_existing_into', { round: round.name, name: as }), status: 'change' };
                }

                if (placement === INTO_ALREADY) {
                    return { id: line.id, text: line.name, note: this.say('paste_existing_already', { round: round.name }), status: 'same' };
                }

                if (placement === INTO_AMBIGUOUS) {
                    return { id: line.id, text: line.name, note: this.say('paste_existing_ambiguous', { round: round.name, name: as }), status: 'warning' };
                }

                return { id: line.id, text: line.name, note: known, status: 'same' };
            }
            case LINE_REMOVED:
                return { id: line.id, text: line.name, note: [this.say('paste_removed', { name: named(line.matches.slice(0, 1)) }), into].filter(Boolean).join(' · '), status: 'warning', tick: { label: this.say('paste_tick_restore'), checked: false } };
            case LINE_DUPLICATE:
                return { id: line.id, text: line.name, note: this.say('paste_duplicate'), status: 'skip' };
            case LINE_HEADER:
                return { id: line.id, text: line.name, note: this.say('paste_header'), status: 'skip' };
            case LINE_INVALID:
            default:
                return { id: line.id, text: line.name || this.say('paste_no_name'), note: reasonFor(this.context, { reason: line.reason ?? 'invalid_change', change: { name: line.name } }), status: 'error' };
        }
    }

    pasteCounts(changes, namePlan, refusedLines, problems, round = null, placed = 0) {
        const counts = [];

        if (changes > 0 || namePlan === null) {
            counts.push({ text: this.texts.tc('people_paste_count_changes', changes) });
        }

        if (namePlan !== null) {
            const newOnes = namePlan.lines.filter((line) => line.status === LINE_NEW && !line.hint && !refusedLines.has(line.id)).length;
            const unsure = namePlan.lines.filter((line) => line.status === LINE_NEW && line.hint && !refusedLines.has(line.id)).length;
            counts.push({ text: this.sayCount('paste_count_new', newOnes) });

            if (unsure > 0) {
                counts.push({ text: this.sayCount('paste_count_unsure', unsure), tone: 'warning' });
            }

            if (namePlan.counts.existing > 0) {
                counts.push({ text: this.sayCount('paste_count_existing', namePlan.counts.existing) });
            }

            if (namePlan.counts.removed > 0) {
                counts.push({ text: this.sayCount('paste_count_removed', namePlan.counts.removed), tone: 'warning' });
            }

            if (namePlan.counts.duplicate > 0) {
                counts.push({ text: this.sayCount('paste_count_duplicate', namePlan.counts.duplicate) });
            }

            if (round !== null && placed > 0) {
                counts.push({ text: this.sayCount('paste_count_into', placed, { round: round.name }) });
            }
        }

        const notPossible = problems + refusedLines.size + (namePlan?.counts.invalid ?? 0);

        if (notPossible > 0) {
            counts.push({ text: this.texts.tc('people_paste_count_problems', notPossible), tone: 'danger' });
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

        if (change.op === 'field' && (change.field === 'externalId' || change.field === 'note')) {
            return { text: name, note: this.say(`paste_change_${change.field}`, { from: change.from ?? '', to: change.to ?? '' }) };
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

// ---------------------------------------------------------------- shared with the phone list and the person editor

/**
 * A client refusal in words: the server's reason text (`context.reasonText()`) with its %placeholders% filled from the
 * refused change - who, which round, which pair/team, the profile's other row, the limits.
 */
export function reasonFor(context, error) {
    // The core words it (sheet_changes.js refusalDetails(): the server's text, its cause variant and parameters)
    if (typeof context.errorText === 'function') {
        return context.errorText(error);
    }

    const { key, params } = refusalDetails(error, context.model);

    return context.reasonText(key, params);
}

/** A typed or pasted country: '' = none, a code ("cz", "CZ") or a name in the page's language; undefined = unknown. */
export function readCountry(countries, text) {
    const value = foldSearchText(trimCell(text));

    if (value === '') {
        return null;
    }

    if (value in countries) {
        return value;
    }

    for (const [code, label] of Object.entries(countries)) {
        if (foldSearchText(label) === value) {
            return code;
        }
    }

    return undefined;
}

/** The country typeahead's options (a name starting with what was typed first). */
export function countryOptions(context, query, person, t) {
    const folded = foldSearchText(query);
    const options = [];

    if (person?.country && folded === '') {
        options.push({ value: NO_COUNTRY, label: t('people_no_country') });
    }

    const entries = Object.entries(context.countries)
        .map(([code, label]) => ({ code, label: String(label), folded: foldSearchText(label) }))
        .filter((country) => folded === '' || country.code === folded || country.folded.includes(folded))
        .sort((a, b) => {
            const startA = a.code === folded || a.folded.startsWith(folded) ? 0 : 1;
            const startB = b.code === folded || b.folded.startsWith(folded) ? 0 : 1;

            return startA - startB || a.label.localeCompare(b.label, context.locale);
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

/**
 * The MSP player search (`urls.playerSearch` = player_search_autocomplete?format=co-puzzler): players as
 * `{id, name, code, country, countryLabel, linkedTo, player}` (`player` = what linkProfile() takes), null when it failed.
 */
export async function searchPlayers(context, model, text, personId, signal) {
    const url = new URL(context.urls.playerSearch, window.location.href);
    url.searchParams.set('query', text);
    let players;

    try {
        const response = await fetch(url.toString(), { headers: { Accept: 'application/json' }, credentials: 'same-origin', signal });
        players = response.ok ? await response.json() : [];
    } catch (e) {
        return null;
    }

    return (Array.isArray(players) ? players : []).map((player) => {
        const elsewhere = model.people().find((other) => other.id !== personId && other.player?.id === player.key);

        // A profile hidden from this organiser (they block it, it is private to them - O9) can be linked; the cell
        // then says "Linked to a MySpeedPuzzling profile" like the state does
        const hidden = player.hidden === true;

        return {
            id: player.key,
            name: player.label,
            code: player.code ?? null,
            country: player.country ?? null,
            countryLabel: player.country ? (context.countries[player.country] ?? '') : '',
            linkedTo: elsewhere?.name ?? null,
            hidden,
            player: hidden
                ? { id: player.key, visible: false, name: null, code: null, country: null, avatar: null, profileUrl: null }
                : { id: player.key, visible: true, name: player.label, code: player.code ?? null, country: player.country ?? null, avatar: player.avatar ?? null, profileUrl: null },
        };
    });
}

/** O1: `Corners · Table 2 · Kim Example, Pat Sample` - without "Table n" when it has none, "(no name)" when unnamed. */
export function teamLabelText(model, teamId, t, say) {
    const label = model.teamLabel(teamId);
    const parts = [label.name ?? t('team_no_name')];

    if (label.table !== null && label.table !== undefined) {
        parts.push(say('team_table', { table: label.table }));
    }

    if (label.members.length > 0) {
        parts.push(label.members.join(', '));
    }

    return parts.join(' · ');
}

/** A date (and time) of the state (ATOM, UTC) in the page's language and the browser's zone. */
export function formatDate(value, locale, withTime) {
    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return '';
    }

    try {
        return new Intl.DateTimeFormat(locale || undefined, withTime ? { dateStyle: 'medium', timeStyle: 'short' } : { dateStyle: 'medium' }).format(date);
    } catch (e) {
        return date.toISOString().slice(0, withTime ? 16 : 10).replace('T', ' ');
    }
}

/**
 * The registration counters and the first-in-line hint of a managed event, from the people already loaded:
 * "Spots taken 180 / 200 · Waitlist 12 · Paid 150 · Checked in 20" and "A spot is free - Robin Example is first on the
 * waitlist · Give a spot".
 */
export function registrationSummaryHtml(model, competition, say, sayCount) {
    const people = model.people();
    const counts = registrationCounts(people, competition.capacity ?? null);
    const items = [
        counts.capacity !== null
            ? say('counter_spots_capacity', { taken: counts.taken, capacity: counts.capacity })
            : say('counter_spots', { taken: counts.taken }),
        say('counter_waitlist', { count: counts.waitlisted }),
        say('counter_paid', { count: counts.paid }),
    ];

    if (competition.isOnline !== true) {
        items.push(say('counter_checked_in', { count: counts.checkedIn }));
    }

    const first = firstInLine(people, competition.capacity ?? null);
    let hint = '';

    if (first !== null) {
        const text = counts.capacity === null
            ? say('first_in_line_no_capacity', { name: first.name })
            : sayCount('first_in_line', counts.free, { name: first.name });
        // The button says what it does: the person gets an e-mail (E-3: a readable button, not white on light green)
        hint = `<div class="sheet-first-in-line" role="status"><i class="bi bi-arrow-up-circle" aria-hidden="true"></i> <span>${escapeHtml(text)} <small class="sheet-first-in-line-mail" id="sheet-first-in-line-mail">${escapeHtml(say('first_in_line_email'))}</small></span> <button type="button" class="btn btn-sm btn-outline-success sheet-btn-success" data-promote="${escapeHtml(first.id)}" aria-describedby="sheet-first-in-line-mail">${escapeHtml(say('first_in_line_action'))}</button></div>`;
    }

    return `<p class="sheet-counters${counts.over ? ' is-over' : ''}" tabindex="-1" data-counters>${items.map((item) => `<span>${escapeHtml(item)}</span>`).join('<span aria-hidden="true"> · </span>')}${counts.over ? ` <span class="sheet-counters-over"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i> ${escapeHtml(say('counter_over'))}</span>` : ''}</p>${hint}`;
}

/**
 * New markup for the counters and the first-in-line hint: a focus inside stays there - on the next first-in-line's
 * button when the organiser just gave a spot, else on the counters (never lost to the page). `onResize` when the height
 * changed (the grid fits itself again).
 */
export function replaceKeepingFocus(element, html, onResize = () => {}) {
    if (element.dataset.html === html) {
        return;
    }

    const hadFocus = element.contains(document.activeElement);
    const before = element.offsetHeight;
    element.innerHTML = html;
    element.dataset.html = html;

    if (hadFocus) {
        (element.querySelector('[data-promote]') ?? element.querySelector('[data-counters]'))?.focus({ preventScroll: true });
    }

    if (element.offsetHeight !== before) {
        onResize();
    }
}

/** The sort icon of a header: none, A→Z, Z→A. */
export function sortIcon(dir) {
    if (dir === 'asc') {
        return 'bi-sort-down-alt';
    }

    if (dir === 'desc') {
        return 'bi-sort-up-alt';
    }

    return 'bi-arrow-down-up';
}

/**
 * A preview whose dry run refused: its Confirm cannot be pressed while `isBlocked()` says so - also after the dialog
 * draws itself again (a message, a tick); the caller's `onConfirm` guard says why if it is tried anyway.
 */
export function holdConfirm(dialog, isBlocked) {
    if (!dialog?.dialog || typeof dialog.render !== 'function') {
        return;
    }

    const apply = () => {
        const button = dialog.dialog?.querySelector('[data-preview-confirm]');

        if (button && isBlocked()) {
            button.disabled = true;
        }
    };
    const render = dialog.render.bind(dialog);
    dialog.render = (...args) => {
        render(...args);
        apply();
    };
    apply();
}

/**
 * A confirmation that then runs the work inside it (the bulk Mark paid / Check in, BR13): the lines say what will happen
 * (how many e-mails go out), Confirm starts `run(control, onProgress)` - the dialog stays, shows "3 of 12 done…" and
 * Cancel becomes Stop (`control.stopped`); it closes with the run's result. Resolves null when cancelled before it
 * started.
 */
export function runInDialog({ host, title, lines, confirmLabel, cancelLabel, closeLabel, stopLabel, progress, run }) {
    return new Promise((resolve) => {
        const id = `sheet-run-${Math.random().toString(36).slice(2)}`;
        const dialog = document.createElement('dialog');
        dialog.className = 'sheet-preview sheet-confirm sheet-run';
        dialog.setAttribute('aria-labelledby', `${id}-title`);
        dialog.setAttribute('aria-describedby', `${id}-text`);
        dialog.innerHTML = `<form method="dialog" class="sheet-preview-form" novalidate>
                <div class="sheet-preview-head"><h2 class="h5 mb-0" id="${id}-title">${escapeHtml(title)}</h2><button type="button" class="btn-close" data-run-cancel aria-label="${escapeHtml(closeLabel)}"></button></div>
                <div class="sheet-preview-body" id="${id}-text">
                    ${lines.map((line) => `<p>${escapeHtml(line)}</p>`).join('')}
                    <p class="sheet-run-progress fw-semibold" data-run-progress role="status" aria-live="polite"></p>
                </div>
                <div class="sheet-preview-foot"><div class="d-flex gap-2 justify-content-end">
                    <button type="button" class="btn btn-outline-secondary" data-run-cancel>${escapeHtml(cancelLabel)}</button>
                    <button type="submit" class="btn btn-primary" data-run-ok>${escapeHtml(confirmLabel)}</button>
                </div></div>
            </form>`;
        const returnTo = document.activeElement;
        const control = { stopped: false };
        let running = false;
        let settled = false;
        const finish = (value) => {
            if (settled) {
                return;
            }

            settled = true;
            dialog.close();
            dialog.remove();

            if (returnTo?.isConnected && !returnTo.hidden) {
                returnTo.focus({ preventScroll: true });
            }

            resolve(value);
        };
        const cancel = () => {
            if (running) {
                control.stopped = true;
                dialog.querySelectorAll('[data-run-cancel]').forEach((button) => {
                    button.disabled = true;
                });
            } else {
                finish(null);
            }
        };
        const progressElement = dialog.querySelector('[data-run-progress]');
        dialog.querySelectorAll('[data-run-cancel]').forEach((button) => button.addEventListener('click', cancel));
        dialog.addEventListener('cancel', (event) => {
            event.preventDefault();
            cancel();
        });
        dialog.querySelector('form').addEventListener('submit', async (event) => {
            event.preventDefault();

            if (running) {
                return;
            }

            running = true;
            const ok = dialog.querySelector('[data-run-ok]');
            ok.disabled = true;
            const stop = dialog.querySelector('.sheet-preview-foot [data-run-cancel]');
            stop.textContent = stopLabel;
            stop.focus();
            let result;

            try {
                result = await run(control, (done, total) => {
                    progressElement.textContent = progress(done, total);
                });
            } finally {
                running = false;
            }

            finish(result);
        });
        (host.closest?.('[data-controller~="participants-sheet"]') ?? document.body).append(dialog);
        dialog.showModal();
        dialog.querySelector('[data-run-ok]').focus();
    });
}

/** A typed number as digits: full-width digits (a Japanese keyboard: "１２") and spaces read like "12". */
export function typedNumber(value) {
    return String(value ?? '').normalize('NFKC').replace(/\s+/gu, '');
}

/**
 * "Type the number to confirm" (§6 Deletes: removing more than 25 % of the people, at least 10) - a modal dialog that
 * resolves true only when the typed number matches.
 */
export function confirmTyped({ host, title, text, prompt, expected, confirmLabel, cancelLabel, closeLabel }) {
    return new Promise((resolve) => {
        const id = `sheet-confirm-${Math.random().toString(36).slice(2)}`;
        const dialog = document.createElement('dialog');
        dialog.className = 'sheet-preview sheet-confirm';
        dialog.setAttribute('aria-labelledby', `${id}-title`);
        dialog.setAttribute('aria-describedby', `${id}-text`);
        dialog.innerHTML = `<form method="dialog" class="sheet-preview-form" novalidate>
                <div class="sheet-preview-head"><h2 class="h5 mb-0" id="${id}-title">${escapeHtml(title)}</h2><button type="button" class="btn-close" data-confirm-cancel aria-label="${escapeHtml(closeLabel)}"></button></div>
                <div class="sheet-preview-body">
                    <p id="${id}-text">${escapeHtml(text)}</p>
                    <label class="form-label" for="${id}-input">${escapeHtml(prompt)}</label>
                    <input type="text" inputmode="numeric" class="form-control" id="${id}-input" autocomplete="off" data-confirm-input>
                </div>
                <div class="sheet-preview-foot"><div class="d-flex gap-2 justify-content-end">
                    <button type="button" class="btn btn-outline-secondary" data-confirm-cancel>${escapeHtml(cancelLabel)}</button>
                    <button type="submit" class="btn btn-danger" data-confirm-ok disabled>${escapeHtml(confirmLabel)}</button>
                </div></div>
            </form>`;
        const returnTo = document.activeElement;
        let settled = false;
        const finish = (value) => {
            if (settled) {
                return;
            }

            settled = true;
            dialog.close();
            dialog.remove();

            if (returnTo?.isConnected) {
                returnTo.focus({ preventScroll: true });
            }

            resolve(value);
        };
        const input = dialog.querySelector('[data-confirm-input]');
        const ok = dialog.querySelector('[data-confirm-ok]');
        input.addEventListener('input', () => {
            ok.disabled = typedNumber(input.value) !== expected;
        });
        dialog.querySelectorAll('[data-confirm-cancel]').forEach((button) => button.addEventListener('click', () => finish(false)));
        dialog.addEventListener('cancel', (event) => {
            event.preventDefault();
            finish(false);
        });
        dialog.querySelector('form').addEventListener('submit', (event) => {
            event.preventDefault();

            if (typedNumber(input.value) === expected) {
                finish(true);
            }
        });
        (host.closest?.('[data-controller~="participants-sheet"]') ?? document.body).append(dialog);
        dialog.showModal();
        input.focus();
    });
}

/** Round colours come from the server as #hex; anything else is not put into a style attribute. */
function safeColor(color) {
    return typeof color === 'string' && /^#[0-9a-f]{3,8}$/i.test(color) ? color : 'transparent';
}
