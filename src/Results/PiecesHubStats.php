<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

readonly final class PiecesHubStats
{
    /**
     * @param list<PiecesHubBrand> $topBrands Brands with the most recorded solves for this piece count
     */
    public function __construct(
        public int $piecesCount,
        public int $puzzlesCount,
        public int $solvesCount,
        public null|int $medianSeconds,
        public array $topBrands,
    ) {
    }
}
