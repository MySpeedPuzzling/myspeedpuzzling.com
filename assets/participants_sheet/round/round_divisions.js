/**
 * The Division column of a round tab (shared-puzzles-and-divisions.md §3.4) - shared by the solo and the pair/team tab
 * on a desktop and the phone list: a column only for a round that uses divisions, a list editor over the round's
 * divisions + "No division", the toolbar's "N without a division" and "Fill empty with …" (one group per entry, so one
 * refused entry does not hold back the others). Every change is sheet_changes.js setDivision(s) through `context.act()`,
 * `from` = what the cell showed when its editor opened.
 */

import { escapeHtml } from '../sheet_grid.js';
import { emptyDivisionTargets, setDivision, setDivisions } from '../sheet_changes.js';
import {
    NO_DIVISION,
    divisionMarkerKey,
    divisionOf,
    divisionOptions,
    divisionTarget,
    divisionValue,
    knownDivisions,
    matchDivision,
    roundDivisions,
    usesDivisions,
    withoutDivision,
} from '../sheet_divisions.js';

export const DIVISION_COLUMN = 'division';

/** The column, or null for a round without divisions (its tab stays exactly as it was). */
export function divisionColumn(model, roundId, texts) {
    if (!usesDivisions(model, roundId)) {
        return null;
    }

    return { key: DIVISION_COLUMN, label: texts.t('col_division'), kind: 'list', width: 160, autoHighlight: 'exact', className: 'sheet-col-division' };
}

/** What the columns depend on: the round's divisions (a new list = a new grid). */
export function divisionsKey(model, roundId) {
    return roundDivisions(model, roundId).map((division) => `${division.id}:${division.name}`).join(',');
}

/** The division id an entry has now ('' = none) - the `from` of an edit that opens. */
export function shownDivision(model, roundId, row) {
    const category = model.round(roundId)?.category;

    return divisionValue(category === 'solo' ? model.place(row, roundId)?.division : model.team(row)?.division);
}

export function divisionCell(context, model, roundId, row, texts) {
    const target = divisionTarget(model.round(roundId)?.category, row);
    const division = divisionOf(model, roundId, shownDivision(model, roundId, row));
    const marker = context.markerFor(divisionMarkerKey(roundId, target));

    if (division === null) {
        return { text: '', html: `<span class="sheet-muted">${escapeHtml(texts.t('division_none'))}</span>`, copy: '', marker, className: 'sheet-col-division' };
    }

    return { text: division.name, copy: division.name, marker, className: 'sheet-col-division' };
}

export function divisionEditValue(model, roundId, row) {
    return divisionOf(model, roundId, shownDivision(model, roundId, row))?.name ?? '';
}

export function divisionSuggest(model, roundId, query, texts) {
    return { options: divisionOptions(model, roundId, query, texts.t('division_none')), hint: '' };
}

/**
 * The editor's commit: the picked option, else what was typed (DivisionMatch's rule) - an action, or `{error}` for a
 * value that names no division of the round, or null for no change.
 */
export function divisionCommit(model, roundId, row, input, seen, texts, options = {}) {
    let to = null;

    if (input.option && typeof input.option.value === 'string') {
        to = input.option.value;
    } else {
        const match = matchDivision(input.text, roundDivisions(model, roundId), knownDivisions(model));

        if (match.reason) {
            const known = roundDivisions(model, roundId).map((division) => division.name).join(', ');

            return {
                error: texts.t(match.reason === 'not_in_round' ? 'division_not_in_round' : 'division_unknown', {
                    value: match.other?.name ?? input.text.trim(),
                    round: model.round(roundId)?.name ?? '',
                    divisions: known,
                }),
            };
        }

        to = match.none ? NO_DIVISION : match.division.id;
    }

    const from = seen === undefined || seen === null ? shownDivision(model, roundId, row) : seen;

    if (to === from) {
        return null;
    }

    return {
        action: setDivision(model, roundId, divisionTarget(model.round(roundId)?.category, row), to, { ...options, from }),
    };
}

/** Cleared division cells (Delete on a selection): one group per entry. */
export function clearDivisionsAction(model, roundId, rows, options = {}) {
    const category = model.round(roundId)?.category;
    const targets = rows.filter((row) => shownDivision(model, roundId, row) !== NO_DIVISION).map((row) => divisionTarget(category, row));

    return targets.length > 0 ? setDivisions(model, roundId, targets, NO_DIVISION, options) : null;
}

/** The toolbar's part: "3 without a division" and "Fill empty with …" - nothing for a round without divisions. */
export function divisionToolbarHtml(model, roundId, texts) {
    if (!usesDivisions(model, roundId)) {
        return '';
    }

    const without = withoutDivision(model, roundId);

    return `<span class="sheet-round-divisions small">${escapeHtml(without > 0 ? texts.tc('division_without', without) : texts.t('division_all_set'))}</span>`
        + (without > 0 ? ` <button type="button" class="btn btn-sm btn-outline-secondary" data-fill-divisions aria-haspopup="dialog"><i class="bi bi-tags" aria-hidden="true"></i> ${escapeHtml(texts.t('division_fill_empty'))}</button>` : '');
}

/** The items of a division picker (the phone's and "Fill empty with …"). */
export function divisionItems(model, roundId, texts, { none = true, current = null } = {}) {
    const items = roundDivisions(model, roundId).map((division) => ({
        value: division.id,
        label: division.name,
        detail: division.id === current ? texts.t('division_current') : (division.shortName ?? ''),
        icon: 'bi-tag',
    }));

    if (none) {
        items.push({ value: NO_DIVISION, label: texts.t('division_none'), icon: 'bi-x-circle', detail: current === NO_DIVISION ? texts.t('division_current') : '' });
    }

    return items;
}

/** "Fill empty with …": the entries without a division of the round get the picked one. */
export function fillEmptyAction(model, roundId, divisionId, options = {}) {
    return setDivisions(model, roundId, emptyDivisionTargets(model, roundId), divisionId, { label: { key: 'divisions' }, ...options });
}
