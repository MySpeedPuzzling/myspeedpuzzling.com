/**
 * `pick` rounds in the participants spreadsheet (competition toolkit A4): a result typed or pasted for an entry that has
 * a per-puzzle record never dead-ends with `puzzles_mismatch` - the `puzzles` change that keeps the record consistent
 * goes with it, in the same request (a group - the server applies both or neither, A rule 7), and in the same undo
 * step. The record itself is recomputed by consistentRecord() of official_results_desk_puzzles.js (the judge page's own
 * rule: the chosen puzzles are kept); when no record fits the new result it is cleared and the organiser is told.
 * Per-puzzle times themselves are edited on the results desk. Every other round: nothing changes.
 *
 * The "Puzzles" cell opens a read-only popover of the record (competition toolkit v2 C5 - sheetPuzzlesPopover()): the
 * puzzles in solving order with their splits, own times or pieces, and a link to the results desk with that entry's
 * per-puzzle editor open (`?entry=<ref>`). Editing stays on the desk (Decision D5 - the one per-puzzle editor).
 *
 * Pure - tests/participants-sheet-round/puzzles.mjs.
 */

import { consistentRecord, deskEntryUrl, pickFormat, recordChips, recordText } from '../official_results_desk_puzzles.js';
import { formatResultTime } from '../official_results_time.js';
import { escapeHtml } from './sheet_grid.js';

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
 * The popover of a `pick` round entry's "Puzzles" cell: the record shown (unsaved values too) in solving order - label,
 * name only of a puzzle that is out (a secret puzzle by its label alone, as before its reveal), finished + the time it
 * was finished at (cumulative) + its own time, or the pieces placed of one not finished - and the results desk's URL
 * with that entry's editor open. Null = not a `pick` round entry.
 *
 * @returns {{items: {id: string, label: string, name: string|null, done: boolean, atSeconds: number|null,
 *            duration: number|null, piecesPlaced: number|null, piecesCount: number|null}[], deskUrl: string|null}|null}
 */
export function sheetPuzzlesPopover(model, roundId, ref, pending) {
    const format = roundPickFormat(model, roundId);

    if (format === null || typeof ref !== 'string' || ref === '') {
        return null;
    }

    const counts = new Map(format.puzzles.map((puzzle) => [String(puzzle.roundPuzzleId ?? '').toLowerCase(), Number.isInteger(puzzle.piecesCount) ? puzzle.piecesCount : null]));
    const items = recordChips(format, shownRecord(model, ref, pending)).map((chip) => ({ ...chip, piecesCount: counts.get(chip.id) ?? null }));

    return { items, deskUrl: deskEntryUrl(model.round(roundId)?.urls?.resultsDesk ?? null, ref) };
}

/**
 * The popover's body (RoundDialog `content`): the list, "No per-puzzle record" when there is none, the desk link.
 *
 * @param {{none: string, done: string, pieces: string, piecesOnly: string, ownTime: string, editOnDesk: string, newTab: string}} texts
 *        `pieces` with %placed% and %pieces%, `piecesOnly` with %placed% (a puzzle without its count), `ownTime` with %time%
 */
export function sheetPuzzlesPopoverHtml(popover, texts) {
    if (popover === null) {
        return '';
    }

    const items = popover.items.map((item) => {
        const name = item.name !== null ? ` <span class="text-body-secondary">${escapeHtml(item.name)}</span>` : '';
        let state = '';

        if (item.done) {
            const at = item.atSeconds !== null ? ` ${escapeHtml(formatResultTime(item.atSeconds))}` : '';
            const own = item.duration !== null && item.atSeconds !== null ? ` <span class="text-body-secondary">${escapeHtml(String(texts.ownTime).replace('%time%', formatResultTime(item.duration)))}</span>` : '';
            state = `<i class="bi bi-check-lg text-success" aria-hidden="true"></i><span class="visually-hidden">${escapeHtml(texts.done)}</span>${at}${own}`;
        } else if (item.piecesPlaced !== null) {
            const template = item.piecesCount !== null ? String(texts.pieces).replace('%pieces%', String(item.piecesCount)) : String(texts.piecesOnly);
            state = escapeHtml(template.replace('%placed%', String(item.piecesPlaced)));
        }

        return `<li class="sheet-puzzles-popover-item" data-puzzle="${escapeHtml(item.id)}"><span><strong>${escapeHtml(item.label)}</strong>${name}</span><span class="sheet-puzzles-popover-state">${state}</span></li>`;
    });
    const list = items.length > 0
        ? `<ol class="sheet-puzzles-popover list-unstyled mb-2">${items.join('')}</ol>`
        : `<p class="sheet-puzzles-popover-none small text-body-secondary mb-2">${escapeHtml(texts.none)}</p>`;
    const link = popover.deskUrl !== null
        ? `<a class="btn btn-sm btn-outline-primary w-100" href="${escapeHtml(popover.deskUrl)}" target="_blank" rel="noopener" data-puzzles-desk><i class="bi bi-table" aria-hidden="true"></i> ${escapeHtml(texts.editOnDesk)}<span class="visually-hidden"> ${escapeHtml(texts.newTab)}</span></a>`
        : '';

    return `${list}${link}`;
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
