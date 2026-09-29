<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

readonly final class PiecesHubBrand
{
    /**
     * @param int $solvesCount Recorded solves of this brand's puzzles with the hub's piece count
     * @param int $puzzlesCount Visible puzzles of this brand with the hub's piece count
     * @param int $brandPuzzlesCount Visible puzzles of this brand in total
     */
    public function __construct(
        public string $brandName,
        public string $slug,
        public int $solvesCount,
        public int $puzzlesCount,
        public int $brandPuzzlesCount,
    ) {
    }

    /**
     * Whether the brand × pieces page of this brand and piece count is indexable.
     * The hub only lists approved brands, and the brand's own solves are at least
     * the solves of this piece count - so the brand hub rule gets exact inputs.
     */
    public function hasIndexableBrandPiecesPage(): bool
    {
        return BrandPiecesHubStats::isIndexableCombination(
            BrandHubStats::isIndexableBrand(true, $this->brandPuzzlesCount, $this->solvesCount),
            $this->puzzlesCount,
            $this->solvesCount,
        );
    }
}
