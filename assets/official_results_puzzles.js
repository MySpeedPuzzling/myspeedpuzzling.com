/**
 * The per-puzzle record of a `pick` round entry in the browser (competition toolkit A3) - the rules of
 * Value\RoundEntryPuzzles, so the judge page, the results desk and the competitor page compute the total and refuse
 * exactly what the server refuses (pinned by tests/OfficialResultsPuzzlesParityTest.php). Change both together.
 *
 * Items are the wire format, in solving order: [{roundPuzzleId, done, atSeconds?, piecesPlaced?}]; null or [] = no
 * record. `round` is the round state's `puzzleFormat`: {mode: "pick", toSolve: K, puzzles: [{roundPuzzleId,
 * piecesCount}, …]}. A result is RoundEntryResult's wire format: null | {seconds} | {piecesPlaced} | {didNotStart: true}.
 * Refusals are the official_results.reason.* keys the server answers with.
 *
 * The shape rules of RoundEntryPuzzles::fromWire() are mirrored by shapeRefusal() (the server answers a record of
 * another shape with `invalid_change`). Two differences of JSON itself stay server-side only
 * (docs/features/competitions-management/round-formats.md "Browser twin"): a whole number written with a fraction
 * (`100.0` - PHP reads a float and refuses it, JavaScript cannot tell it from `100`), and a JSON object PHP decodes into
 * a list (`{}` or keys "0".."n-1" - the browser builds arrays only and refuses any other value here).
 */

export const MAX_SECONDS = 86399;

// RoundEntryPuzzles::MAX_ITEMS - far more than any round has puzzles
const MAX_ITEMS = 50;

const ITEM_KEYS = ['roundPuzzleId', 'done', 'atSeconds', 'piecesPlaced'];

// Ramsey's Uuid::isValid() (GenericValidator): any variant, an optional "urn:" and "uuid:" and one pair of braces
const HEX = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';
const UUID = new RegExp(`^(?:urn:)?(?:uuid:)?(?:\\{${HEX}\\}|${HEX})$`, 'i');

// A key whose value is undefined never reaches the server (JSON.stringify drops it)
function readable(item) {
    return item !== null && typeof item === 'object'
        && Object.keys(item).every((name) => item[name] === undefined || ITEM_KEYS.includes(name))
        && typeof item.roundPuzzleId === 'string' && UUID.test(item.roundPuzzleId)
        && typeof item.done === 'boolean'
        && (!given(item.atSeconds) || (Number.isInteger(item.atSeconds) && item.done))
        && (!given(item.piecesPlaced) || (Number.isInteger(item.piecesPlaced) && !item.done));
}

/**
 * RoundEntryPuzzles::fromWire() - the record's shape: null or a list of at most 50 items, each exactly
 * {roundPuzzleId: a UUID, done: true|false, atSeconds?: an integer on a done item, piecesPlaced?: an integer on one
 * not done}. `invalid_change` (what the server answers for an unreadable change) or null = readable.
 */
export function shapeRefusal(items) {
    return given(items) && (!Array.isArray(items) || items.length > MAX_ITEMS || !items.every(readable)) ? 'invalid_change' : null;
}

function piecesById(round) {
    const pieces = new Map();

    for (const puzzle of round?.puzzles ?? []) {
        pieces.set(String(puzzle.roundPuzzleId).toLowerCase(), puzzle.piecesCount ?? null);
    }

    return pieces;
}

function list(items) {
    return Array.isArray(items) ? items : [];
}

function idOf(item) {
    return String(item.roundPuzzleId).toLowerCase();
}

function given(value) {
    return value !== undefined && value !== null;
}

/**
 * The shape (shapeRefusal()), then RoundEntryPuzzles::itemsRefusal() - rules 2-5: puzzles of the round, each once, at most K, splits and pieces in
 * range, finished puzzles first with increasing splits. Null = fine.
 */
export function itemsRefusal(items, round) {
    const shape = shapeRefusal(items);

    if (shape !== null) {
        return shape;
    }

    const pieces = piecesById(round);
    const record = list(items);
    const seen = new Set();

    for (const item of record) {
        if (!pieces.has(idOf(item))) {
            return 'unknown_round_puzzle';
        }

        if (seen.has(idOf(item))) {
            return 'puzzle_listed_twice';
        }

        seen.add(idOf(item));
    }

    if (record.length > (round?.toSolve ?? 0)) {
        return 'too_many_puzzles';
    }

    for (const item of record) {
        if (given(item.atSeconds) && (item.atSeconds < 1 || item.atSeconds > MAX_SECONDS)) {
            return 'invalid_split';
        }

        const count = pieces.get(idOf(item));

        if (given(item.piecesPlaced) && (item.piecesPlaced < 1 || (count !== null && item.piecesPlaced > count - 1))) {
            return 'invalid_pieces_placed';
        }
    }

    let notDoneSeen = false;
    let lastSplit = null;

    for (const item of record) {
        if (item.done !== true) {
            notDoneSeen = true;
            continue;
        }

        if (notDoneSeen) {
            return 'splits_out_of_order';
        }

        if (given(item.atSeconds)) {
            if (lastSplit !== null && item.atSeconds <= lastSplit) {
                return 'splits_out_of_order';
            }

            lastSplit = item.atSeconds;
        }
    }

    return null;
}

/**
 * The entry's total pieces placed (rule 6): a finished puzzle counts all its pieces, one not finished its
 * `piecesPlaced` (none = 0). This is the `piecesPlaced` of an unfinished result sent with the record.
 */
export function total(items, round) {
    const pieces = piecesById(round);

    return list(items).reduce((sum, item) => sum + (item.done === true ? (pieces.get(idOf(item)) ?? 0) : (item.piecesPlaced ?? 0)), 0);
}

/**
 * RoundEntryPuzzles::consistencyRefusal() - rule 6, the record against the result: did not start = no record;
 * finished = exactly K puzzles, all finished, no split after the time, the last split (when given) = the time;
 * unfinished = at least one puzzle not finished and pieces placed = total(); no result yet = anything.
 */
export function consistencyRefusal(result, items, round) {
    const record = list(items);

    if (record.length === 0 || !result) {
        return null;
    }

    if (result.didNotStart === true) {
        return 'puzzles_mismatch';
    }

    if (Number.isInteger(result.seconds)) {
        if (record.length !== round?.toSolve) {
            return 'puzzles_mismatch';
        }

        for (const item of record) {
            if (item.done !== true || (given(item.atSeconds) && item.atSeconds > result.seconds)) {
                return 'puzzles_mismatch';
            }
        }

        const last = record[record.length - 1];

        return given(last.atSeconds) && last.atSeconds !== result.seconds ? 'puzzles_mismatch' : null;
    }

    if (Number.isInteger(result.piecesPlaced)) {
        if (!record.some((item) => item.done !== true) || total(record, round) !== result.piecesPlaced) {
            return 'puzzles_mismatch';
        }
    }

    return null;
}

/**
 * Everything the server checks on a record: rules 2-5, then rule 6 against `result` when one is given (undefined =
 * the record alone). Null = the server takes it.
 */
export function validate(items, round, result = undefined) {
    return itemsRefusal(items, round) ?? (result === undefined ? null : consistencyRefusal(result, items, round));
}

/**
 * RoundEntryPuzzles::duration() - one puzzle's own time: its split minus the previous one's (the first: its split);
 * null when a split it needs is missing.
 */
export function duration(items, index) {
    const record = list(items);
    const item = record[index];

    if (!item || !given(item.atSeconds)) {
        return null;
    }

    if (index === 0) {
        return item.atSeconds;
    }

    const previous = record[index - 1].atSeconds;

    return given(previous) ? item.atSeconds - previous : null;
}

/**
 * RoundEntryPuzzles::unfinishedMaximum() - the most pieces placed an unfinished result without a record may have:
 * K × the round's biggest puzzle − 1.
 */
export function unfinishedMaximum(round) {
    const counts = (round?.puzzles ?? []).map((puzzle) => puzzle.piecesCount ?? 0);

    return counts.length === 0 ? 0 : (round?.toSolve ?? 0) * Math.max(...counts) - 1;
}
