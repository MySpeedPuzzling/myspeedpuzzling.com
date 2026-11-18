/**
 * "My tables" on a judge's phone (competition toolkit v2 J3 - design-v2/judge-speed.md §2.2): the tables a judge looks
 * after, typed once per event ("1-20, 41-45"), put first on Find's lists - never hiding anything:
 * - the To-do list: my tables' entries with no result shown (the server's or this device's waiting one), table order;
 * - Recent: my tables only, the others counted ("+ 4 at other tables", one tap shows every one);
 * - "Proposals waiting" and "Puzzle missing" count every entry and list mine first, then "Other tables" (D-4);
 * - typed search, the unique-table open, scans and landings never look at it (a judge helps a neighbour).
 * Entries without a table number are never mine. Only rounds with table numbers use it.
 *
 * Stored in the phone's preferences (live_entry_draft.js, `myTables`): per event `{text, on, savedAt}`, the newest
 * 5 events kept (D-9) - a filter of last weekend's event never applies to this one.
 *
 * Pure functions - tests/live-results-harness.mjs and tests/live-entry-offline-harness.mjs run them under node.
 */

import { recentEntries, shownValue } from './official_results_live.js';

export const MAX_TABLE = 9999;
export const MAX_RANGES = 20;
export const MAX_EVENTS = 5;
export const MAX_TEXT = 200;

/**
 * "1-20, 41-45" → `{ranges: [[1, 20], [41, 45]], text: '1-20, 41-45'}` (sorted, overlapping and adjacent ranges
 * merged, the text normalised); en/em dashes, commas, semicolons and spaces all work. An empty text = no tables.
 * Else `{error}`: `syntax` (not numbers, a reversed range), `range` (outside 1-9999), `too_many` (over 20 ranges).
 */
export function parseTables(text) {
    const source = String(text ?? '').trim();

    if (source === '') {
        return { ranges: [], text: '' };
    }

    if (source.length > MAX_TEXT) {
        return { error: 'too_many' };
    }

    const parsed = [];

    for (const token of source.replace(/\s*[-–—]\s*/g, '-').split(/[\s,;]+/)) {
        if (token === '') {
            continue;
        }

        const match = /^(\d{1,6})(?:-(\d{1,6}))?$/.exec(token);

        if (match === null) {
            return { error: 'syntax' };
        }

        const from = Number(match[1]);
        const to = match[2] === undefined ? from : Number(match[2]);

        if (from < 1 || to < 1 || from > MAX_TABLE || to > MAX_TABLE) {
            return { error: 'range' };
        }

        if (to < from) {
            return { error: 'syntax' };
        }

        parsed.push([from, to]);
    }

    if (parsed.length === 0) {
        return { error: 'syntax' };
    }

    parsed.sort((a, b) => a[0] - b[0]);
    const ranges = [];

    for (const [from, to] of parsed) {
        const last = ranges[ranges.length - 1];

        if (last !== undefined && from <= last[1] + 1) {
            last[1] = Math.max(last[1], to);
        } else {
            ranges.push([from, to]);
        }
    }

    if (ranges.length > MAX_RANGES) {
        return { error: 'too_many' };
    }

    return { ranges, text: ranges.map(([from, to]) => (from === to ? String(from) : `${from}-${to}`)).join(', ') };
}

export function isMine(ranges, tableNumber) {
    if (!Number.isInteger(tableNumber)) {
        return false;
    }

    for (const [from, to] of ranges) {
        if (tableNumber >= from && tableNumber <= to) {
            return true;
        }
    }

    return false;
}

/**
 * Rows (anything with an `entry`) split into mine and the others, each in its given order.
 */
export function splitMine(rows, ranges) {
    const mine = [];
    const others = [];

    for (const row of rows) {
        (isMine(ranges, row.entry?.tableNumber) ? mine : others).push(row);
    }

    return { mine, others };
}

/**
 * My tables' entries with no result shown (shownValue(): this device's latest unsent value wins over the server's) -
 * in table order, at most `limit`; `count` = all of them.
 *
 * @param {Map<string, object[]>} byEntry outbox items per entry ref (outboxByEntry())
 */
export function todo(entries, ranges, byEntry, limit = 30) {
    const open = [];

    for (const entry of entries) {
        if (!isMine(ranges, entry.tableNumber)) {
            continue;
        }

        const value = shownValue(entry, 'result', byEntry.get(entry.ref) ?? []).value;

        if (value === null || value === undefined) {
            open.push(entry);
        }
    }

    open.sort((a, b) => a.tableNumber - b.tableNumber || (a.ref < b.ref ? -1 : (a.ref > b.ref ? 1 : 0)));

    return { entries: open.slice(0, limit), count: open.length };
}

/**
 * Recent entries of my tables (at most `limit`), and how many of the round's usual recent list (the latest `limit` of
 * every table - what a tap on "+ N at other tables" shows) are at other tables.
 */
export function recentMine(entries, outboxItems, ranges, limit = 15) {
    const all = recentEntries(entries, outboxItems, Infinity);
    const mine = [];
    let others = 0;

    for (let i = 0; i < all.length && (mine.length < limit || i < limit); i += 1) {
        if (isMine(ranges, all[i].entry.tableNumber)) {
            if (mine.length < limit) {
                mine.push(all[i]);
            }
        } else if (i < limit) {
            others += 1;
        }
    }

    return { mine, others };
}

function normaliseOne(value) {
    if (value === null || typeof value !== 'object' || Array.isArray(value) || typeof value.text !== 'string' || typeof value.savedAt !== 'number' || !Number.isFinite(value.savedAt)) {
        return null;
    }

    const parsed = parseTables(value.text);

    if (parsed.error !== undefined || parsed.ranges.length === 0) {
        return null;
    }

    return { text: parsed.text, on: value.on !== false, savedAt: value.savedAt };
}

/**
 * The stored `myTables` preference, checked: `{[competitionId]: {text, on, savedAt}}`, the newest 5 events. Anything
 * else (the never written list of the first version, a hand edit) → `{}`.
 */
export function normaliseMyTables(stored) {
    if (stored === null || typeof stored !== 'object' || Array.isArray(stored)) {
        return {};
    }

    const kept = [];

    for (const [competitionId, value] of Object.entries(stored)) {
        const one = competitionId !== '' && competitionId.length <= 64 ? normaliseOne(value) : null;

        if (one !== null) {
            kept.push([competitionId, one]);
        }
    }

    return Object.fromEntries(kept.sort((a, b) => b[1].savedAt - a[1].savedAt).slice(0, MAX_EVENTS));
}

/**
 * The preference with this event's tables changed (`{text, on}`; an empty text forgets the event) - the newest 5 kept.
 */
export function withMyTables(stored, competitionId, { text, on = true }, now) {
    const all = { ...normaliseMyTables(stored) };
    delete all[competitionId];

    const parsed = parseTables(text);

    if (parsed.error === undefined && parsed.ranges.length > 0) {
        all[competitionId] = { text: parsed.text, on: on !== false, savedAt: now };
    }

    return normaliseMyTables(all);
}

/**
 * This event's tables: `{text, on, ranges}`, or null (none set, no event).
 */
export function myTablesOf(prefs, competitionId) {
    const one = typeof competitionId === 'string' ? normaliseMyTables(prefs?.myTables)[competitionId] : undefined;

    return one === undefined ? null : { text: one.text, on: one.on, ranges: parseTables(one.text).ranges };
}
