// Analytics Studio (docs/features/competitions-management/analytics-studio.md §7 + §0a) - the page's data model, pure:
// - merges the `state` answer (§3.1, who is in the round, the slots, results) with the `analytics` answer (§3.2) into
//   one row per entry for the active puzzle slot;
// - search (diacritics folded like SearchText::fold() - search_fold.js; names, team names, country codes, table
//   numbers), filter chips, sort - a sort or filter needing a puzzle only once that slot is visible (§5);
// - R3: no prediction column or sort in the list - predictions come for the selected entrant and the compare set only;
// - R8: unpublished confirmed results are never a sort key and never ordered; a published round sorts by official rank;
// - the compare set (at most 4 - phones show 2, the layout decides) and its best value per row;
// - the view reducer for the keymap (keys.js turns key presses into the actions applied here).
// Pinned by tests/analytics-studio-harness.mjs.

import { foldSearchText } from '../search_fold.js';

export const SORTS = ['name', 'table', 'solves', 'best', 'rating', 'rank'];
export const DEFAULT_SORT = 'name';
export const FILTERS = ['all', 'solved', 'never', 'nohistory'];
export const MAX_COMPARE = 4;
export const SKILL_TIERS = ['enthusiast', 'apprentice', 'proficient', 'advanced', 'expert', 'master', 'legend'];
export const DIFFICULTY_TIERS = ['very_easy', 'easy', 'average', 'challenging', 'hard', 'very_hard'];

const PUZZLE_SORTS = ['solves', 'best'];
const PUZZLE_FILTERS = ['solved', 'never'];

function isNumber(value) {
    return typeof value === 'number' && Number.isFinite(value);
}

function isObject(value) {
    return value !== null && typeof value === 'object' && !Array.isArray(value);
}

function own(object, key) {
    return isObject(object) && typeof key === 'string' && Object.prototype.hasOwnProperty.call(object, key) ? object[key] : undefined;
}

// ---- slots

/**
 * The round's slots in order: `{label, key, revealsAtMs, puzzle, visible}` - a hidden slot has `key` and `puzzle` null
 * and carries nothing but its label and the reveal moment (§5).
 */
export function slotsOf(state) {
    if (!Array.isArray(state?.slots)) {
        return [];
    }

    return state.slots
        .filter((slot) => isObject(slot) && typeof slot.label === 'string')
        .map((slot) => {
            const puzzle = isObject(slot.puzzle) && typeof slot.puzzle.key === 'string' ? slot.puzzle : null;

            return {
                label: slot.label,
                key: puzzle === null ? null : puzzle.key,
                revealsAtMs: isNumber(slot.revealsAtMs) ? slot.revealsAtMs : null,
                puzzle,
                visible: puzzle !== null,
            };
        });
}

/**
 * The slot shown: the one with `label`, else the first visible one, else the first one, else null.
 */
export function activeSlot(state, label = null) {
    const slots = slotsOf(state);

    return slots.find((slot) => slot.label === label) ?? slots.find((slot) => slot.visible) ?? slots[0] ?? null;
}

/**
 * The label `step` slots away from `label` (wrapping) - `[` / `]`.
 */
export function stepSlot(state, label, step) {
    const slots = slotsOf(state);

    if (slots.length === 0) {
        return null;
    }

    const index = Math.max(0, slots.findIndex((slot) => slot.label === label));

    return slots[(index + step + slots.length * 8) % slots.length].label;
}

// ---- rows

/**
 * The state's result for an entry - official (published round: rank, qualified) or live (confirmed by the judge, never a
 * rank, R8) - else null.
 */
export function resultOf(state, key) {
    if (!Array.isArray(state?.results)) {
        return null;
    }

    const result = state.results.find((candidate) => isObject(candidate) && candidate.key === key);

    if (result === undefined || (result.state !== 'official' && result.state !== 'live')) {
        return null;
    }

    if (result.state === 'live') {
        return { ...result, rank: null, qualified: false };
    }

    return result;
}

function memberStats(analytics, key, index) {
    const members = own(own(analytics?.entrants, key), 'members');
    const stats = Array.isArray(members) ? members[index] : undefined;

    return isObject(stats) && Object.keys(stats).length > 0 ? stats : null;
}

function memberNames(members) {
    return members.map((member) => member.name).join(' / ');
}

/**
 * One row per entry for the slot with key `slotKey` (null = hidden slot or none: no puzzle numbers at all).
 */
export function entrantRows(state, analytics, slotKey = null) {
    if (!Array.isArray(state?.entrants)) {
        return [];
    }

    const loaded = isObject(analytics) && isObject(analytics.entrants);

    return state.entrants.filter((entrant) => isObject(entrant) && typeof entrant.key === 'string').map((entrant) => {
        const members = (Array.isArray(entrant.members) ? entrant.members : []).filter(isObject).map((member, index) => ({
            name: typeof member.name === 'string' ? member.name : '',
            country: typeof member.country === 'string' ? member.country : null,
            profile: typeof member.profile === 'string' ? member.profile : null,
            analysable: member.analysable === true,
            stats: member.analysable === true ? memberStats(analytics, entrant.key, index) : null,
        }));
        const isTeam = entrant.isTeam === true;
        const teamName = typeof entrant.teamName === 'string' && entrant.teamName !== '' ? entrant.teamName : null;
        const lineup = isTeam && isObject(own(own(analytics?.entrants, entrant.key), 'lineup')) ? analytics.entrants[entrant.key].lineup : null;
        const hasHistory = members.some((member) => member.analysable);
        const perPuzzle = (member) => (slotKey === null ? null : own(member.stats?.perPuzzle, slotKey) ?? null);

        let solves = null;
        let bestSeconds = null;
        let membersSolved = null;

        if (slotKey !== null && loaded) {
            membersSolved = members.filter((member) => (perPuzzle(member)?.solves ?? 0) > 0).length;

            if (isTeam) {
                const history = lineup === null ? null : (lineup.perPuzzle?.[slotKey] ?? null);
                solves = lineup === null ? null : (history?.solves ?? 0);
                bestSeconds = isNumber(history?.bestSeconds) ? history.bestSeconds : null;
            } else if (members[0]?.analysable && members[0].stats !== null) {
                const history = perPuzzle(members[0]);
                solves = history?.solves ?? 0;
                bestSeconds = isNumber(history?.bestSeconds) ? history.bestSeconds : null;
            }
        }

        const ratings = members.map((member) => member.stats?.rating).filter(isNumber);
        const name = isTeam && teamName !== null ? teamName : memberNames(members);
        const result = resultOf(state, entrant.key);

        return {
            key: entrant.key,
            isTeam,
            teamName,
            name,
            subtitle: isTeam ? memberNames(members) : '',
            table: isNumber(entrant.table) ? entrant.table : null,
            members,
            lineup,
            hasHistory,
            analyticsLoaded: loaded,
            solves,
            solved: (solves ?? 0) > 0 || (membersSolved ?? 0) > 0,
            membersSolved,
            bestSeconds,
            rating: ratings.length === 0 ? null : Math.max(...ratings),
            rankingOptedOut: !isTeam && members[0]?.stats?.rankingOptedOut === true,
            result,
            searchText: ` ${foldSearchText([name, teamName ?? '', ...members.map((member) => member.name)].join(' '))} ${members
                .map((member) => (member.country ?? '').toLowerCase()).join(' ')} `,
        };
    });
}

// ---- search, filter, sort

/**
 * Whether a row holds every typed word: names and team name folded, a country code, a table number ("12" or "#12" -
 * exactly that table, or a name holding the digits).
 */
export function matchesQuery(row, query) {
    const folded = foldSearchText(query);

    if (folded === '') {
        return true;
    }

    return folded.split(' ').every((token) => {
        const number = /^#?(\d+)$/.exec(token);

        if (number !== null && row.table !== null && String(row.table) === String(Number(number[1]))) {
            return true;
        }

        return row.searchText.includes(token.replace(/^#/, ''));
    });
}

/**
 * The sorts that make sense now: the puzzle ones only for a visible slot (§5), by official rank only once published (R8).
 */
export function availableSorts(state, slot) {
    return SORTS.filter((sort) => {
        if (PUZZLE_SORTS.includes(sort)) {
            return slot?.visible === true;
        }

        if (sort === 'rank') {
            return state?.round?.published === true;
        }

        return true;
    });
}

export function availableFilters(slot) {
    return FILTERS.filter((filter) => !PUZZLE_FILTERS.includes(filter) || slot?.visible === true);
}

function compareNullsLast(a, b, direction) {
    if (a === null && b === null) {
        return 0;
    }

    if (a === null) {
        return 1;
    }

    if (b === null) {
        return -1;
    }

    return direction * (a - b);
}

function byName(a, b) {
    return foldSearchText(a.name).localeCompare(foldSearchText(b.name)) || (a.key < b.key ? -1 : (a.key > b.key ? 1 : 0));
}

const COMPARATORS = {
    name: () => 0,
    table: (a, b) => compareNullsLast(a.table, b.table, 1),
    solves: (a, b) => compareNullsLast(a.solves, b.solves, -1),
    best: (a, b) => compareNullsLast(a.bestSeconds, b.bestSeconds, 1),
    rating: (a, b) => compareNullsLast(a.rating, b.rating, -1),
    // Official ranks only: a live result has none (resultOf), so an unpublished round can never be ordered by them
    rank: (a, b) => compareNullsLast(a.result?.state === 'official' ? (a.result.rank ?? null) : null, b.result?.state === 'official' ? (b.result.rank ?? null) : null, 1),
};

function passesFilter(row, filter) {
    switch (filter) {
        case 'solved':
            return row.solved;
        case 'never':
            return row.hasHistory && row.analyticsLoaded && !row.solved;
        case 'nohistory':
            return !row.hasHistory;
        default:
            return true;
    }
}

/**
 * The list as shown: filtered, searched, sorted (an unavailable sort/filter falls back to name / all).
 */
export function visibleRows(rows, { query = '', sort = DEFAULT_SORT, filter = 'all', state = null, slot = null } = {}) {
    const sorts = availableSorts(state, slot);
    const filters = availableFilters(slot);
    const activeSort = sorts.includes(sort) ? sort : DEFAULT_SORT;
    const activeFilter = filters.includes(filter) ? filter : 'all';
    const comparator = COMPARATORS[activeSort];

    return rows
        .filter((row) => passesFilter(row, activeFilter) && matchesQuery(row, query))
        .sort((a, b) => comparator(a, b) || byName(a, b));
}

/**
 * How many entries have a result right now (the top bar's "Live: 23 confirmed" / "23 official").
 */
export function resultsCount(state) {
    return Array.isArray(state?.results) ? state.results.filter((result) => result?.state === 'live' || result?.state === 'official').length : 0;
}

/**
 * The official ranking of a published round (R8: only then is there an ordered list) - rows with an official result,
 * by rank. Empty while not published.
 */
export function officialRanking(state, rows) {
    if (state?.round?.published !== true) {
        return [];
    }

    return rows
        .filter((row) => row.result?.state === 'official')
        .sort((a, b) => compareNullsLast(a.result.rank ?? null, b.result.rank ?? null, 1) || compareNullsLast(a.result.seconds ?? null, b.result.seconds ?? null, 1) || byName(a, b));
}

// ---- compare

/**
 * Adds `key` to the compare set or removes it; a fifth entry is refused (`refused: true`, set unchanged).
 */
export function toggleCompare(compare, key) {
    const list = Array.isArray(compare) ? compare.slice() : [];

    if (typeof key !== 'string' || key === '') {
        return { compare: list, refused: false };
    }

    const index = list.indexOf(key);

    if (index >= 0) {
        list.splice(index, 1);

        return { compare: list, refused: false };
    }

    if (list.length >= MAX_COMPARE) {
        return { compare: list, refused: true };
    }

    list.push(key);

    return { compare: list, refused: false };
}

/**
 * A member's prediction for a slot from whatever answered (the entrant answer, else the predictions answer):
 * `{status: 'value', value}` | `{status: 'optedOut'}` | `{status: 'none'}` (no model, private/unlinked) |
 * `{status: 'pending'}` (not loaded yet).
 */
export function predictionOf({ entrant = null, predictions = null } = {}, key, memberIndex, slotKey) {
    let member;

    if (isObject(entrant) && entrant.key === key && Array.isArray(entrant.members)) {
        member = entrant.members[memberIndex];
    } else {
        const members = own(own(predictions?.entrants, key), 'members');
        member = Array.isArray(members) ? members[memberIndex] : undefined;
    }

    if (member === undefined) {
        return { status: 'pending' };
    }

    if (!isObject(member) || Object.keys(member).length === 0) {
        return { status: 'none' };
    }

    if (member.predictionsOptedOut === true) {
        return { status: 'optedOut' };
    }

    const value = slotKey === null ? undefined : own(member.prediction, slotKey);

    return isObject(value) && isNumber(value.seconds) ? { status: 'value', value } : { status: 'none' };
}

function skillRank(tier) {
    const index = SKILL_TIERS.indexOf(tier);

    return index < 0 ? null : index + 1;
}

export const COMPARE_METRICS = [
    { id: 'solves', kind: 'count', better: 'higher' },
    { id: 'best', kind: 'duration', better: 'lower' },
    { id: 'first', kind: 'duration', better: 'lower' },
    { id: 'last', kind: 'duration', better: 'lower' },
    { id: 'first_try', kind: 'duration', better: 'lower' },
    { id: 'prediction', kind: 'duration', better: 'lower' },
    { id: 'brand_median', kind: 'duration', better: 'lower' },
    { id: 'size_median', kind: 'duration', better: 'lower' },
    { id: 'rating', kind: 'count', better: 'higher' },
    { id: 'skill', kind: 'skill', better: 'higher' },
    // A live result is never compared (R8) - only an official rank has a best
    { id: 'result', kind: 'result', better: 'rank' },
];

function metricValue(metric, row, slotKey, sources) {
    const solo = row.isTeam ? null : row.members[0];
    const history = slotKey === null ? null : (row.isTeam ? (row.lineup?.perPuzzle?.[slotKey] ?? null) : (own(solo?.stats?.perPuzzle, slotKey) ?? null));
    const similar = slotKey === null || solo === null ? null : (own(solo.stats?.similar, slotKey) ?? null);
    const pick = (value) => (isNumber(value) ? value : null);

    switch (metric.id) {
        case 'solves':
            return slotKey === null || !row.analyticsLoaded || (!row.isTeam && solo?.stats === null) || (row.isTeam && row.lineup === null) ? null : (history?.solves ?? 0);
        case 'best':
            return pick(history?.bestSeconds);
        case 'first':
            return pick(history?.firstSeconds);
        case 'last':
            return pick(history?.lastSeconds);
        case 'first_try':
            return pick(history?.firstTrySeconds);
        case 'prediction': {
            // No pair/team prediction (D12)
            if (row.isTeam || slotKey === null) {
                return null;
            }

            const prediction = predictionOf(sources, row.key, 0, slotKey);

            return prediction.status === 'value' ? prediction.value.seconds : null;
        }
        case 'brand_median':
            return pick(similar?.brandMedian);
        case 'size_median':
            return pick(similar?.sizeMedian);
        case 'rating':
            return row.rating;
        case 'skill': {
            const skill = solo?.stats?.skill;

            return isObject(skill) ? (own(skill, slotKey ?? '500') ?? own(skill, '500') ?? null) : null;
        }
        case 'result':
            return row.result;
        default:
            return null;
    }
}

function bestIndexes(metric, values) {
    let numbers;

    if (metric.better === 'rank') {
        numbers = values.map((value) => (value?.state === 'official' && isNumber(value.rank) ? value.rank : null));
    } else if (metric.kind === 'skill') {
        numbers = values.map(skillRank);
    } else {
        numbers = values.map((value) => (isNumber(value) ? value : null));
    }

    const known = numbers.filter((value) => value !== null);

    if (known.length < 2) {
        return [];
    }

    const best = metric.better === 'higher' || metric.kind === 'skill' ? Math.max(...known) : Math.min(...known);

    if (known.every((value) => value === best)) {
        return [];
    }

    return numbers.flatMap((value, index) => (value === best ? [index] : []));
}

/**
 * The compare table: one line per metric with each compared entry's value and the indexes of the best ones (bold).
 * No best when fewer than two are known or all are equal.
 *
 * @returns {Array<{id: string, kind: string, values: Array<*>, best: number[]}>}
 */
export function compareTable(rows, slotKey, sources = {}) {
    return COMPARE_METRICS.map((metric) => {
        const values = rows.map((row) => metricValue(metric, row, slotKey, sources));

        return { id: metric.id, kind: metric.kind, values, best: bestIndexes(metric, values) };
    });
}

// ---- the view

export function initialView(decoded = {}) {
    return {
        puzzle: decoded.puzzle ?? null,
        selected: decoded.e ?? null,
        open: decoded.e !== null && decoded.e !== undefined,
        compare: [],
        query: decoded.q ?? '',
        sort: decoded.sort ?? DEFAULT_SORT,
        filter: 'all',
        tab: 'entrants',
        help: false,
        notice: null,
    };
}

function moveSelection(keys, selected, delta) {
    if (keys.length === 0) {
        return selected;
    }

    const index = keys.indexOf(selected);

    if (index < 0) {
        return delta > 0 ? keys[0] : keys[keys.length - 1];
    }

    return keys[Math.max(0, Math.min(keys.length - 1, index + delta))];
}

/**
 * Applies an action (keys.js, or a click the page adapter maps to the same names) to the view.
 * `context.keys` = the entry keys of the list as shown, in order; `context.state` = the round state.
 *
 * @returns {{view: object, effect: null|'focusSearch'|'blurSearch'}}
 */
export function applyAction(view, action, { keys = [], state = null } = {}) {
    const next = { ...view, notice: null };

    switch (action?.type) {
        case 'focusSearch':
            return { view: next, effect: 'focusSearch' };
        case 'blurSearch':
            return { view: next, effect: 'blurSearch' };
        case 'search':
            return { view: { ...next, query: String(action.query ?? '') }, effect: null };
        case 'clearSearch':
            return { view: { ...next, query: '' }, effect: null };
        case 'move':
            return { view: { ...next, selected: moveSelection(keys, view.selected, action.delta) }, effect: null };
        case 'select':
            return { view: { ...next, selected: action.key ?? null, open: action.key !== null && action.key !== undefined }, effect: null };
        case 'open':
            return { view: { ...next, open: view.selected !== null }, effect: null };
        case 'toggleCompare': {
            const key = action.key ?? view.selected;
            const { compare, refused } = toggleCompare(view.compare, key);

            return { view: { ...next, compare, notice: refused ? 'compare_full' : null }, effect: null };
        }
        case 'clearCompare':
            return { view: { ...next, compare: [] }, effect: null };
        case 'slot':
            return { view: { ...next, puzzle: action.label ?? null }, effect: null };
        case 'stepSlot':
            return { view: { ...next, puzzle: stepSlot(state, activeSlot(state, view.puzzle)?.label ?? null, action.step) }, effect: null };
        case 'sort':
            return { view: { ...next, sort: SORTS.includes(action.sort) ? action.sort : DEFAULT_SORT }, effect: null };
        case 'filter':
            return { view: { ...next, filter: FILTERS.includes(action.filter) ? action.filter : 'all' }, effect: null };
        case 'tab':
            return { view: { ...next, tab: ['entrants', 'puzzle', 'live'].includes(action.tab) ? action.tab : 'entrants' }, effect: null };
        case 'toggleHelp':
            return { view: { ...next, help: !view.help }, effect: null };
        case 'closeHelp':
            return { view: { ...next, help: false }, effect: null };
        case 'escape':
            if (view.help) {
                return { view: { ...next, help: false }, effect: null };
            }

            return { view: { ...next, open: false }, effect: null };
        default:
            return { view, effect: null };
    }
}
