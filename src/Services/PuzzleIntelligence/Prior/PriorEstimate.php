<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PuzzleIntelligence\Prior;

use SpeedPuzzling\Web\Value\DifficultySource;

/**
 * A prior guess of a puzzle's log difficulty (0.0 = the average puzzle) and what a prediction resting on it alone
 * reports as its difficulty source.
 */
readonly final class PriorEstimate
{
    public function __construct(
        public float $logDifficulty,
        public string $name,
        public DifficultySource $sourceWithoutSolves = DifficultySource::Shrunk,
    ) {
    }
}
