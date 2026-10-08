// Runs assets/events_index.js (the events page index helpers) for what tests/EventsIndexScriptTest.php hands over on
// stdin - scope checks, typed queries against entries, day and month checks, dates, the detail pages' times - and prints
// the results as JSON.

import { readFileSync } from 'node:fs';
import { createQueryMatcher, occursOn, overlapsMonth, scopeMatches, formatDays, formatDate, formatDayRange, dateLocale, formatTime, zoneLabel, visitorTime } from '../assets/events_index.js';

const input = JSON.parse(readFileSync(0, 'utf8'));

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
}));
