// The month grid of the events page calendar (docs/features/events-page/README.md, "Calendar view") - owner:
// workstream B. Pure functions over the page's index (assets/events_index.js), so the calendar view and the desktop
// side calendar (controllers/events_calendar_controller.js) build their months the same way. Pinned by
// tests/EventsCalendarScriptTest.php, which runs this module under node.
//
// Days are `Y-m-d` strings read in UTC, like the server writes them (the event's own local dates). Weeks start on
// Monday.

import { createQueryMatcher, occursOn, overlapsMonth, scopeMatches } from './events_index.js';

export const KIND_IN_PERSON = 'in_person';
export const KIND_ONLINE = 'online';
export const KIND_PAST = 'past';

// Dot order in a day cell, and the side calendar's priority (in person wins over online over past)
const KIND_ORDER = [KIND_IN_PERSON, KIND_ONLINE, KIND_PAST];

/**
 * `2026-11` → `{year: 2026, month0: 10}`; anything else → null.
 */
export function parseMonth(value) {
    const match = /^(\d{4})-(\d{2})$/.exec(String(value ?? ''));

    if (match === null) {
        return null;
    }

    const month0 = Number(match[2]) - 1;

    return month0 >= 0 && month0 <= 11 ? { year: Number(match[1]), month0 } : null;
}

export function monthKey({ year, month0 }) {
    return `${String(year).padStart(4, '0')}-${String(month0 + 1).padStart(2, '0')}`;
}

/**
 * The month `delta` months after `month` (negative = before).
 */
export function shiftMonth({ year, month0 }, delta) {
    const total = year * 12 + month0 + delta;

    return { year: Math.floor(total / 12), month0: ((total % 12) + 12) % 12 };
}

export function isoDay(year, month0, day) {
    return `${String(year).padStart(4, '0')}-${String(month0 + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
}

/**
 * Coral in person, blue online, grey past - what a dot (and a name's border) of an entry says.
 */
export function entryKind(entry) {
    if (entry?.st === 'past') {
        return KIND_PAST;
    }

    return entry?.sc === 'online' ? KIND_ONLINE : KIND_IN_PERSON;
}

/**
 * The one kind a day of the side calendar shows: in person wins over online over past.
 */
export function strongestKind(kinds) {
    return KIND_ORDER.find((kind) => kinds.includes(kind)) ?? null;
}

/**
 * The dated occurrences (one-time events and editions, no series) in `scope` that match what was typed.
 */
export function calendarEntries(index, { scope = 'all', query = '' } = {}) {
    const matches = createQueryMatcher(query);

    return (Array.isArray(index) ? index : [])
        .filter((entry) => entry && entry.k !== 's' && entry.f && scopeMatches(entry.sc, scope) && matches(entry));
}

/**
 * Everything one month of the calendar shows:
 * - `lead`: blank cells before the 1st (Monday first);
 * - `days`: every day with its `iso`, `day`, `isToday`, the entries on it (`entries`, long-running ones left out) and
 *   their `kinds` (in person, online, past - each once, in that order);
 * - `runs`: long-running entries overlapping the month (a bar under the grid instead of a dot on every day), with
 *   `startsBefore` / `endsAfter` the month;
 * - `items`: every entry overlapping the month, by first day, then name - the month's rows. Long-running ones that
 *   started in an earlier month come last: they are already the bar above, and would otherwise lead every month.
 */
export function buildMonth(entries, { year, month0 }, today = '') {
    const daysIn = new Date(Date.UTC(year, month0 + 1, 0)).getUTCDate();
    const lead = (new Date(Date.UTC(year, month0, 1)).getUTCDay() + 6) % 7;
    const first = isoDay(year, month0, 1);
    const last = isoDay(year, month0, daysIn);
    const inMonth = entries.filter((entry) => overlapsMonth(entry, year, month0)).sort(byDate);
    const carriedOver = (entry) => entry.lr && entry.f < first;
    const items = [...inMonth.filter((entry) => !carriedOver(entry)), ...inMonth.filter(carriedOver)];
    const runs = inMonth
        .filter((entry) => entry.lr)
        .map((entry) => ({ entry, kind: entryKind(entry), startsBefore: entry.f < first, endsAfter: (entry.t || entry.f) > last }));
    const dayEntries = items.filter((entry) => !entry.lr);
    const days = [];

    for (let day = 1; day <= daysIn; day++) {
        const iso = isoDay(year, month0, day);
        const onDay = dayEntries.filter((entry) => occursOn(entry, iso));
        const kinds = KIND_ORDER.filter((kind) => onDay.some((entry) => entryKind(entry) === kind));

        days.push({ day, iso, isToday: iso === today, entries: onDay, kinds });
    }

    return { lead, days, runs, items };
}

/**
 * The index ids of the occurrences a day picked in the calendar points at: every entry on that day except the
 * long-running ones (those are bars, not dots).
 */
export function idsOnDay(entries, iso) {
    return entries.filter((entry) => !entry.lr && occursOn(entry, iso)).map((entry) => entry.id);
}

/**
 * Which "from …, until …" text a long-running bar gets: `all_month` (it started before and ends after this month),
 * `until` (started before, ends in it) or `from` (starts in it).
 */
export function runTextKind(run) {
    if (run.startsBefore && run.endsAfter) {
        return 'all_month';
    }

    return run.startsBefore ? 'until' : 'from';
}

/**
 * The weekday names of a Monday-first week in the page's language (`short`: "Mon", "Mo", "lun.", "月").
 */
export function weekdayNames(locale, width = 'short') {
    const format = new Intl.DateTimeFormat(locale || undefined, { weekday: width, timeZone: 'UTC' });

    // 2024-01-01 was a Monday
    return Array.from({ length: 7 }, (_, offset) => format.format(new Date(Date.UTC(2024, 0, 1 + offset))));
}

/**
 * Formats a `Y-m-d` day (or a month's first day) in the page's language, read in UTC.
 */
export function formatIso(iso, locale, options) {
    return new Intl.DateTimeFormat(locale || undefined, { ...options, timeZone: 'UTC' }).format(new Date(`${iso}T00:00:00Z`));
}

function byDate(a, b) {
    return String(a.f).localeCompare(String(b.f)) || String(a.n ?? '').localeCompare(String(b.n ?? '')) || (a.id ?? 0) - (b.id ?? 0);
}
