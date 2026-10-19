<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\Insights;

use SpeedPuzzling\Web\Value\RatingMoveKind;

/**
 * One step that would raise the MSP rating, with what it is worth if solved in the expected time.
 */
readonly final class RatingMove
{
    public function __construct(
        public RatingMoveKind $kind,
        public InsightsPuzzleRef $puzzle,
        /** The prediction for the attempt (personal for a re-solve, statistical for a first try) */
        public int $expectedSeconds,
        /** Re-solve / defend: today's best time */
        public null|int $currentBestSeconds,
        /** Rating points as the ladder shows them (rating × 1000) the move adds alone */
        public float $gain,
        /** gain ÷ expected hours */
        public float $gainPerHour,
        /** Defend: days until the entry leaves the 24-month window */
        public null|int $daysUntilLeaving,
        /** Public first-attempt solvers of the puzzle (puzzle_rating_times) */
        public int $solverCount,
        /** First try: the share of its solvers (first attempts) the expected time beats, 0-1 */
        public null|float $fasterThanShare,
        public bool $onShelf,
        /** First try: the puzzle has 19 public first-attempt solvers - this first try makes it count in ratings */
        public bool $makesPuzzleCount,
    ) {
    }
}
