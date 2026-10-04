<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\DifficultyTier;

/**
 * A rated puzzle's tier (the icon) and score (the order - finer than the tier, which is a band of it).
 */
readonly final class PuzzleDifficultyRating
{
    public function __construct(
        public DifficultyTier $tier,
        public float $score,
    ) {
    }
}
