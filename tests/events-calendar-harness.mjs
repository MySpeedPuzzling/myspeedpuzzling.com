// Runs assets/events_calendar.js (the events page calendar's month building) for what tests/EventsCalendarScriptTest.php
// hands over on stdin and prints the results as JSON: index entries are reduced to their ids.

import { readFileSync } from 'node:fs';
import {
    buildMonth,
    calendarEntries,
    idsOnDay,
    monthKey,
    parseMonth,
    runTextKind,
    shiftMonth,
    strongestKind,
    weekdayNames,
} from '../assets/events_calendar.js';

const input = JSON.parse(readFileSync(0, 'utf8'));
const ids = (entries) => entries.map((entry) => entry.id);

process.stdout.write(JSON.stringify({
    months: input.months.map(({ index, scope, query, month, today }) => {
        const built = buildMonth(calendarEntries(index, { scope, query }), parseMonth(month), today);

        return {
            lead: built.lead,
            dayCount: built.days.length,
            days: Object.fromEntries(built.days
                .filter((day) => day.entries.length > 0 || day.isToday)
                .map((day) => [day.iso, { ids: ids(day.entries), kinds: day.kinds, today: day.isToday }])),
            runs: built.runs.map((run) => ({ id: run.entry.id, kind: run.kind, text: runTextKind(run) })),
            items: ids(built.items),
        };
    }),
    dayIds: input.dayIds.map(({ index, scope, query, day }) => idsOnDay(calendarEntries(index, { scope, query }), day)),
    shifted: input.shifted.map(({ month, delta }) => monthKey(shiftMonth(parseMonth(month), delta))),
    parsed: input.parsed.map((value) => parseMonth(value)),
    strongest: input.strongest.map((kinds) => strongestKind(kinds)),
    weekdays: input.weekdays.map(({ locale }) => weekdayNames(locale, 'short')),
}));
