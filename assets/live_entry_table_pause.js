/**
 * A typed table name opens after a short pause (SPWS demo UX leftovers, docs/features/competitions-management/
 * spws-demo-ux-leftovers.md §2). On a round with table names a typed `a1` waits while A10 … A19 continue it - no
 * instant open (official_results_live.js uniqueTable(), C7). When the judge stops typing for TABLE_PAUSE_MS and the
 * text is exactly one entry's table name, that entry opens - typing on to `a10` in time still works (every keystroke
 * re-arms the pause).
 *
 * Only rounds with table names: plain numbers keep C7 as it is (an exact number nothing continues opens at once, else
 * Enter or a tap). Text without a digit never opens (names). Two entries at the same table: none.
 *
 * Pure functions - tests/live-entry-table-pause-harness.mjs runs them under node; the controller owns the timer.
 */

import { key as labelKey } from './table_labels.js';

export const TABLE_PAUSE_MS = 700;

/**
 * The entry to open once the typing paused, or null.
 *
 * @param {object[]} index buildSearchIndex()'s items ({entry, labelKey})
 */
export function pausedTable(index, query, { tableNumbersOff = false, labels = null } = {}) {
    const text = String(query ?? '').trim();

    // Text ending in a separator ("a1-", "a1 " is trimmed) is a name still being typed (A1-1 / A1-2)
    if (labels === null || tableNumbersOff || !/\d/.test(text) || !/[A-Za-z0-9]$/.test(text)) {
        return null;
    }

    const typedKey = labelKey(text);

    if (typedKey === '') {
        return null;
    }

    let found = null;

    for (const item of index) {
        if (item.labelKey !== null && item.labelKey === typedKey) {
            if (found !== null) {
                return null;
            }

            found = item.entry;
        }
    }

    return found;
}

/**
 * Whether a pause timer that fired may still open: the find view is shown and the box holds the text it was armed for.
 */
export function pauseStillValid(armedQuery, currentQuery, view, stillTyping = true) {
    // stillTyping = the find box still has the focus (no scanner, menu or other field took over meanwhile)
    return view === 'find' && stillTyping === true && typeof armedQuery === 'string' && armedQuery.trim() !== '' && armedQuery === currentQuery;
}
