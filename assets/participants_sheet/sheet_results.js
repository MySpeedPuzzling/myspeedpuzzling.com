/**
 * Official results typed in the participants spreadsheet's round tabs (contract O3, docs/features/competitions-management/
 * participants-spreadsheet.md §11b and "Client architecture (as built)"): the grammar of a result cell, the table number
 * cell, what a cell shows, ranks of the round as the page shows it, and the RecordRoundResults changes (with their exact
 * inverse) an edit sends. Results are written only through `official_results_record` (RecordRoundResults) and table
 * swaps through `official_results_assign_table_numbers` (AssignTableNumbers) - never a second write path.
 *
 * Grammar of a result cell (one parser for typing and pasting):
 * - a time: `1:23:45`, `58:12`, digits right-aligned as h:mm:ss (`12345` = 1:23:45) - parseResultTime() of
 *   official_results_time.js, the live entry's and the desk's own parser;
 * - pieces placed (did not finish): `479p`, `479 p`, `479 pcs`, `479 pieces`, `479/500`, plus the words of the page's
 *   language (`piecesWords`) - 1 .. pieces of the round's puzzle - 1 (a finished puzzle gets a time);
 * - did not start: `DNS`, `-` (any dash) or the words of the page's language (`didNotStartWords`);
 * - empty: no result.
 *
 * Pure - pinned by tests/participants-sheet-round-harness.mjs.
 */

import { parseResultTime, formatResultTime } from '../official_results_time.js';
import { rankEntries } from '../official_results_ranking.js';
import { sameValue } from '../official_results_pending_changes.js';
import { foldSearchText } from '../search_fold.js';
import { resultsAction } from './sheet_changes.js';

export { sameValue };

export const MAX_TABLE_NUMBER = 9999;
// RecordRoundResultsHandler::MAX_PIECES_PLACED - a round without exactly one puzzle has no piece count to check against
export const MAX_PIECES_PLACED = 100000;

const DASHES = /^[-‐-―−]$/u;
const BUILT_IN_PIECES_WORDS = ['p', 'pc', 'pcs', 'piece', 'pieces'];
const BUILT_IN_DNS_WORDS = ['dns', 'did not start'];

/** Words of a comma-separated list (a translated text), folded. */
export function wordList(text) {
    return String(text ?? '').split(',').map((word) => foldSearchText(word)).filter((word) => word !== '');
}

function escapeRegExp(text) {
    return text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

/**
 * A typed or pasted result.
 *
 * @param {string} input
 * @param {{piecesCount?: number|null, piecesWords?: string[], didNotStartWords?: string[]}} [options]
 * @returns {{kind: 'empty'} | {kind: 'result', result: object} | {kind: 'error', reason: 'invalid'|'out_of_range'|'pieces_range', max?: number}}
 */
export function parseResultInput(input, { piecesCount = null, piecesWords = [], didNotStartWords = [] } = {}) {
    const text = String(input ?? '').trim();

    if (text === '') {
        return { kind: 'empty' };
    }

    const folded = foldSearchText(text).replace(/\.$/, '');

    if (DASHES.test(text) || [...BUILT_IN_DNS_WORDS, ...didNotStartWords.map((word) => foldSearchText(word))].includes(folded)) {
        return { kind: 'result', result: { didNotStart: true } };
    }

    const words = [...new Set([...BUILT_IN_PIECES_WORDS, ...piecesWords.map((word) => foldSearchText(word))])].filter(Boolean).map(escapeRegExp);
    const unit = `(?:\\s*(?:${words.join('|')})\\.?)`;
    const placed = new RegExp(`^(\\d{1,6})${unit}$`, 'u').exec(folded);
    const ofTotal = new RegExp(`^(\\d{1,6})\\s*/\\s*(\\d{1,6})${unit}?$`, 'u').exec(folded);

    if (placed !== null || ofTotal !== null) {
        const pieces = parseInt((placed ?? ofTotal)[1], 10);
        const total = piecesCount ?? (ofTotal !== null ? parseInt(ofTotal[2], 10) : null);
        const max = total !== null ? total - 1 : MAX_PIECES_PLACED;

        if (pieces < 1 || pieces > max) {
            return { kind: 'error', reason: 'pieces_range', max: Math.max(0, max) };
        }

        return { kind: 'result', result: { piecesPlaced: pieces } };
    }

    const time = parseResultTime(text);

    if (time.seconds !== undefined) {
        return { kind: 'result', result: { seconds: time.seconds } };
    }

    return { kind: 'error', reason: time.error === 'out_of_range' ? 'out_of_range' : 'invalid' };
}

/** The result a parsed input means: the result, null for "no result", undefined for an error. */
export function parsedValue(parsed) {
    if (parsed.kind === 'empty') {
        return null;
    }

    return parsed.kind === 'result' ? parsed.result : undefined;
}

/** The text an edit of a result cell starts with - what typing it again would mean. */
export function resultEditText(result) {
    if (result === null || result === undefined) {
        return '';
    }

    if (Number.isInteger(result.seconds)) {
        return formatResultTime(result.seconds);
    }

    if (Number.isInteger(result.piecesPlaced)) {
        return `${result.piecesPlaced}p`;
    }

    return result.didNotStart === true ? 'DNS' : '';
}

/**
 * What a result cell shows: `1:23:45`, `479 / 500 pcs`, `Did not start`, '' (no result).
 *
 * @param {{piecesPlaced: string, piecesPlacedOf: string, didNotStart: string}} texts with %placed% / %pieces%
 */
export function resultText(result, piecesCount, texts) {
    if (result === null || result === undefined) {
        return '';
    }

    if (Number.isInteger(result.seconds)) {
        return formatResultTime(result.seconds);
    }

    if (Number.isInteger(result.piecesPlaced)) {
        return (piecesCount ? texts.piecesPlacedOf : texts.piecesPlaced)
            .replace('%placed%', String(result.piecesPlaced))
            .replace('%pieces%', String(piecesCount ?? ''));
    }

    return result.didNotStart === true ? texts.didNotStart : '';
}

/**
 * The line under the editor while typing: "Finished in 1:23:45", "Didn't finish: 479 pieces placed", "Did not start",
 * "No result" - or why the input is not a result.
 *
 * @param {{finished: string, didNotFinish: string, didNotStart: string, noResult: string, invalid: string,
 *          outOfRange: string, piecesRange: string}} texts with %time% / %placed% / %max%
 */
export function resultPreview(parsed, texts) {
    switch (parsed.kind) {
        case 'empty':
            return texts.noResult;
        case 'error':
            if (parsed.reason === 'pieces_range') {
                return texts.piecesRange.replace('%max%', String(parsed.max ?? ''));
            }

            return parsed.reason === 'out_of_range' ? texts.outOfRange : texts.invalid;
        default:
            break;
    }

    const result = parsed.result;

    if (Number.isInteger(result.seconds)) {
        return texts.finished.replace('%time%', formatResultTime(result.seconds));
    }

    if (Number.isInteger(result.piecesPlaced)) {
        return texts.didNotFinish.replace('%placed%', String(result.piecesPlaced));
    }

    return texts.didNotStart;
}

/** The kind of a result: finished | unfinished | dns | none - what the Alt+Down list offers. */
export function resultKind(result) {
    if (result === null || result === undefined) {
        return 'none';
    }

    if (Number.isInteger(result.seconds)) {
        return 'finished';
    }

    return Number.isInteger(result.piecesPlaced) ? 'unfinished' : (result.didNotStart === true ? 'dns' : 'none');
}

// ---------------------------------------------------------------- table numbers

/**
 * A typed table number: 1 .. 9999, empty = no table.
 *
 * @returns {{kind: 'empty'} | {kind: 'number', value: number} | {kind: 'error', reason: 'invalid_table_number'}}
 */
export function parseTableNumber(input) {
    const text = String(input ?? '').trim().replace(/^#/, '');

    if (text === '') {
        return { kind: 'empty' };
    }

    if (!/^\d{1,6}$/.test(text)) {
        return { kind: 'error', reason: 'invalid_table_number' };
    }

    const value = parseInt(text, 10);

    return value >= 1 && value <= MAX_TABLE_NUMBER ? { kind: 'number', value } : { kind: 'error', reason: 'invalid_table_number' };
}

/**
 * The entry (`{ref, table, …}`) that holds a table number in the round, other than `exceptRef` - "Table 6 is Ben's".
 */
export function tableHolder(entries, number, exceptRef) {
    if (number === null || number === undefined) {
        return null;
    }

    return entries.find((entry) => entry.ref !== exceptRef && entry.table === number) ?? null;
}

/**
 * "Swap them": one AssignTableNumbers write - the entry gets the number, its holder the entry's old number (or none);
 * `from` = the numbers the page showed (seating.md: a number changed meanwhile refuses the whole write).
 */
export function swapAssignments(entry, number, holder) {
    return [
        { entry: entry.ref, from: entry.table ?? null, number },
        { entry: holder.ref, from: holder.table ?? null, number: entry.table ?? null },
    ];
}

/**
 * The swap as RecordRoundResults changes - the undo step of a swap (the server checks the table numbers of the whole set
 * after it, so two changes trading numbers go through together).
 */
export function swapAsResults(roundId, assignments) {
    return assignments.map((assignment) => ({ roundId, ref: assignment.entry, field: 'table_number', from: assignment.from, to: assignment.number }));
}

// ---------------------------------------------------------------- changes

/**
 * One official field edited (result, table_number, qualified): `from` = what the organiser saw when the edit began
 * (the results desk's openEditor() rule - a value somebody else saved meanwhile comes back as a conflict, never
 * overwritten), the undo = the swapped change.
 */
export function officialEdit(roundId, ref, field, from, to, label = { key: 'results' }) {
    return resultsAction([{ roundId, ref, field, from: from ?? null, to: to ?? (field === 'qualified' ? false : null) }], label);
}

/** Several official fields at once (a results paste) - one undo step. */
export function officialEdits(changes, label = { key: 'results' }) {
    return resultsAction(changes.map((change) => ({ ...change, from: change.from ?? null, to: change.to ?? null })), label);
}

// ---------------------------------------------------------------- ranks

/**
 * Ranks of the round's entries as the page shows them (unsaved values included): Map id → rank (null = unranked) -
 * official_results_ranking.js, the server's rules.
 *
 * @param {Array<{id: string, displayName: string, tableNumber: number|null, result: object|null}>} entries
 */
export function roundRanks(entries) {
    return new Map(rankEntries(entries).map((entry) => [entry.id, entry.rank]));
}

/** "entered by Eva · 10:42" - the time in the round's time zone. */
export function enteredLabel(enteredBy, enteredAt, { locale = 'en', timeZone = undefined, template = '%name% · %time%' } = {}) {
    if (!enteredBy && !enteredAt) {
        return '';
    }

    let time = '';

    if (enteredAt) {
        const date = new Date(enteredAt);

        if (!Number.isNaN(date.getTime())) {
            try {
                time = new Intl.DateTimeFormat(locale, { hour: '2-digit', minute: '2-digit', timeZone }).format(date);
            } catch (e) {
                time = new Intl.DateTimeFormat('en', { hour: '2-digit', minute: '2-digit' }).format(date);
            }
        }
    }

    return template.replace('%name%', enteredBy ?? '').replace('%time%', time).replace(/^\s*·\s*|\s*·\s*$/g, '').trim();
}
