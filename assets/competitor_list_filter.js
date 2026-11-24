// The competitor list's search (templates/competitor_page/list.html.twig, competitor_list_controller.js): the server
// renders every row's folded name(s) and shown #code into `data-search` (SearchText::fold()) and the keys of its tables
// (TableLabels::key()), the browser folds only what was typed.
//
// - Names: every typed word must be in a row's text.
// - Tables (competitor-pages.md "Competitor list - search by table"): the typed text as one table key
//   (table_labels.js key(): "a1 l", "A1-L", "a1l" → `A.1.L`) - an exact table name first, then a table that continues it
//   with whole segments ("a1" → A1 L, A1 R), then one whose last segment only starts like it ("a1" → A10 L). Only an
//   exact name matches without a digit typed - "a" is a name search, not every table of column A. Leading words may be
//   the row's names or the word "table" in any of the 6 languages ("anna a1", "table a1"); a "#code" is never a table.
// - A row shows when its names or one of its tables match; while some row matched by a table, rows are ordered by how
//   well (exact, whole segments, started, names only), the server's order within each.
//
// Pure, pinned by tests/CompetitorListScriptTest.php.

import { foldSearchText } from './search_fold.js';
import { continues, key } from './table_labels.js';

// The word "table" as people type it before a table name, folded (en/fr, cs, de, es, ja)
const TABLE_WORDS = new Set(['table', 'tables', 'stul', 'stol', 'stolu', 'stoly', 'tisch', 'mesa', 'テーブル', '卓']);

export const TABLE_EXACT = 0;
export const TABLE_SEGMENTS = 1;
export const TABLE_STARTED = 2;

/**
 * The typed words, folded like the server folds the rows ("#code" keeps its "#")
 *
 * @param {string} query
 * @returns {string[]}
 */
export function competitorListTokens(query) {
    return foldSearchText(query ?? '').split(' ').filter((token) => token !== '');
}

/**
 * @param {string} search a row's `data-search`
 * @param {string[]} tokens competitorListTokens()
 */
export function competitorListRowMatches(search, tokens) {
    return tokens.every((token) => (search ?? '').includes(token));
}

/**
 * How well a table (its key) matches the typed text's key - TABLE_EXACT / TABLE_SEGMENTS / TABLE_STARTED, null for no
 * match.
 *
 * @param {string} typedKey key() of the typed text
 * @param {string} tableKey
 * @returns {null|number}
 */
export function tableMatch(typedKey, tableKey) {
    if (typedKey === '' || typeof tableKey !== 'string' || tableKey === '') {
        return null;
    }

    if (tableKey === typedKey) {
        return TABLE_EXACT;
    }

    // Without a digit typed, only an exact table name - else "a" would list the whole column A
    if (!/[0-9]/.test(typedKey) || !continues(typedKey, tableKey)) {
        return null;
    }

    return tableKey.startsWith(`${typedKey}.`) ? TABLE_SEGMENTS : TABLE_STARTED;
}

/**
 * The ways the typed text names a table: the words from position i on as one key, the words before it being the row's
 * names or "table" - [{key, before: folded words}], the whole text first. None for a "#code".
 *
 * @param {string} query
 * @returns {{key: string, before: string[]}[]}
 */
export function tableQueries(query) {
    const words = String(query ?? '').trim().split(/\s+/u).filter((word) => word !== '');

    if (words.length === 0 || words[0].startsWith('#')) {
        return [];
    }

    const queries = [];

    words.forEach((word, index) => {
        const typedKey = key(words.slice(index).join(' '));

        if (typedKey !== '' && !word.startsWith('#')) {
            queries.push({ key: typedKey, before: words.slice(0, index).map((before) => foldSearchText(before)).filter((before) => before !== '') });
        }
    });

    return queries;
}

/**
 * @param {string|{search?: string, tables?: string[]}} row a row's `data-search`, or it with its tables' keys
 */
function rowOf(row) {
    if (typeof row === 'string') {
        return { search: row, tables: [] };
    }

    return { search: row?.search ?? '', tables: Array.isArray(row?.tables) ? row.tables : [] };
}

/**
 * Which rows show, in which order, and how many per section.
 *
 * @param {Array<Array<string|{search?: string, tables?: string[]}>>} sections
 * @param {string} query
 * @returns {{ shown: boolean[][], counts: number[], total: number, order: number[][], tables: string[][][] }}
 *   order = each section's row indexes as they show (hidden ones included, last); tables = per row the keys of its
 *   tables that matched best (empty when the row matched by its names only)
 */
export function filterCompetitorList(sections, query) {
    const tokens = competitorListTokens(query);
    const typedTables = tableQueries(query);

    const shown = [];
    const order = [];
    const tables = [];

    sections.forEach((rows) => {
        const ranks = [];
        const sectionShown = [];
        const sectionTables = [];

        rows.map(rowOf).forEach(({ search, tables: keys }) => {
            let best = null;
            let matched = [];

            // The table part only for rows whose names (or "table") are the words before it
            const usable = typedTables.filter(({ before }) => before.every((word) => TABLE_WORDS.has(word) || search.includes(word)));

            keys.forEach((tableKey) => {
                const match = usable.reduce((best, { key: typedKey }) => {
                    const tier = tableMatch(typedKey, tableKey);

                    return tier !== null && (best === null || tier < best) ? tier : best;
                }, null);

                if (match === null) {
                    return;
                }

                if (best === null || match < best) {
                    best = match;
                    matched = [tableKey];
                } else if (match === best && !matched.includes(tableKey)) {
                    matched.push(tableKey);
                }
            });

            const byName = competitorListRowMatches(search, tokens);
            sectionShown.push(byName || best !== null);
            sectionTables.push(matched);
            // Names only rank after every table match; a hidden row goes last (it does not show anyway)
            ranks.push(best ?? (byName ? 3 : 4));
        });

        const indexes = rows.map((_, index) => index);
        if (ranks.some((rank) => rank < 3)) {
            indexes.sort((a, b) => (ranks[a] - ranks[b]) || (a - b));
        }

        shown.push(sectionShown);
        order.push(indexes);
        tables.push(sectionTables);
    });

    const counts = shown.map((rows) => rows.filter(Boolean).length);

    return { shown, counts, total: counts.reduce((sum, count) => sum + count, 0), order, tables };
}
