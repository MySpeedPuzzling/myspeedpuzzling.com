// Runs assets/events_index.js (the events page index helpers) for what tests/EventsIndexScriptTest.php hands over on
// stdin - scope checks, typed queries against entries, day and month checks - and prints the results as JSON.

import { readFileSync } from 'node:fs';
import { createQueryMatcher, occursOn, overlapsMonth, scopeMatches, formatDays } from '../assets/events_index.js';

const input = JSON.parse(readFileSync(0, 'utf8'));

process.stdout.write(JSON.stringify({
    scopes: input.scopes.map(({ scopeKey, scope }) => scopeMatches(scopeKey, scope)),
    queries: input.queries.map(({ query, entry }) => createQueryMatcher(query)(entry)),
    days: input.days.map(({ entry, day }) => occursOn(entry, day)),
    months: input.months.map(({ entry, year, month0 }) => overlapsMonth(entry, year, month0)),
    formatted: input.formatted.map(({ entry, locale, withYear }) => formatDays(entry, locale, withYear)),
}));
