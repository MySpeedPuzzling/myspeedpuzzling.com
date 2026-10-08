<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\EventDetail;

use SpeedPuzzling\Web\Results\EventsPage\AgendaMonth;
use SpeedPuzzling\Web\Results\EventsPage\AgendaRow;
use SpeedPuzzling\Web\Results\EventsPage\ArchiveYear;
use SpeedPuzzling\Web\Value\FollowTarget;

/**
 * Everything the series page renders (SeriesPageBuilder, docs/features/events-page/detail-pages.md "Series page"):
 * one list of sessions - the Next card, the other live and upcoming ones by month, long spans running now, editions
 * without a date, the past by year.
 */
readonly final class SeriesPage
{
    /**
     * @param list<AgendaMonth> $months live and upcoming occurrences without the Next one, by start (live first)
     * @param list<AgendaRow> $ongoing long spans running now
     * @param list<AgendaRow> $dateNotSet editions without a date and without rounds, by name
     * @param list<ArchiveYear> $pastYears one line per past session, newest year and line first
     * @param list<JsonLdSubEvent> $subEvents public dated occurrences (one per session) for the EventSeries JSON-LD
     */
    public function __construct(
        public null|SeriesNextCard $next,
        public array $months,
        public array $ongoing,
        public array $dateNotSet,
        public array $pastYears,
        public SeriesFacts $facts,
        // null unless the series is public
        public null|FollowTarget $followTarget,
        public bool $following,
        public array $subEvents,
        // the series has an occurrence at all
        private bool $hasOccurrences,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->hasOccurrences === false;
    }

    /**
     * Live and upcoming rows below the Next card
     */
    public function upcomingCount(): int
    {
        $count = 0;

        foreach ($this->months as $month) {
            $count += count($month->rows);
        }

        return $count;
    }

    public function pastCount(): int
    {
        $count = 0;

        foreach ($this->pastYears as $year) {
            $count += count($year->lines);
        }

        return $count;
    }
}
