<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\DifficultyTier;

readonly final class PuzzleListInsight
{
    public function __construct(
        public int $solvedTimes,
        public null|DifficultyTier $difficultyTier,
    ) {
    }
}
