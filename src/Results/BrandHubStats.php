<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

readonly final class BrandHubStats
{
    /**
     * Thin-page guardrail: brands below these thresholds render with
     * noindex and are excluded from the brands sitemap.
     */
    public const int MIN_INDEXABLE_PUZZLES = 3;

    /**
     * @param list<PiecesMedian> $piecesMedians Median solo time per piece count (most-solved buckets first)
     */
    public function __construct(
        public string $brandId,
        public string $brandName,
        public string $slug,
        public bool $approved,
        public int $puzzlesCount,
        public int $solvesCount,
        public null|int $medianSeconds,
        public array $piecesMedians,
    ) {
    }

    public function isIndexable(): bool
    {
        return $this->approved
            && $this->puzzlesCount >= self::MIN_INDEXABLE_PUZZLES
            && $this->solvesCount > 0;
    }
}
