/**
 * The per-puzzle record of a `pick` round on the organiser's desk-side tools (competition toolkit A4 -
 * A-round-formats.md §2.6, R-review-and-plan.md §1.1): the results desk's per-puzzle editor, the record's chips on a
 * desk row and in the participants sheet's "Puzzles" column, and the record a participants sheet result edit sends with
 * the result so it never comes back `puzzles_mismatch`.
 *
 * The record's rules are official_results_puzzles.js (the twin of RoundEntryPuzzles) and the judge page's choice helpers
 * live_entry_puzzles.js - imported, never re-implemented. A hidden puzzle is shown by its label only: the round state
 * already sends no name for it (the public rule, organisers too), and a name that came anyway is still not shown.
 *
 * Pure - tests/official-results-desk-harness.mjs runs it under node.
 */

import { formatResultTime } from './official_results_time.js';
import { sameValue } from './official_results_pending_changes.js';
import { total, validate } from './official_results_puzzles.js';
import { choiceOf, pickFormat, puzzleChips, recordFor } from './live_entry_puzzles.js';

export { pickFormat };

function key(id) {
    return String(id ?? '').toLowerCase();
}

function record(value) {
    return Array.isArray(value) && value.length > 0 ? value : null;
}

/**
 * The round's puzzles as the editor offers them: id, label (always), name (only of a puzzle that is out).
 *
 * @returns {{id: string, label: string, name: string|null}[]}
 */
export function puzzleOptions(format) {
    return puzzleChips(format, []).map((chip) => ({ id: chip.id, label: chip.label, name: chip.name }));
}

/**
 * A record's items as chips, in solving order: label, name (only when out), finished + its cumulative split and own
 * time, or the pieces of a puzzle not finished.
 *
 * @returns {{id: string, label: string, name: string|null, done: boolean, atSeconds: number|null, duration: number|null, piecesPlaced: number|null}[]}
 */
export function recordChips(format, value) {
    const items = record(value) ?? [];
    const options = new Map(puzzleOptions(format).map((option) => [option.id, option]));

    return items.map((item, index) => {
        const option = options.get(key(item.roundPuzzleId));
        const previous = index > 0 ? items[index - 1].atSeconds : 0;
        const at = Number.isInteger(item.atSeconds) ? item.atSeconds : null;

        return {
            id: key(item.roundPuzzleId),
            label: option?.label ?? '?',
            name: option?.name ?? null,
            done: item.done === true,
            atSeconds: at,
            duration: at !== null && Number.isInteger(previous) ? at - previous : null,
            piecesPlaced: item.done !== true && Number.isInteger(item.piecesPlaced) ? item.piecesPlaced : null,
        };
    });
}

/**
 * The record in one line - "B ✓ 0:41:10 · A 312 pcs" (labels only - a sheet cell and a conflict line). Empty = none.
 *
 * @param {{pieces: string}} texts `pieces` with %placed%
 */
export function recordText(format, value, texts) {
    return recordChips(format, value).map((chip) => {
        if (chip.done) {
            return chip.atSeconds !== null ? `${chip.label} ✓ ${formatResultTime(chip.atSeconds)}` : `${chip.label} ✓`;
        }

        return chip.piecesPlaced !== null ? `${chip.label} ${String(texts?.pieces ?? '%placed%').replace('%placed%', String(chip.piecesPlaced))}` : chip.label;
    }).join(' · ');
}

/**
 * The editor's rows when it opens: K rows (more when a record holds more), filled from the record shown.
 *
 * @returns {{id: string, done: boolean, at: number|null, pieces: number|null}[]}
 */
export function editorRows(format, value) {
    const items = record(value) ?? [];
    const rows = items.map((item) => ({
        id: key(item.roundPuzzleId),
        done: item.done === true,
        at: Number.isInteger(item.atSeconds) ? item.atSeconds : null,
        pieces: Number.isInteger(item.piecesPlaced) ? item.piecesPlaced : null,
    }));

    while (rows.length < (format?.toSolve ?? 1)) {
        rows.push({ id: '', done: false, at: null, pieces: null });
    }

    return rows;
}

/**
 * What the per-puzzle editor would save: the result and the record, as one group.
 *
 * - `time` (finished): all K puzzles chosen, all finished; the last one's "finished at" is the time (required), the
 *   others' splits are optional.
 * - `pieces` (didn't finish): the chosen puzzles, each finished (optional split) or not (optional pieces); the result's
 *   pieces = total() of the record (a finished puzzle counts all its pieces).
 * - `none` (no result yet): the choice alone - puzzles finished so far may carry their split.
 * - `dns`: no record.
 *
 * Rows without a puzzle are left out. `error` = the editor's own message key (nothing chosen, time missing…),
 * `refusal` = the server's reason (validate()) - neither is ever sent.
 *
 * @param {{id: string, done: boolean, at: number|null, pieces: number|null}[]} rows
 * @returns {{result: object|null, record: object[]|null, error: string|null, refusal: string|null}}
 */
export function editorOutcome(format, mode, rows) {
    if (mode === 'dns') {
        return { result: { didNotStart: true }, record: null, error: null, refusal: null };
    }

    const chosen = (rows ?? []).filter((row) => row.id !== '');
    const items = chosen.map((row) => {
        const done = mode === 'time' ? true : row.done === true;

        if (done) {
            return Number.isInteger(row.at) ? { roundPuzzleId: row.id, done: true, atSeconds: row.at } : { roundPuzzleId: row.id, done: true };
        }

        return mode === 'pieces' && Number.isInteger(row.pieces) ? { roundPuzzleId: row.id, done: false, piecesPlaced: row.pieces } : { roundPuzzleId: row.id, done: false };
    });
    const value = items.length > 0 ? items : null;
    let result = null;

    if (mode === 'time') {
        if (items.length !== format.toSolve) {
            return { result: null, record: value, error: 'choose_all', refusal: null };
        }

        const last = items[items.length - 1];

        if (!Number.isInteger(last.atSeconds)) {
            return { result: null, record: value, error: 'time_missing', refusal: null };
        }

        result = { seconds: last.atSeconds };
    } else if (mode === 'pieces') {
        if (items.length === 0) {
            return { result: null, record: null, error: 'choose_one', refusal: null };
        }

        const pieces = total(items, format);

        if (pieces < 1) {
            return { result: null, record: value, error: 'pieces_missing', refusal: null };
        }

        result = { piecesPlaced: pieces };
    }

    return { result, record: value, error: null, refusal: validate(value, format, result) };
}

/**
 * The record a participants sheet result edit sends with the result on a `pick` round (the brief's "never a
 * `puzzles_mismatch` dead end"): the chosen puzzles are kept and the record recomputed for the new result by the
 * judge page's own rule (recordFor: finished = all done, the last split dropped or = the time; unfinished = the puzzles
 * before the last count in full, the last gets the rest of the pieces; did not start = none; no result = kept). When
 * no record fits the result (fewer than K chosen for a time, pieces that cannot be split that way) the record is
 * cleared - the result wins, the undo brings both back.
 *
 * @returns {{changed: boolean, record: object[]|null, cleared: boolean}} `changed` = a `puzzles` change must go along
 */
export function consistentRecord(format, result, shown) {
    const before = record(shown);

    if (format === null || before === null) {
        return { changed: false, record: before, cleared: false };
    }

    const { record: recomputed, refusal } = recordFor(format, choiceOf(format, before), result ?? null, before);
    const next = refusal === null ? record(recomputed) : null;

    return { changed: !sameValue(next, before), record: next, cleared: next === null };
}
