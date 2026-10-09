<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\EventDetail;

use DateTimeImmutable;
use SpeedPuzzling\Web\Results\EventsPage\ArchiveLine;

/**
 * One month of a past year on the series page of a series with many sessions (SeriesPage::$filter set,
 * docs/features/events-page/high-frequency-series.md "Series page for 200+ editions"): a `<details>` section, only the
 * newest month of each year open.
 */
readonly final class SeriesPastMonth
{
    /**
     * @param list<ArchiveLine> $lines newest first
     */
    public function __construct(
        // the first day of the month, 00:00 UTC
        public DateTimeImmutable $firstDay,
        public array $lines,
        public bool $open,
    ) {
    }

    /**
     * Y-m - the filter's month value (`data-month`)
     */
    public function key(): string
    {
        return $this->firstDay->format('Y-m');
    }
}
