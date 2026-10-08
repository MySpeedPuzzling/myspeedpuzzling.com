<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\EventsPage;

/**
 * "21 upcoming dates in 8 countries and online · 15 series" - public items only.
 */
readonly final class EventsSummary
{
    public function __construct(
        // live + upcoming dates
        public int $upcomingDates,
        // countries with live or upcoming in-person dates
        public int $countries,
        public bool $hasOnline,
        public int $series,
    ) {
    }
}
