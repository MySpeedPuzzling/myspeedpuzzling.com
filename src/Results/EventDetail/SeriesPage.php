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
 * without a date, the past by year. A series with many sessions also gets the filter bar and its past years in month
 * sections (docs/features/events-page/high-frequency-series.md "Series page for 200+ editions").
 */
readonly final class SeriesPage
{
    /**
     * @param list<AgendaMonth> $months live and upcoming occurrences without the Next one, by start (live first)
     * @param list<AgendaRow> $ongoing long spans running now
     * @param list<AgendaRow> $dateNotSet editions without a date and without rounds, by name
     * @param list<ArchiveYear> $pastYears one line per past session, newest year and line first
     * @param list<JsonLdSubEvent> $subEvents public dated occurrences (one per session) for the EventSeries JSON-LD - at
     *     most SeriesPageBuilder::MAX_JSON_LD_SUB_EVENTS: every coming one, then the newest past ones
     * @param array<int, list<SeriesPastMonth>> $pastMonths with the filter: each past year's months, newest first
     * @param array<int, SeriesRowDetails> $rowDetails by the row's (or line's) index id
     * @param list<string> $categories RoundCategory values that occur in the sessions' rounds (solo, duo, team order)
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
        // the filter bar and the past in month sections - a series with many sessions only
        public null|SeriesFilter $filter = null,
        public array $pastMonths = [],
        // a public edition has started - "Add my time" in the header (docs/features/events-page/high-frequency-series.md P27)
        public bool $hasStartedEdition = false,
        public array $rowDetails = [],
        public array $categories = [],
    ) {
    }

    /**
     * @param list<int> $indexIds a row's or line's ids - the series page has one per row
     */
    public function detailsOf(array $indexIds): null|SeriesRowDetails
    {
        foreach ($indexIds as $id) {
            if (isset($this->rowDetails[$id])) {
                return $this->rowDetails[$id];
            }
        }

        return null;
    }

    /**
     * Two or more categories occur: the category chips of the filter bar and the pills on the rows - one category says
     * nothing
     */
    public function showsCategories(): bool
    {
        return count($this->categories) >= 2;
    }

    /**
     * A past year's month sections (only with the filter)
     *
     * @return list<SeriesPastMonth>
     */
    public function monthsOf(int $year): array
    {
        return $this->pastMonths[$year] ?? [];
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
