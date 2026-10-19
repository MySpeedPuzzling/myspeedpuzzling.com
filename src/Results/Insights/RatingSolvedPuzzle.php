<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\Insights;

use DateTimeImmutable;

/**
 * One puzzle of a rated piece count the player has solo results on (all time), with what the rating holds of it -
 * the inputs of the rating what-ifs (GetInsightsRatingInputs::solvedPuzzles()).
 */
readonly final class RatingSolvedPuzzle
{
    public function __construct(
        public string $puzzleId,
        /** null = kept secret now: counts in the rating, never suggested */
        public null|InsightsPuzzleRef $puzzle,
        /** The first-attempt time and moment as the rating takes them (MIN over first-attempt rows) */
        public null|int $firstAttemptSeconds,
        public null|DateTimeImmutable $firstAttemptAt,
        public int $bestSeconds,
        public DateTimeImmutable $latestAt,
        public int $attempts,
        public bool $onShelf,
        /** puzzle_rating_times: null = no row (fewer than 9 public first-attempt solvers, or another piece count) */
        public null|bool $qualifies,
        public null|int $solverCount,
        public null|float $difficultyWeight,
        public null|RatingEntrySnapshot $firstAttemptEntry,
        public null|RatingEntrySnapshot $bestTimeEntry,
    ) {
    }
}
