<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;

readonly final class ActivityCalendarDay
{
    public function __construct(
        public DateTimeImmutable $date,
        public int $soloCount,
        public int $duoCount,
        public int $teamCount,
        public int $firstAttemptCount,
        public int $totalSeconds,
    ) {
    }

    public function totalCount(): int
    {
        return $this->soloCount + $this->duoCount + $this->teamCount;
    }
}
