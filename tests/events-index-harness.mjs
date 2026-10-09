// Runs assets/events_index.js (the events page index helpers) for what tests/EventsIndexScriptTest.php hands over on
// stdin - scope checks, typed queries against entries, day and month checks, dates, the detail pages' times, rebuilding
// the compact index the page ships and the archive's lines - and prints the results as JSON.

import { readFileSync } from 'node:fs';
import { createQueryMatcher, occursOn, overlapsMonth, scopeMatches, formatDays, formatDate, formatDayRange, dateLocale, formatTime, zoneLabel, visitorTime, expandEventsIndex, archiveLinesOf } from '../assets/events_index.js';

const input = JSON.parse(readFileSync(0, 'utf8'));

// The archive of one year from a shipped index, as the events page builds it (events_page_controller.js)
const archiveOf = ({ index, year }) => archiveLinesOf(expandEventsIndex(index)
    .filter((entry) => entry.st === 'past' && !entry.w && entry.f && Number(entry.f.slice(0, 4)) === year))
    .map((line) => ({ ids: line.entries.map((entry) => entry.id).sort((a, b) => a - b), editions: line.editions, title: line.title, scope: line.scope }));

process.stdout.write(JSON.stringify({
    scopes: input.scopes.map(({ scopeKey, scope }) => scopeMatches(scopeKey, scope)),
    queries: input.queries.map(({ query, entry }) => createQueryMatcher(query)(entry)),
    days: input.days.map(({ entry, day }) => occursOn(entry, day)),
    months: input.months.map(({ entry, year, month0 }) => overlapsMonth(entry, year, month0)),
    formatted: input.formatted.map(({ entry, locale, withYear }) => formatDays(entry, locale, withYear)),
    dates: (input.dates ?? []).map(({ from, to, lang, skeleton }) => (to === undefined
        ? formatDate(from, dateLocale(lang), skeleton)
        : formatDayRange(from, to, dateLocale(lang), skeleton))),
    times: (input.times ?? []).map(({ instant, zone, lang }) => formatTime(instant, zone, dateLocale(lang))),
    zones: (input.zones ?? []).map(({ zone, lang, instant }) => zoneLabel(zone, dateLocale(lang), instant)),
    visitor: (input.visitor ?? []).map(({ instant, eventZone, lang, visitorZone }) => visitorTime(instant, eventZone, dateLocale(lang), visitorZone)),
    expanded: (input.expand ?? []).map((index) => expandEventsIndex(index)),
    searched: (input.search ?? []).map(({ index, query }) => expandEventsIndex(index).filter(createQueryMatcher(query)).map((entry) => entry.id)),
    archives: (input.archive ?? []).map(archiveOf),
    expandedDays: (input.expandedDays ?? []).map(({ index, ids, lang }) => {
        const entries = expandEventsIndex(index);

        return ids.map((id) => formatDays(entries[id], dateLocale(lang)));
    }),
}));
