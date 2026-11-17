/**
 * The judge's "Finished now" per puzzle on a pick round (real events W5, world-series-rules.md "Review amendments"):
 * a back-to-back block is ONE "Solve all 2 (order recorded)" round on one shared stopwatch, and the judge records each
 * puzzle's finish moment at the table - `atSeconds` of the round record (`result_puzzles`, cumulative splits).
 *
 * - Rows on the entry card: the puzzles finished so far (solving order, their split and own time) and - while the entry
 *   has no result - the ones that may still be finished (the chosen ones once K are chosen, else every puzzle not
 *   finished yet), each with "Finished now" while the stopwatch runs and a typed time (a correction or a time noted on
 *   paper).
 * - A tap / typed time that is not the K-th: the record alone (`puzzles`), the next puzzles chosen stay chosen and not
 *   done. The K-th: the round's result - the card's confirm step with the time, Save sends `puzzles` + `result` as one
 *   group (A rule 7), the last split = the time (withFinishSplit()).
 * - A typed time of a puzzle already finished corrects its split - never the last one of a finished entry (that split
 *   is the result: the round's time field changes both).
 *
 * The record's rules are official_results_puzzles.js (the twin of RoundEntryPuzzles) - imported, never re-implemented.
 * Pure functions - tests/live-entry-splits-harness.mjs runs them under node.
 */

import { duration, MAX_SECONDS, validate } from './official_results_puzzles.js';
import { pickFormat, puzzleChips } from './live_entry_puzzles.js';
import { sameValue } from './official_results_live.js';

function key(id) {
    return String(id).toLowerCase();
}

function list(record) {
    return Array.isArray(record) ? record.filter((item) => item && item.roundPuzzleId !== undefined) : [];
}

function finishedTime(result) {
    return Number.isInteger(result?.seconds);
}

function noResult(result) {
    return result === null || result === undefined;
}

/**
 * The round's `puzzleFormat` when per-puzzle finish times apply: a pick round where an entry solves 2 or more puzzles
 * (with one, the round's own "Finished now" is that puzzle's time). Null elsewhere - nothing changes there.
 */
export function splitFormat(round) {
    const format = pickFormat(round);

    return format !== null && Number.isInteger(format.toSolve) && format.toSolve >= 2 ? format : null;
}

/**
 * The puzzles that may be finished next: once K puzzles are chosen (on the card or in the record) only those, else
 * every round puzzle not finished - the chosen ones first.
 */
export function candidates(format, record, choice) {
    const items = list(record);
    const done = new Set(items.filter((item) => item.done === true).map((item) => key(item.roundPuzzleId)));

    if (done.size >= format.toSolve) {
        return [];
    }

    const chosen = [...(choice ?? []).map(key), ...items.map((item) => key(item.roundPuzzleId))].filter((id, index, all) => all.indexOf(id) === index);
    const pool = chosen.length >= format.toSolve ? chosen : [...chosen, ...format.puzzles.map((puzzle) => key(puzzle.roundPuzzleId)).filter((id) => !chosen.includes(id))];

    return pool.filter((id) => !done.has(id));
}

/**
 * What the card shows: `rows` = the finished puzzles (split, own time, `edit` = a typed correction allowed) then - only
 * while the entry has no result - the candidates (`tap` while the stopwatch runs, `edit` = a typed time). `show` is
 * false when there is nothing to show (no split yet and nothing to tap) - the card stays as it was.
 *
 * @returns {{show: boolean, rows: {id: string, label: string, order: number, done: boolean, at: number|null, own: number|null, tap: boolean, edit: boolean}[]}}
 */
export function splitRows(format, record, choice, result, running) {
    if (format === null) {
        return { show: false, rows: [] };
    }

    const labels = new Map(puzzleChips(format, []).map((chip) => [chip.id, chip.label]));
    const items = list(record);
    const done = items.filter((item) => item.done === true);
    const resultSet = !noResult(result);
    const rows = done.map((item, index) => ({
        id: key(item.roundPuzzleId),
        label: labels.get(key(item.roundPuzzleId)) ?? '?',
        order: index + 1,
        done: true,
        at: Number.isInteger(item.atSeconds) ? item.atSeconds : null,
        own: duration(items, index),
        tap: false,
        // The last split of a finished entry is its result - the time field changes both
        edit: !(finishedTime(result) && index === done.length - 1 && done.length === format.toSolve),
    }));

    if (!resultSet) {
        for (const id of candidates(format, items, choice)) {
            rows.push({ id, label: labels.get(id) ?? '?', order: 0, done: false, at: null, own: null, tap: running === true, edit: true });
        }
    }

    const show = rows.some((row) => row.done) || (running === true && rows.some((row) => row.tap));

    return { show, rows: show ? rows : [] };
}

/**
 * The record after puzzle `id` finished at `seconds` (a tap's elapsed or a typed time), on the record the card shows
 * and the card's choice. `finishes` = it is the K-th puzzle: the record goes with the round's result (the confirm
 * step), `choice` is then the solving order to confirm. `refusal` = an official_results.reason.* key, never sent.
 *
 * @returns {{record: object[]|null, choice: string[], finishes: boolean, refusal: string|null}}
 */
export function splitStep(format, record, choice, id, seconds, result) {
    const target = key(id);
    const items = list(record).map((item) => ({ ...item }));
    const done = items.filter((item) => item.done === true);
    const refuse = (refusal) => ({ record: null, choice: choice ?? [], finishes: false, refusal });

    if (!Number.isInteger(seconds) || seconds < 1 || seconds > MAX_SECONDS) {
        return refuse('invalid_split');
    }

    const index = done.findIndex((item) => key(item.roundPuzzleId) === target);

    if (index !== -1) {
        // A correction of a split already recorded - the result stays (never the last one of a finished entry)
        if (finishedTime(result) && index === done.length - 1 && done.length === format.toSolve) {
            return refuse('puzzles_mismatch');
        }

        done[index] = { ...done[index], atSeconds: seconds };
        const corrected = [...done, ...items.filter((item) => item.done !== true)];
        const refusal = validate(corrected, format, noResult(result) ? undefined : result);

        return refusal === null ? { record: corrected, choice: corrected.map((item) => key(item.roundPuzzleId)), finishes: false, refusal: null } : refuse(refusal);
    }

    if (!noResult(result) || !candidates(format, items, choice).includes(target)) {
        return refuse('puzzles_mismatch');
    }

    const finished = [...done, { roundPuzzleId: target, done: true, atSeconds: seconds }];
    const known = new Map(items.map((item) => [key(item.roundPuzzleId), item]));
    // The puzzles chosen next stay chosen, not done (the card's choice first, then the record's)
    const rest = [...(choice ?? []).map(key), ...items.map((item) => key(item.roundPuzzleId))]
        .filter((other, position, all) => all.indexOf(other) === position && other !== target && !finished.some((item) => key(item.roundPuzzleId) === other))
        .slice(0, Math.max(0, format.toSolve - finished.length))
        .map((other) => {
            const before = known.get(other);

            return before && before.done !== true ? before : { roundPuzzleId: other, done: false };
        });
    const next = [...finished, ...rest];
    const refusal = validate(next, format);

    if (refusal !== null) {
        return refuse(refusal);
    }

    return { record: next, choice: next.map((item) => key(item.roundPuzzleId)), finishes: finished.length === format.toSolve, refusal: null };
}

/**
 * The round's own "Finished now" on a card where puzzles were finished one by one: when K − 1 are finished, each with
 * its split, and exactly one puzzle is left, the choice that finishes the round with it (its split = the time, by
 * withFinishSplit()). Null otherwise - the round's Finished now does what it always did.
 */
export function finishChoice(format, record, choice) {
    if (format === null) {
        return null;
    }

    const done = list(record).filter((item) => item.done === true);

    if (done.length !== format.toSolve - 1 || done.length === 0 || !done.every((item) => Number.isInteger(item.atSeconds))) {
        return null;
    }

    const left = candidates(format, record, choice);

    return left.length === 1 ? [...done.map((item) => key(item.roundPuzzleId)), left[0]] : null;
}

/**
 * A finished result's record whose earlier puzzles all have their splits gets the last split too - the result's time
 * (rule 6: the last split, when given, is the time). Anything else comes back as it was (the same array).
 */
export function withFinishSplit(format, record, result) {
    const items = list(record);

    if (format === null || !finishedTime(result) || items.length !== format.toSolve || items.length < 2) {
        return record;
    }

    const last = items[items.length - 1];

    if (Number.isInteger(last.atSeconds) || !items.every((item) => item.done === true) || !items.slice(0, -1).every((item) => Number.isInteger(item.atSeconds))) {
        return record;
    }

    const split = [...items.slice(0, -1), { ...last, atSeconds: result.seconds }];

    return validate(split, format, result) === null ? split : record;
}

/**
 * A Save's fields (saveFields() of live_entry_puzzles.js) with withFinishSplit() applied: the `puzzles` change carries
 * the last split; when the record shown already equals the record without it, the change is added. Nothing else moves.
 *
 * @returns {{fields: {field: string, to: *}[], record: object[]|null|undefined}}
 */
export function finishFields(format, fields, record, result, shownRecord) {
    const split = withFinishSplit(format, record, result);

    if (split === record) {
        return { fields, record };
    }

    if (fields.some((field) => field.field === 'puzzles')) {
        return { fields: fields.map((field) => (field.field === 'puzzles' ? { ...field, to: split } : field)), record: split };
    }

    return sameValue(shownRecord, split) ? { fields, record: split } : { fields: [{ field: 'puzzles', to: split }, ...fields], record: split };
}

/**
 * A finished time for the whole round (typed, or the round's own "Finished now") on a card where puzzles were already
 * finished one by one: `choice` = the solving order that finishes the round (the finished puzzles, then the one left),
 * or a `refusal` shown on the card before anything is sent - `pick_remaining` (which puzzle was finished last is not
 * known: tap its chip first) or `splits_out_of_order` (the time is not after an earlier split - saving it would drop
 * every split). Nothing recorded per puzzle, or not a finished time: {choice: null, refusal: null} - as before.
 *
 * @returns {{choice: string[]|null, refusal: string|null}}
 */
export function finishGate(format, record, choice, value) {
    const done = list(record).filter((item) => item.done === true);

    if (format === null || !finishedTime(value) || done.length === 0) {
        return { choice: null, refusal: null };
    }

    const doneIds = done.map((item) => key(item.roundPuzzleId));
    const chosen = [...doneIds, ...(choice ?? []).map(key).filter((id) => !doneIds.includes(id))];
    const order = chosen.length === format.toSolve ? chosen : (doneIds.length >= format.toSolve ? doneIds : finishChoice(format, record, choice));

    if (order === null) {
        return { choice: null, refusal: 'pick_remaining' };
    }

    const splits = order.slice(0, -1).map((id) => done.find((item) => key(item.roundPuzzleId) === id)?.atSeconds).filter(Number.isInteger);

    if (splits.some((split) => value.seconds <= split)) {
        return { choice: null, refusal: 'splits_out_of_order' };
    }

    return { choice: order, refusal: null };
}

/**
 * The round's own "Finished now" while fewer than K − 1 puzzles are finished on a split round: the confirm step says it
 * finishes the whole round (a judge's habit tap meant for one puzzle).
 */
export function finishesWholeRound(format, record) {
    return format !== null && list(record).filter((item) => item.done === true).length < format.toSolve - 1;
}
