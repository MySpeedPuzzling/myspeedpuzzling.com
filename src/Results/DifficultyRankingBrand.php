<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * A brand with enough rated puzzles for its own hardest / easiest lists.
 */
readonly final class DifficultyRankingBrand
{
    public function __construct(
        public string $brandId,
        public string $brandName,
        public string $slug,
        public int $ratedPuzzlesCount,
    ) {
    }
}
