<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\EventDetail;

use DateTimeImmutable;

/**
 * The series page's filter bar (series/_filters.html.twig + series_filter_controller.js, docs/features/events-page/
 * high-frequency-series.md "Series page for 200+ editions") - only for a series with many sessions
 * (SeriesPageBuilder::FILTER_FROM_SESSIONS). Client-side only: the rows carry what it reads (SeriesRowDetails), without
 * JavaScript the bar stays hidden and every row shows. Its category chips are SeriesPage::$categories.
 */
readonly final class SeriesFilter
{
    /**
     * @param list<DateTimeImmutable> $upcomingMonths first days of the months of the upcoming list, soonest first
     * @param list<DateTimeImmutable> $pastMonths first days of the months with past sessions, newest first
     */
    public function __construct(
        public array $upcomingMonths,
        public array $pastMonths,
    ) {
    }
}
