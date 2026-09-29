<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

readonly final class BrandHubStats
{
    /**
     * Thin-page guardrail: brands below these thresholds render with
     * noindex and are excluded from the brands sitemap and the brand directory.
     */
    public const int MIN_INDEXABLE_PUZZLES = 3;

    /**
     * @param list<PiecesMedian> $piecesMedians Median solo time per piece count (most-solved buckets first)
     * @param list<BrandPiecesHubStats> $piecesPages Brand × pieces pages: allowed piece counts with at least one visible puzzle, ascending
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
        public array $piecesPages,
    ) {
    }

    public function isIndexable(): bool
    {
        return self::isIndexableBrand($this->approved, $this->puzzlesCount, $this->solvesCount);
    }

    /**
     * The one rule for brand hubs - the directory and the sitemap apply it too.
     */
    public static function isIndexableBrand(bool $approved, int $puzzlesCount, int $solvesCount): bool
    {
        return $approved
            && $puzzlesCount >= self::MIN_INDEXABLE_PUZZLES
            && $solvesCount > 0;
    }

    public function piecesPage(int $piecesCount): null|BrandPiecesHubStats
    {
        foreach ($this->piecesPages as $piecesPage) {
            if ($piecesPage->piecesCount === $piecesCount) {
                return $piecesPage;
            }
        }

        return null;
    }

    public function hasIndexablePiecesPage(int $piecesCount): bool
    {
        return $this->piecesPage($piecesCount)?->isIndexable($this) === true;
    }

    /**
     * @return list<BrandPiecesHubStats>
     */
    public function indexablePiecesPages(): array
    {
        return array_values(array_filter(
            $this->piecesPages,
            fn (BrandPiecesHubStats $piecesPage): bool => $piecesPage->isIndexable($this),
        ));
    }
}
