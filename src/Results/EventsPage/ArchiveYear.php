<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\EventsPage;

readonly final class ArchiveYear
{
    /**
     * @param list<ArchiveLine> $lines newest first
     */
    public function __construct(
        public int $year,
        public array $lines,
    ) {
    }

    /**
     * The events held that year - one-time events and editions, the sessions of one counted once (like the series
     * directory's edition counts; month headers count dates instead)
     */
    public function occurrenceCount(): int
    {
        $count = 0;

        foreach ($this->lines as $line) {
            $count += $line->editionCount;
        }

        return $count;
    }
}
