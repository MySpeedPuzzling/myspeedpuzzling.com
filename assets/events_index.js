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
 * An archive line's day(s): "3 Mar", "10–11 Oct", "30 Oct – 2 Nov 2025", "2025年10月10日–11日" - in the page's
 * language, like the server writes them (EventsPageDates::range() with MMMd / yMMMd).
 */
export function formatDays(entry, locale, withYear = false) {
    if (!entry?.f) {
        return '';
    }

    return formatDayRange(entry.f, entry.t || null, locale, withYear ? 'yMMMd' : 'MMMd');
}

// The ICU skeletons of EventsPageDates as Intl.DateTimeFormat options - same skeleton, same text on both sides
const SKELETONS = {
    yMMMM: { year: 'numeric', month: 'long' },
    yMMM: { year: 'numeric', month: 'short' },
    MMM: { month: 'short' },
    MMMd: { month: 'short', day: 'numeric' },
    yMMMd: { year: 'numeric', month: 'short', day: 'numeric' },
};

const FIELDS = { year: 'y', relatedYear: 'y', month: 'M', day: 'd', weekday: 'E' };

/**
 * The locale the events pages write dates in (EventsPageDates::dateLocale()): the page's, English as en-GB.
 */
export function dateLocale(lang) {
    const locale = String(lang || '').replace('_', '-');

    return locale === 'en' ? 'en-GB' : (locale || undefined);
}

function dateParts(iso, locale, skeleton) {
    const options = SKELETONS[skeleton] ?? SKELETONS.yMMMd;

    return new Intl.DateTimeFormat(locale || undefined, { ...options, timeZone: 'UTC' })
        .formatToParts(new Date(`${iso}T00:00:00Z`))
        .map((part) => ({ value: part.value, field: FIELDS[part.type] ?? (part.type === 'literal' ? null : part.type) }));
}

const joinParts = (parts) => parts.map((part) => part.value).join('').trim();

/**
 * One day (or month) from an ICU skeleton of SKELETONS, read in UTC: formatDate('2026-10-01', 'cs', 'yMMMM') = "říjen
 * 2026"
 */
export function formatDate(iso, locale, skeleton) {
    try {
        return joinParts(dateParts(iso, locale, skeleton));
    } catch {
        return String(iso);
    }
}

/**
 * Two days (or months) as one compact range - EventsPageDates::range(), rule for rule: what they share is written once,
 * on the side the locale puts it. Only the fields of the skeleton count.
 */
export function formatDayRange(fromIso, toIso, locale, skeleton) {
    try {
        const from = dateParts(fromIso, locale, skeleton);
        const full = joinParts(from);

        if (!toIso) {
            return full;
        }

        const fields = from.map((part) => part.field).filter((field) => field !== null);
        const has = (field) => fields.includes(field);
        const sameYear = fromIso.slice(0, 4) === toIso.slice(0, 4);
        const sameMonth = fromIso.slice(0, 7) === toIso.slice(0, 7);
        const sameDay = fromIso.slice(0, 10) === toIso.slice(0, 10);

        if ((sameDay || !has('d')) && (sameMonth || !has('M')) && (sameYear || !has('y'))) {
            return full;
        }

        const to = dateParts(toIso, locale, skeleton);
        const fullTo = joinParts(to);

        if (!has('E')) {
            const first = fields[0];
            const last = fields[fields.length - 1];

            if (has('d') && sameMonth) {
                if (first === 'd') {
                    return `${joinParts(segment(from, 'd'))}–${fullTo}`;
                }

                if (last === 'd') {
                    return `${full}–${joinParts(segment(to, 'd'))}`;
                }
            }

            if (has('y') && sameYear) {
                if (last === 'y') {
                    return joined(joinParts(without(from, 'y')), fullTo);
                }

                if (first === 'y') {
                    return joined(full, joinParts(without(to, 'y')));
                }
            }
        }

        return joined(full, fullTo);
    } catch {
        return toIso ? `${fromIso} – ${toIso}` : String(fromIso);
    }
}

const joined = (from, to) => (/[\s\p{Zs}]/u.test(from + to) ? `${from} – ${to}` : `${from}–${to}`);

// A field with the literals that follow it, up to the next field
function segment(parts, field) {
    const start = parts.findIndex((part) => part.field === field);
    const result = [];

    for (let i = start; i >= 0 && i < parts.length; i++) {
        if (i > start && parts[i].field !== null) {
            break;
        }

        result.push(parts[i]);
    }

    return result;
}

// The parts without their last (or first) field and the literals between it and the rest; a dot closing the field
// before stays ("d. M." of "d. M. y")
function without(parts, field) {
    const indexes = parts.map((part, index) => (part.field !== null ? index : -1)).filter((index) => index >= 0);
    const lastIndex = indexes[indexes.length - 1];

    if (parts[lastIndex]?.field === field) {
        const kept = parts.slice(0, lastIndex);
        let dot = '';

        while (kept.length > 0 && kept[kept.length - 1].field === null) {
            dot = kept.pop().value.startsWith('.') ? '.' : '';
        }

        return [...kept, ...(dot ? [{ value: dot, field: null }] : []), ...parts.slice(lastIndex + 1)];
    }

    const firstIndex = indexes[0];

    if (parts[firstIndex]?.field === field) {
        const rest = parts.slice(firstIndex + 1);

        while (rest.length > 0 && rest[0].field === null) {
            rest.shift();
        }

        return [...parts.slice(0, firstIndex), ...rest];
    }

    return parts;
}

function isoDate(year, month0, day) {
    return `${String(year).padStart(4, '0')}-${String(month0 + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
}
