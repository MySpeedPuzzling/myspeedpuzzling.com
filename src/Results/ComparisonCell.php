<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\ComparisonSubjectRef;

/**
 * One subject's time on one compared puzzle (ComparisonBuilder). `seconds` / `timeId` / `day` are the compared time:
 * the best one, or the first try when only first tries are compared. The time id opens the `puzzle_result_detail`
 * modal (all attempts).
 */
readonly final class ComparisonCell
{
    public function __construct(
        public ComparisonSubjectRef $subject,
        public int $seconds,
        public string $timeId,
        public DateTimeImmutable $day,
        // Times within the filters ("best of N")
        public int $attempts,
        public int $bestSeconds,
        public string $bestTimeId,
        public null|int $firstTrySeconds,
        public null|string $firstTryTimeId,
        // 1 = fastest; equal times share a rank (1, 1, 3)
        public int $rank,
        // Behind the fastest time of the puzzle: seconds, and as a ratio (0.12 = 12 % slower); 0 for the fastest
        public int $deltaSeconds,
        public float $deltaRatio,
    ) {
    }

    public function isFastest(): bool
    {
        return $this->rank === 1;
    }

    /**
     * The "1st try" badge: the compared time is the subject's first try on the puzzle.
     */
    public function isFirstTry(): bool
    {
        return $this->firstTryTimeId !== null && $this->firstTryTimeId === $this->timeId;
    }
}
