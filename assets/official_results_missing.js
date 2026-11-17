/**
 * "Didn't finish" counted as the judge sees it (reliability hardening T1, round-formats.md "Missing pieces"): the
 * judge types the pieces still MISSING and the page computes the pieces placed = pieces − missing (or types placed,
 * as before). The value saved is always `piecesPlaced` - nothing changes on the server. Shared by the judge page
 * (live_results_controller.js) and the results desk; pure - tests/live-results-harness.mjs runs it under node.
 *
 * - Modes: `missing` (needs the puzzle's piece count) / `placed`. Pieces unknown → only `placed`.
 * - A one-puzzle (`total`) round: missing 1..pieces − 1 (placed 1..pieces − 1).
 * - A `pick` round: per chosen puzzle not finished ("open") one count; the entry's pieces placed = Σ finished puzzles'
 *   pieces + Σ the open ones' placed (official_results_puzzles.js total()). An open puzzle may have none placed (it was
 *   abandoned or never started - missing = all its pieces), as long as the entry placed at least one piece.
 * - Time's up: an entry without a result opens in "Didn't finish" once the round's limit is reached.
 */

import { total } from './official_results_puzzles.js';

export const MISSING = 'missing';
export const PLACED = 'placed';

function known(pieces) {
    return Number.isInteger(pieces) && pieces > 1;
}

/** A typed count: digits only (at most 6), else null. */
export function count(text) {
    const trimmed = String(text ?? '').trim();

    return /^\d{1,6}$/.test(trimmed) ? parseInt(trimmed, 10) : null;
}

/** The mode a field offers: the judge's preference while the piece count is known, else `placed`. */
export function piecesMode(preferred, pieces) {
    return known(pieces) && preferred !== PLACED ? MISSING : PLACED;
}

/**
 * One typed count read in `mode` for a puzzle of `pieces` (null = unknown).
 * `max` = the most placed allowed (default pieces − 1); `allowNone` = 0 placed is fine (an open puzzle of a pick round).
 *
 * @returns {{empty: boolean, placed: number|null, missing: number|null, error: boolean}}
 */
export function typedPieces(text, mode, pieces, { max = known(pieces) ? pieces - 1 : null, allowNone = false } = {}) {
    const typed = count(text);

    if (String(text ?? '').trim() === '') {
        return { empty: true, placed: null, missing: null, error: false };
    }

    const least = allowNone ? 0 : 1;
    let placed = null;

    if (typed !== null && mode === MISSING && known(pieces)) {
        placed = pieces - typed;
    } else if (typed !== null && mode === PLACED) {
        placed = typed;
    }

    if (placed === null || placed < least || (max !== null && placed > max) || (mode === MISSING && placed > pieces - 1)) {
        return { empty: false, placed: null, missing: null, error: true };
    }

    return { empty: false, placed, missing: known(pieces) ? pieces - placed : null, error: false };
}

/** What a field shows typed in the other mode (switching Missing ↔ Placed keeps the count the judge meant). */
export function convertTyped(text, from, to, pieces) {
    const typed = count(text);

    if (typed === null || from === to || !known(pieces) || typed > pieces) {
        return from === to ? String(text ?? '') : '';
    }

    return String(pieces - typed);
}

function key(id) {
    return String(id).toLowerCase();
}

/** The open (not finished) puzzles kept for a choice - those still chosen, else the last chosen. */
export function openFor(choice, open) {
    const kept = choice.filter((id) => (open ?? []).includes(id));

    return kept.length > 0 || choice.length === 0 ? kept : [choice[choice.length - 1]];
}

/** A tap on a chosen puzzle's finished / not finished switch - the last open one stays open (rule 6: at least one). */
export function toggleOpen(choice, open, id) {
    const current = openFor(choice, open);
    const tapped = key(id);

    if (!choice.includes(tapped)) {
        return current;
    }

    if (current.includes(tapped)) {
        return current.length > 1 ? current.filter((other) => other !== tapped) : current;
    }

    return choice.filter((other) => other === tapped || current.includes(other));
}

/** The open puzzles of a record (not done, still chosen) - the judge's starting point on an entry with one. */
export function openOfRecord(choice, record) {
    const notDone = (Array.isArray(record) ? record : []).filter((item) => item?.done !== true).map((item) => key(item.roundPuzzleId));

    return openFor(choice, notDone);
}

/**
 * A pick round's "Didn't finish": per open puzzle the typed count read in its mode (missing needs its piece count).
 *
 * @param {object} format    pickFormat()
 * @param {string[]} choice  chosen round puzzle ids, solving order
 * @param {string[]} open    openFor()
 * @param {Object<string, string>} texts  typed per open puzzle id
 * @param {string} preferred MISSING | PLACED
 * @returns {{rows: {id: string, open: boolean, pieces: number|null, mode: string, text: string, empty: boolean, placed: number|null, missing: number|null, error: boolean}[],
 *            notDone: Map<string, number|null>, result: {piecesPlaced: number}|null, firstEmpty: string|null, invalid: boolean}}
 */
export function pickUnfinished(format, choice, open, texts, preferred) {
    const opened = openFor(choice, open);
    const piecesOf = new Map((format?.puzzles ?? []).map((puzzle) => [key(puzzle.roundPuzzleId), puzzle.piecesCount ?? null]));
    const notDone = new Map();
    let firstEmpty = null;
    let invalid = false;

    const rows = choice.map((id) => {
        const pieces = piecesOf.get(id) ?? null;
        const mode = piecesMode(preferred, pieces);
        const isOpen = opened.includes(id);
        const text = isOpen ? String(texts?.[id] ?? '') : '';
        const read = isOpen ? typedPieces(text, mode, pieces, { allowNone: true }) : { empty: false, placed: null, missing: null, error: false };

        if (isOpen) {
            firstEmpty ??= read.empty ? id : null;
            invalid = invalid || read.error;
            notDone.set(id, read.placed);
        }

        return { id, open: isOpen, pieces, mode, text, ...read };
    });

    let result = null;

    if (choice.length > 0 && firstEmpty === null && !invalid) {
        const items = choice.map((id) => (notDone.has(id) ? { roundPuzzleId: id, done: false, piecesPlaced: notDone.get(id) || undefined } : { roundPuzzleId: id, done: true }));
        const placed = total(items, format);
        result = placed > 0 ? { piecesPlaced: placed } : null;
        invalid = placed < 1;
    }

    return { rows, notDone, result, firstEmpty, invalid };
}

/**
 * The round's time is up: a limit set and reached - the stopwatch running past it, or stopped after it.
 *
 * @param {string|null} status        stopwatchStatusAt(): running | stopped | scheduled | null
 * @param {number|null} elapsedSeconds running: seconds since the start; stopped: the stopped stopwatch's time
 * @param {number|null} minutesLimit
 */
export function timesUp(status, elapsedSeconds, minutesLimit) {
    const limit = (minutesLimit ?? 0) * 60;

    return limit > 0 && (status === 'running' || status === 'stopped') && Number.isInteger(elapsedSeconds) && elapsedSeconds >= limit;
}
