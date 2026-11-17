/**
 * Finished with pieces missing (competition toolkit T6, official-results.md "Finished with pieces missing"): a puzzle
 * finished in time that still lacks pieces - a piece missing from the factory. The judge records "finished at 1:23:45
 * with 1 piece missing"; it is the entry's own field `missing_pieces` (null = complete), next to the result, never a
 * part of it - so a time corrected on a device that does not know the field keeps the count, and any other result
 * (didn't finish, did not start, cleared) drops it on the server. Shared by the judge page and the results desk; pure -
 * tests/live-results-harness.mjs runs it under node.
 */

export const FIELD = 'missing_pieces';
export const MAX_MISSING_PIECES = 9999;

function finished(result) {
    return result !== null && result !== undefined && typeof result === 'object' && Number.isInteger(result.seconds);
}

/**
 * A typed count of missing pieces: empty = none (the puzzle was complete), else 1..pieces − 1 (pieces known) or
 * 1..9999.
 *
 * @returns {{empty: true} | {value: number} | {error: true}}
 */
export function parseMissingPieces(text, pieces = null) {
    const trimmed = String(text ?? '').trim();

    if (trimmed === '') {
        return { empty: true };
    }

    if (!/^\d{1,4}$/.test(trimmed)) {
        return { error: true };
    }

    const value = parseInt(trimmed, 10);
    const max = Number.isInteger(pieces) && pieces > 1 ? Math.min(pieces - 1, MAX_MISSING_PIECES) : MAX_MISSING_PIECES;

    return value < 1 || value > max ? { error: true } : { value };
}

/**
 * The stored count of an entry (or a pending one): a positive integer, else null.
 */
export function missingOf(value) {
    return Number.isInteger(value) && value > 0 ? value : null;
}

/**
 * The `missing_pieces` change a Save adds after the result (same group, the server applies them together):
 * - only with a finished result - any other result drops the count on the server, nothing to send;
 * - `typed` undefined = the judge did not open the field: the count stays as it is (no change);
 * - otherwise the typed count (null = complete) when it differs from what is shown.
 *
 * @param {object|null} result the result being saved
 * @param {number|null|undefined} typed
 * @param {number|null|undefined} shown the count the device shows now (server or its own pending change)
 * @returns {{field: string, to: number|null}|null}
 */
export function missingPiecesChange(result, typed, shown) {
    if (!finished(result) || typed === undefined) {
        return null;
    }

    const to = missingOf(typed);

    return to === missingOf(shown) ? null : { field: FIELD, to };
}

/**
 * "1:23:45" → "1:23:45 · 1 piece missing" for a finished result with a count; any other text stays.
 *
 * @param {(count: number) => string} label the translated "%count% pieces missing"
 */
export function withMissing(text, result, missing, label) {
    const count = missingOf(missing);

    return finished(result) && count !== null ? `${text} · ${label(count)}` : text;
}
