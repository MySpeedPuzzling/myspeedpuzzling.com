<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\Insights;

/**
 * A puzzle you improved on most: gain = 1 − best ÷ first.
 */
readonly final class InsightsLearnedPuzzle
{
    public function __construct(
        public string $puzzleId,
        public int $attempts,
        public int $firstSeconds,
        public int $bestSeconds,
        public float $gain,
    ) {
    }
}
