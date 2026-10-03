<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\ComparisonSubjectRef;

/**
 * One subject's results on one puzzle within the comparison filters (GetComparisonResults). Times outside the period /
 * not first tries (when only first tries are compared) were dropped before aggregating, so "best" is the best within the
 * filters and `attempts` counts the filtered times only.
 */
readonly final class ComparisonTimeRow
{
    public function __construct(
        public ComparisonSubjectRef $subject,
        public string $puzzleId,
        public int $piecesCount,
        public int $attempts,
        public int $bestSeconds,
        public string $bestTimeId,
        // COALESCE(finished_at, tracked_at) of the best time
        public DateTimeImmutable $bestDay,
        // The earliest time flagged as a first try; null = none
        public null|int $firstTrySeconds,
        public null|string $firstTryTimeId,
        public null|DateTimeImmutable $firstTryDay,
        // Only when the criteria need it (a member filtering / sorting by difficulty), else always null
        public null|int $difficultyTier = null,
        // Only when asked for (sort by name, charts), else always null
        public null|string $puzzleName = null,
    ) {
    }
}
