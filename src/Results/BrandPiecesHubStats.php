<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * One brand × piece-count combination ("Ravensburger 500 pieces"). Every
 * combination with at least one visible puzzle of an allowed piece count has a
 * page; only the ones passing isIndexable() are meant for search engines.
 */
readonly final class BrandPiecesHubStats
{
    /**
     * Thin-page guardrail: fewer puzzles than this and the combination page
     * is noindex and left out of the sitemap.
     */
    public const int MIN_INDEXABLE_PUZZLES = 6;

    public function __construct(
        public int $piecesCount,
        public int $puzzlesCount,
        public int $solvesCount,
        public null|int $medianSeconds,
    ) {
    }

    public function isIndexable(BrandHubStats $brand): bool
    {
        return self::isIndexableCombination($brand->isIndexable(), $this->puzzlesCount, $this->solvesCount);
    }

    /**
     * The one rule for brand × pieces pages: the brand hub itself is indexable,
     * the combination has enough visible puzzles and at least one recorded solve.
     */
    public static function isIndexableCombination(bool $brandHubIndexable, int $puzzlesCount, int $solvesCount): bool
    {
        return $brandHubIndexable
            && $puzzlesCount >= self::MIN_INDEXABLE_PUZZLES
            && $solvesCount > 0;
    }
}
