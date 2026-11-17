/**
 * The puzzle choice of a `pick` round on the judge page (competition toolkit C10 - R-review-and-plan.md §1.1,
 * C-judge-page.md §2.3): which K of the round's N puzzles an entry solves, in its solving order, saved with the result
 * as one group. The record's rules are official_results_puzzles.js (the twin of RoundEntryPuzzles) - imported, never
 * re-implemented here.
 *
 * - Chips: the label always; the name and picture only of a puzzle the round state says is out (`hidden: false`). The
 *   server already sends nulls for a hidden one (the public rule, for organisers too); a name that came anyway for a
 *   hidden slot is still not shown.
 * - Taps: K = 1 picks one (tap again: none); K > 1 adds in solving order, tap again removes, a tap when K are chosen
 *   replaces the last.
 * - Save: the record that fits the result (finished = all K done; unfinished = the puzzles before the last done, the
 *   last one with its pieces; did not start = none; no result = chosen, not done) goes with the result in one group.
 *
 * Pure functions - tests/live-results-harness.mjs runs them under node.
 */

import { shownValue, sameValue } from './official_results_live.js';
import { total, unfinishedMaximum, validate } from './official_results_puzzles.js';

/**
 * The round's `puzzleFormat` when it is a pick round, else null (a `total` round has no choice).
 */
export function pickFormat(round) {
    const format = round?.puzzleFormat;

    return format && format.mode === 'pick' && Array.isArray(format.puzzles) && format.puzzles.length > 0 ? format : null;
}

function key(id) {
    return String(id).toLowerCase();
}

/**
 * The round puzzle ids of a record, in solving order (only puzzles of the round).
 */
export function choiceOf(format, record) {
    const known = new Set((format?.puzzles ?? []).map((puzzle) => key(puzzle.roundPuzzleId)));

    return (Array.isArray(record) ? record : [])
        .map((item) => key(item?.roundPuzzleId))
        .filter((id, index, all) => known.has(id) && all.indexOf(id) === index);
}

/**
 * A tap on a chip (see the header).
 */
export function togglePick(choice, id, toSolve) {
    const tapped = key(id);

    if (choice.includes(tapped)) {
        return choice.filter((picked) => picked !== tapped);
    }

    if (toSolve <= 1) {
        return [tapped];
    }

    return choice.length < toSolve ? [...choice, tapped] : [...choice.slice(0, toSolve - 1), tapped];
}

function safeImage(url) {
    return typeof url === 'string' && /^(https:\/\/|\/(?!\/))/.test(url) ? url : null;
}

/**
 * What a chip shows: `order` is the solving order (0 = not chosen); `name`/`imageUrl` only for a puzzle that is out.
 *
 * @returns {{id: string, label: string, name: string|null, imageUrl: string|null, hidden: boolean, order: number}[]}
 */
export function puzzleChips(format, choice) {
    return (format?.puzzles ?? []).map((puzzle) => {
        const out = puzzle.hidden === false;
        const id = key(puzzle.roundPuzzleId);

        return {
            id,
            label: typeof puzzle.label === 'string' && puzzle.label !== '' ? puzzle.label : String(puzzle.position ?? ''),
            name: out && typeof puzzle.name === 'string' && puzzle.name !== '' ? puzzle.name : null,
            imageUrl: out ? safeImage(puzzle.imageUrl) : null,
            hidden: !out,
            order: choice.indexOf(id) + 1,
        };
    });
}

/**
 * The labels of a record in solving order - "B", "B → A" (a puzzle not of the round: "?").
 */
export function describeChoice(format, record) {
    const labels = new Map(puzzleChips(format, []).map((chip) => [chip.id, chip.label]));

    return (Array.isArray(record) ? record : []).map((item) => labels.get(key(item.roundPuzzleId)) ?? '?').join(' → ');
}

function piecesOf(format, id) {
    return (format.puzzles.find((puzzle) => key(puzzle.roundPuzzleId) === id)?.piecesCount) ?? null;
}

/**
 * The most pieces the judge may type for an unfinished entry: on the last chosen puzzle (its count − 1), or - nothing
 * chosen - for the whole round (K × the biggest − 1). Null = no limit known.
 */
export function piecesLimit(format, choice) {
    if (choice.length === 0) {
        return unfinishedMaximum(format) || null;
    }

    const count = piecesOf(format, choice[choice.length - 1]);

    return typeof count === 'number' && count > 1 ? count - 1 : null;
}

function doneItems(ids, previous) {
    return ids.map((id) => {
        const before = previous.find((item) => key(item.roundPuzzleId) === id);

        return before?.done === true && Number.isInteger(before.atSeconds) ? { roundPuzzleId: id, done: true, atSeconds: before.atSeconds } : { roundPuzzleId: id, done: true };
    });
}

function withoutSplits(items) {
    return items.map(({ atSeconds, ...item }) => item);
}

/**
 * The unfinished result: the pieces typed are those of the last chosen puzzle, the puzzles before it count in full
 * (nothing chosen: the pieces typed are the result).
 */
export function unfinishedResult(format, choice, pieces) {
    if (choice.length === 0) {
        return { piecesPlaced: pieces };
    }

    return { piecesPlaced: total(doneItems(choice.slice(0, -1), []), format) + pieces };
}

/**
 * The record that goes with `result` (undefined = no result entered, the choice alone). `previous` keeps the splits
 * the desk may have recorded. Null = no record (did not start, nothing chosen, or a finished result without all K
 * chosen - saved without them, "Puzzle missing" then). `refusal` = the record would not fit the result (never sent).
 *
 * @returns {{record: object[]|null, refusal: string|null}}
 */
export function recordFor(format, choice, result, previous = null) {
    const before = Array.isArray(previous) ? previous : [];

    if (choice.length === 0 || result?.didNotStart === true) {
        return { record: null, refusal: null };
    }

    let items;

    if (result === null || result === undefined) {
        items = choice.map((id) => before.find((item) => key(item.roundPuzzleId) === id) ?? { roundPuzzleId: id, done: false });

        if (validate(items, format) !== null) {
            items = choice.map((id) => ({ roundPuzzleId: id, done: false }));
        }
    } else if (Number.isInteger(result.seconds)) {
        if (choice.length !== format.toSolve) {
            return { record: null, refusal: null };
        }

        items = [...doneItems(choice.slice(0, -1), before), { roundPuzzleId: choice[choice.length - 1], done: true }];
    } else {
        const done = doneItems(choice.slice(0, -1), before);
        const rest = result.piecesPlaced - total(done, format);
        items = [...done, rest > 0 ? { roundPuzzleId: choice[choice.length - 1], done: false, piecesPlaced: rest } : { roundPuzzleId: choice[choice.length - 1], done: false }];
    }

    const target = result ?? undefined;

    if (validate(items, format, target) !== null) {
        items = withoutSplits(items);
    }

    const refusal = validate(items, format, target);

    return refusal === null ? { record: items, refusal: null } : { record: null, refusal };
}

/**
 * The fields one Save sends for an entry, in one group (A rule 7 - the server applies them together or not at all):
 * the result when it changes, the record when it changes; the result goes along with a record change while its own
 * earlier change still waits on this device (rule 6 is checked on the result the server holds).
 *
 * @param {object|null} format   pickFormat() - null: the result alone, as on every other round
 * @param {string[]} choice
 * @param {*} value              the result to save (RoundEntryResult's wire format)
 * @param {object} entry
 * @param {object[]} outboxItems
 * @returns {{fields: {field: string, to: *}[], record: object[]|null|undefined, refusal: string|null}}
 */
export function saveFields(format, choice, value, entry, outboxItems) {
    const result = shownValue(entry, 'result', outboxItems);
    const resultSettled = result.item === null || result.item.state === 'pending';
    const resultChanges = !(sameValue(result.value, value) && resultSettled);
    const fields = [];
    let record;
    let refusal = null;

    if (format !== null) {
        const known = shownValue(entry, 'puzzles', outboxItems);
        ({ record, refusal } = recordFor(format, choice, value, known.value));

        if (refusal === null && !(sameValue(known.value, record) && (known.item === null || known.item.state === 'pending'))) {
            fields.push({ field: 'puzzles', to: record });
        }
    }

    if (resultChanges || (fields.length > 0 && result.item !== null)) {
        fields.push({ field: 'result', to: value });
    }

    return { fields, record, refusal };
}

/**
 * Entries with a result (a time or pieces) but no puzzle chosen - the "Puzzle missing" list of the find view.
 *
 * @param {Map<string, object[]>} byEntry  outboxByEntry()
 */
export function missingChoice(entries, format, byEntry) {
    if (format === null) {
        return [];
    }

    return entries.filter((entry) => {
        const own = byEntry.get(entry.ref) ?? [];
        const result = shownValue(entry, 'result', own).value;

        if (!result || (!Number.isInteger(result.seconds) && !Number.isInteger(result.piecesPlaced))) {
            return false;
        }

        const record = shownValue(entry, 'puzzles', own).value;

        return !Array.isArray(record) || record.length === 0;
    });
}
