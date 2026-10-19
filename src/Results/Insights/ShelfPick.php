<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\Insights;

use SpeedPuzzling\Web\Value\ShelfPickSource;

/**
 * An unsolved puzzle from the shelf that suits the player: "expect ~44 min, faster than 84 % of its solvers".
 */
readonly final class ShelfPick
{
    public function __construct(
        public InsightsPuzzleRef $puzzle,
        public int $expectedSeconds,
        public int $expectedLowSeconds,
        public int $expectedHighSeconds,
        public ShelfPickSource $source,
        /** The share of its solvers' first attempts the expected time beats, 0-1; null below MINIMUM_SOLVERS */
        public null|float $fasterThanShare,
        public int $solverCount,
        public null|int $difficultyTier,
        public null|int $medianSoloSeconds,
    ) {
    }
}
