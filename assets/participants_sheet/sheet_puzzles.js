/**
 * `pick` rounds in the participants spreadsheet (competition toolkit A4): a result typed or pasted for an entry that has
 * a per-puzzle record never dead-ends with `puzzles_mismatch` - the `puzzles` change that keeps the record consistent
 * goes with it, in the same request (a group - the server applies both or neither, A rule 7), and in the same undo
 * step. The record itself is recomputed by consistentRecord() of official_results_desk_puzzles.js (the judge page's own
 * rule: the chosen puzzles are kept); when no record fits the new result it is cleared and the organiser is told.
 * Per-puzzle times themselves are edited on the results desk. Every other round: nothing changes.
 *
 * Pure - tests/participants-sheet-round/puzzles.mjs.
 */

import { consistentRecord, pickFormat, recordText } from '../official_results_desk_puzzles.js';

/** The round's `puzzleFormat` when it is a `pick` round, else null. */
export function roundPickFormat(model, roundId) {
    return pickFormat(model.round(roundId));
}

/** The record the server holds for an entry (`team:<id>` / `participant_round:<placeId>`), null = none. */
export function serverRecord(model, ref) {
    if (typeof ref !== 'string') {
        return null;
    }

    if (ref.startsWith('team:')) {
        return model.team(ref.slice(5))?.puzzles ?? null;
    }

    if (ref.startsWith('participant_round:')) {
        return model.placeById(ref.slice('participant_round:'.length))?.puzzles ?? null;
    }

    return null;
}

/** The record as the page shows it - the organiser's unsaved value, else the server's. */
export function shownRecord(model, ref, pending) {
    const server = serverRecord(model, ref);

    return pending ? pending.value(ref, 'puzzles', server) : server;
}

/**
 * The "Puzzles" cell of a `pick` round entry: the record's labels in solving order ("B ✓ 0:41:10 · A 312 pcs").
 */
export function recordCellText(model, roundId, ref, pending, texts) {
    const format = roundPickFormat(model, roundId);

    return format === null || ref === null ? '' : recordText(format, shownRecord(model, ref, pending), texts);
}

/**
 * An action (sheet_changes.js) with the `puzzles` changes its `result` changes need on `pick` rounds: each such pair
 * shares a `group` (sent together), and the step's undo brings both back together. A result change that already comes
 * with a `puzzles` change of its entry (an undo, a redo) is left as it is.
 *
 * @param {object} action
 * @param {object} model SheetModel
 * @param {function(string): object} pendingOf the round's PendingChanges (queue.results(roundId))
 * @returns {{action: object, cleared: string[]}} `cleared` = refs whose record is cleared because no record fit
 */
export function withPickRecords(action, model, pendingOf) {
    const changes = action?.results ?? [];

    if (changes.length === 0 || !changes.some((change) => change.field === 'result' && roundPickFormat(model, change.roundId) !== null)) {
        return { action, cleared: [] };
    }

    const withRecord = new Set(changes.filter((change) => change.field === 'puzzles').map((change) => change.ref));
    const results = [];
    const inverse = [];
    const cleared = [];

    for (const change of changes) {
        const format = change.field === 'result' ? roundPickFormat(model, change.roundId) : null;

        if (format === null || withRecord.has(change.ref)) {
            results.push(change);

            continue;
        }

        const shown = shownRecord(model, change.ref, pendingOf(change.roundId));
        const next = consistentRecord(format, change.to ?? null, shown);

        if (!next.changed) {
            results.push(change);

            continue;
        }

        const group = `puzzles:${change.ref}`;
        const puzzles = { roundId: change.roundId, ref: change.ref, field: 'puzzles', from: shown ?? null, to: next.record, group };
        results.push({ ...change, group }, puzzles);
        inverse.push({ ...change, from: change.to, to: change.from, group }, { ...puzzles, from: next.record, to: shown ?? null });

        if (next.cleared) {
            cleared.push(change.ref);
        }
    }

    if (inverse.length === 0) {
        return { action, cleared };
    }

    // The undo of a grouped result change is the group's undo: both back together
    const inverseResults = (action.inverseResults ?? []).flatMap((change) => {
        const own = change.field === 'result' ? inverse.filter((candidate) => candidate.ref === change.ref) : [];

        return own.length > 0 ? own : [change];
    });

    return { action: { ...action, results, inverseResults }, cleared };
}
