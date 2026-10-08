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

    public function occurrenceCount(): int
    {
        $count = 0;

        foreach ($this->lines as $line) {
            $count += $line->editionCount;
        }

        return $count;
    }
}
