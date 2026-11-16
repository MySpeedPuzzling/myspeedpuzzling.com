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
        // Unfinished competition results of the day (docs/features/competitions-management/unfinished-results-plan.md):
        // no solve, but the day is active - the cell marks them apart from the solves
        public int $unfinishedCount = 0,
    ) {
    }

    public function totalCount(): int
    {
        return $this->soloCount + $this->duoCount + $this->teamCount;
    }
}
