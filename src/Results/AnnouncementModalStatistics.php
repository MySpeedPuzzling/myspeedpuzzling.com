<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;

readonly final class AnnouncementModalStatistics
{
    public function __construct(
        public string $modal,
        /** Players the modal was put on a page for - each of them once, ever */
        public int $displayed,
        /** Of those, how many browsers confirmed that it really opened */
        public int $seen,
        public int $displayedLast24Hours,
        public int $displayedLast7Days,
        public null|DateTimeImmutable $firstDisplayedAt,
        public null|DateTimeImmutable $lastDisplayedAt,
    ) {
    }

    public function seenPercent(): null|int
    {
        return $this->displayed > 0 ? (int) round($this->seen / $this->displayed * 100) : null;
    }
}
