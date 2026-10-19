<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\Insights;

/**
 * A puzzle on the player's shelf (in one of their collections - the system one included - or borrowed, and not lent
 * out) that they have no result on yet, of any kind (GetInsightsShelf::unsolved()). Never a secret puzzle.
 */
readonly final class ShelfPuzzle
{
    public function __construct(
        public InsightsPuzzleRef $puzzle,
        /** Difficulty score when rated with more than insufficient confidence */
        public null|float $difficulty,
        public null|int $difficultyTier,
        public null|int $medianSoloSeconds,
        public int $soloResultsCount,
    ) {
    }
}
