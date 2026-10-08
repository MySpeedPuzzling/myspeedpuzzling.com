// The search and calendar index of the events page (docs/features/events-page/implementation-plan.md, 1.5 and 1.7):
// one entry per occurrence and series line, rendered by EventsIndexFactory into
// `<script type="application/json" data-events-index>`. The list page (events_page_controller.js) and the calendar
// (events_calendar_controller.js) read it through this module - nobody else edits it. Pinned by
// tests/EventsIndexScriptTest.php, which runs it under node.
//
// Entry keys: id, k (e = one-time event, d = edition, s = series), n (name; an edition's: the series name), en (an
// edition's own name), sid (an edition's series entry), u (link), f / t (first / last day, Y-m-d; t null for one day),
// lr (long-running), sc (scope key: online / country code / ''), c (country code), p (place label), st (status: live,
// upcoming, past, tba, ongoing; null for a series), r (results), w (waiting for approval), x (folded search text).

import { foldSearchText } from './search_fold.js';

// EventsPageBuilder::LONG_RUN_DAYS - over this many days an occurrence shows only its first day ("Runs until …")
export const LONG_RUN_DAYS = 14;

// Typed abbreviations that also find their full name (already folded)
const ALIASES = {
    wjpc: 'world jigsaw puzzle championship',
    ejpc: 'european jigsaw puzzle championship',
};

/**
 * The index of the page under `root` (an element containing the script, or the document). Never throws: a missing or
 * broken index is an empty list.
 *
 * @returns {Array<Object>}
 */
export function readEventsIndex(root) {
    const script = root?.querySelector?.('script[data-events-index]');

    if (!script) {
        return [];
    }

    try {
        const entries = JSON.parse(script.textContent || '[]');

        return Array.isArray(entries) ? entries : [];
    } catch {
        return [];
    }
}

/**
 * Whether an entry with `scopeKey` (its `sc`) belongs to `scope` (`all`, `online` or a country code). Online entries
 * have the scope key `online` - they count under Online only, never under a country.
 */
export function scopeMatches(scopeKey, scope) {
    if (!scope || scope === 'all') {
        return true;
    }

    return String(scopeKey ?? '') === String(scope);
}

/**
 * A matcher of index entries for what was typed: every typed word must be in the entry's folded search text `x`
 * (folded like the server folds it, SearchText::fold()). `wjpc` and `ejpc` also match their full names. Nothing typed
 * matches everything.
 *
 * @returns {function(Object): boolean}
 */
export function createQueryMatcher(text) {
    const tokens = foldSearchText(text).split(' ').filter((token) => token !== '');

    if (tokens.length === 0) {
        return () => true;
    }

    return (entry) => {
        const haystack = String(entry?.x ?? '');

        return tokens.every((token) => haystack.includes(token)
            || (Object.hasOwn(ALIASES, token) && haystack.includes(ALIASES[token])));
    };
}

/**
 * Whether an entry takes place on `isoDay` (Y-m-d) - any day from its first to its last, long-running ones included.
 * Series and undated entries never do.
 */
export function occursOn(entry, isoDay) {
    if (!entry?.f) {
        return false;
    }

    const to = entry.t || entry.f;

    return entry.f <= isoDay && isoDay <= to;
}

/**
 * Whether an entry takes place on any day of a month (`month0`: 0 = January).
 */
export function overlapsMonth(entry, year, month0) {
    if (!entry?.f) {
        return false;
    }

    const first = isoDate(year, month0, 1);
    const last = isoDate(year, month0, new Date(Date.UTC(year, month0 + 1, 0)).getUTCDate());
    const to = entry.t || entry.f;

    return entry.f <= last && to >= first;
}

/**
 * An archive line for a past entry, cloned from `templateRoot` (the `<template data-events-archive-line-template>`, or
 * a line element) and filled into its `data-slot` elements: date, title, results, place and the link.
 *
 * @param {{locale: string, withYear: boolean, resultsLabel: string, onlineLabel: string}} options
 * @returns {HTMLElement}
 */
export function fillArchiveLine(templateRoot, entry, { locale, withYear = false, resultsLabel = '', onlineLabel = '' } = {}) {
    const source = templateRoot.content ? templateRoot.content.firstElementChild : templateRoot;
    const line = source.cloneNode(true);
    const slot = (name) => line.querySelector(`[data-slot="${name}"]`);

    line.setAttribute('data-ev-ids', String(entry.id));
    line.setAttribute('data-ev-scope', String(entry.sc ?? ''));
    line.hidden = false;

    const date = slot('date');

    if (date) {
        date.textContent = formatDays(entry, locale, withYear);
    }

    const title = slot('title');

    if (title) {
        title.textContent = entry.en ? `${entry.n} · ${entry.en}` : String(entry.n ?? '');
    }

    const link = slot('link');

    if (link) {
        if (entry.u) {
            link.setAttribute('href', entry.u);
        } else {
            link.removeAttribute('href');
        }
    }

    const results = slot('results');

    if (results) {
        results.textContent = entry.r ? resultsLabel : '';
        results.hidden = !entry.r;
    }

    const place = slot('place');

    if (place) {
        place.textContent = entry.sc === 'online' ? onlineLabel : String(entry.p ?? '');
    }

    return line;
}

/**
 * "3 Mar", "10 Oct – 11 Oct", with the year when asked - in the page's language, read in UTC like the server writes
 * the days.
 */
export function formatDays(entry, locale, withYear = false) {
    if (!entry?.f) {
        return '';
    }

    const options = { day: 'numeric', month: 'short', timeZone: 'UTC' };

    if (withYear) {
        options.year = 'numeric';
    }

    const format = new Intl.DateTimeFormat(locale || undefined, options);
    const from = new Date(`${entry.f}T00:00:00Z`);

    if (!entry.t || entry.t === entry.f) {
        return format.format(from);
    }

    const to = new Date(`${entry.t}T00:00:00Z`);

    return typeof format.formatRange === 'function' ? format.formatRange(from, to) : `${format.format(from)} – ${format.format(to)}`;
}

function isoDate(year, month0, day) {
    return `${String(year).padStart(4, '0')}-${String(month0 + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
}
