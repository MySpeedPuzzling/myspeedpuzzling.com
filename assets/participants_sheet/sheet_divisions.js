/**
 * Divisions on the round tabs of the participants sheet (shared-puzzles-and-divisions.md §3.3, §3.4): a round that uses
 * divisions carries `divisions` ([{id, name, shortName}], in the event's order) in the state, its entries `division`
 * (the id, null = none) - a solo entry on the person's place, a pair/team on the team. A round without divisions has
 * neither key, and no Division column.
 *
 * matchDivision() is the browser's twin of DivisionMatch (PHP): a typed value names a division by its id, name or short
 * label, folded like search text (foldSearchText - trimmed, whitespace collapsed, lower case, accents folded). Pure -
 * pinned by tests/participants-sheet-round/divisions.mjs.
 */

import { foldSearchText } from '../search_fold.js';

export const NO_DIVISION = '';

/** The divisions the round uses (none = a round without divisions). */
export function roundDivisions(model, roundId) {
    const divisions = model.round(roundId)?.divisions;

    return Array.isArray(divisions) ? divisions : [];
}

export function usesDivisions(model, roundId) {
    return roundDivisions(model, roundId).length > 0;
}

/** Every division any round of the event uses - what the page knows of the event's list. */
export function knownDivisions(model) {
    const known = new Map();

    for (const round of model.rounds()) {
        for (const division of Array.isArray(round.divisions) ? round.divisions : []) {
            known.set(division.id, division);
        }
    }

    return [...known.values()];
}

export function divisionKey(value) {
    return foldSearchText(String(value ?? ''));
}

function names(division, wanted) {
    return String(division.id).toLowerCase() === wanted
        || divisionKey(division.name) === wanted
        || (division.shortName !== null && division.shortName !== undefined && divisionKey(division.shortName) === wanted);
}

/**
 * What a typed value means for an entry of a round: `{none: true}` (empty - no division), `{division}` (one the round
 * uses) or `{reason}` - `not_in_round` (another division of the event) / `unknown_division` (the server's codes).
 */
export function matchDivision(value, usable, ofEvent = []) {
    const wanted = divisionKey(value);

    if (wanted === '') {
        return { none: true };
    }

    const division = usable.find((candidate) => names(candidate, wanted)) ?? null;

    if (division !== null) {
        return { division };
    }

    const other = ofEvent.find((candidate) => names(candidate, wanted)) ?? null;

    return { reason: other !== null ? 'not_in_round' : 'unknown_division', other };
}

/** The division an entry has, as the state says it (null for none or one the round does not use any more). */
export function divisionOf(model, roundId, divisionId) {
    if (divisionId === null || divisionId === undefined || divisionId === NO_DIVISION) {
        return null;
    }

    return roundDivisions(model, roundId).find((division) => division.id === divisionId) ?? null;
}

/** The division id of an entry as a change's `from` / `to` wants it: '' for none. */
export function divisionValue(divisionId) {
    return divisionId === null || divisionId === undefined ? NO_DIVISION : String(divisionId);
}

/**
 * The editor's list: the round's divisions (filtered by what is typed) and "No division" - exact = the one the typed
 * text names, so Enter takes it.
 */
export function divisionOptions(model, roundId, query, noneLabel) {
    const wanted = divisionKey(query);
    const options = roundDivisions(model, roundId)
        .filter((division) => wanted === '' || divisionKey(division.name).includes(wanted) || (division.shortName && divisionKey(division.shortName).includes(wanted)))
        .map((division) => ({
            label: division.name,
            detail: division.shortName ?? '',
            value: division.id,
            exact: wanted !== '' && names(division, wanted),
        }));

    if (wanted === '') {
        options.push({ label: noneLabel, value: NO_DIVISION, exact: false, className: 'sheet-option-none' });
    }

    return options;
}

/** The entry a division column's row stands for: a solo entry by participant, a pair/team by team. */
export function divisionTarget(roundCategory, row) {
    return roundCategory === 'solo' ? { participant: row } : { team: row };
}

/** The marker key of an entry's division (sheet_changes.js changeTarget()). */
export function divisionMarkerKey(roundId, target) {
    return target.team ? `division:team:${target.team}:${roundId}` : `division:participant:${target.participant}:${roundId}`;
}

/** How many entries of the round have no division - the toolbar's "N without a division". */
export function withoutDivision(model, roundId) {
    if (!usesDivisions(model, roundId)) {
        return 0;
    }

    if (model.round(roundId)?.category === 'solo') {
        return model.peopleIn(roundId).filter((person) => divisionOf(model, roundId, model.place(person.id, roundId)?.division) === null).length;
    }

    return model.teamsOf(roundId).filter((team) => divisionOf(model, roundId, team.division) === null).length;
}
