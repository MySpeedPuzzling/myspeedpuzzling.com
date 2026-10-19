<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\Insights;

/**
 * puzzle_rating_times of one puzzle: the sorted times the rating ranks its solvers by.
 */
readonly final class PuzzleRatingTimeLists
{
    /**
     * @param list<int> $firstAttemptTimes ascending
     * @param list<int> $bestTimes ascending
     */
    public function __construct(
        public string $puzzleId,
        public bool $qualifies,
        public int $solverCount,
        public null|float $difficultyWeight,
        public array $firstAttemptTimes,
        public array $bestTimes,
    ) {
    }
}
