<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\EventsPage;

readonly final class EventsArchivePage
{
    /**
     * @param list<ArchiveLine> $lines newest first
     * @param list<int> $years every year with past public occurrences, newest first
     * @param list<string> $itemListUrls every occurrence of the year (rolled-up editions included)
     */
    public function __construct(
        public int $year,
        public array $lines,
        public array $years,
        public array $itemListUrls,
        // occurrences of the year
        public int $count,
    ) {
    }
}
