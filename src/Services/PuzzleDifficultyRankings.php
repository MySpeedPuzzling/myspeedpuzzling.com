<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use SpeedPuzzling\Web\Controller\PiecesPuzzlesController;
use SpeedPuzzling\Web\Query\GetDifficultyRankings;
use SpeedPuzzling\Web\Results\DifficultyRanking;
use SpeedPuzzling\Web\Results\DifficultyRankingBrand;
use SpeedPuzzling\Web\Results\DifficultyRankingEntry;
use SpeedPuzzling\Web\Results\DifficultyRankingsAvailability;
use SpeedPuzzling\Web\Value\DifficultyRankingDirection;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Hardest / easiest puzzle lists per piece count and per brand
 * (docs/features/seo/implementation-plan-2026-10.md, WS-H).
 *
 * Difficulty is a members-only insight: members get the ranking with
 * difficulty (up to MEMBERS_LIMIT puzzles), everybody else the first
 * PUBLIC_LIMIT puzzles with every score and tier stripped.
 *
 * Difficulty is recalculated every 15 minutes and the lists are the same for
 * every visitor, so the availability index and each list are cached for an
 * hour. A list exists only with enough rated puzzles behind it; unknown piece
 * counts and brands never reach the database (or the cache) beyond the one
 * shared availability entry.
 */
readonly final class PuzzleDifficultyRankings
{
    public const int MINIMUM_PUZZLES_PER_PIECES = 50;

    public const int MINIMUM_PUZZLES_PER_BRAND = 40;

    public const int PUBLIC_LIMIT = 25;

    public const int MEMBERS_LIMIT = 100;

    private const int CACHE_TTL = 3600;

    public function __construct(
        private GetDifficultyRankings $getDifficultyRankings,
        private CacheInterface $difficultyRankingsCache,
    ) {
    }

    public function availability(): DifficultyRankingsAvailability
    {
        return $this->difficultyRankingsCache->get('availability', function (ItemInterface $item): DifficultyRankingsAvailability {
            $item->expiresAfter(self::CACHE_TTL);

            $brands = [];

            foreach ($this->getDifficultyRankings->brandsWithRatedPuzzles(self::MINIMUM_PUZZLES_PER_BRAND) as $brand) {
                $brands[$brand->slug] = $brand;
            }

            return new DifficultyRankingsAvailability(
                ratedPuzzlesPerPieces: $this->getDifficultyRankings->ratedPuzzlesPerPieces(
                    PiecesPuzzlesController::ALLOWED_PIECES,
                    self::MINIMUM_PUZZLES_PER_PIECES,
                ),
                brands: $brands,
            );
        });
    }

    /**
     * Null when the piece count has no list (not a hub piece count, or too few rated puzzles).
     */
    public function forPieces(int $piecesCount, DifficultyRankingDirection $direction, bool $withDifficulty): null|DifficultyRanking
    {
        $ratedPuzzlesCount = $this->availability()->ratedPuzzlesForPieces($piecesCount);

        if ($ratedPuzzlesCount === null) {
            return null;
        }

        // v2: the entries lost their alternative name - a new shape under a new key, so the old and the new release never
        // read each other's lists (Redis is shared across blue-green)
        /** @var list<DifficultyRankingEntry> $entries */
        $entries = $this->difficultyRankingsCache->get(
            sprintf('pieces_%d_%s_v2', $piecesCount, $direction->value),
            function (ItemInterface $item) use ($piecesCount, $direction): array {
                $item->expiresAfter(self::CACHE_TTL);

                return $this->getDifficultyRankings->byPieces($piecesCount, $direction, self::MEMBERS_LIMIT);
            },
        );

        return $this->forViewer(new DifficultyRanking($direction, $ratedPuzzlesCount, $entries, true), $withDifficulty);
    }

    /**
     * The brand comes from availability(), which is also what decides that it has a list.
     */
    public function forBrand(DifficultyRankingBrand $brand, DifficultyRankingDirection $direction, bool $withDifficulty): DifficultyRanking
    {
        /** @var list<DifficultyRankingEntry> $entries */
        $entries = $this->difficultyRankingsCache->get(
            sprintf('brand_%s_%s_v2', $brand->brandId, $direction->value),
            function (ItemInterface $item) use ($brand, $direction): array {
                $item->expiresAfter(self::CACHE_TTL);

                return $this->getDifficultyRankings->byBrand($brand->brandId, $direction, self::MEMBERS_LIMIT);
            },
        );

        return $this->forViewer(new DifficultyRanking($direction, $brand->ratedPuzzlesCount, $entries, true), $withDifficulty);
    }

    private function forViewer(DifficultyRanking $ranking, bool $withDifficulty): DifficultyRanking
    {
        return $withDifficulty ? $ranking : $ranking->forPublic(self::PUBLIC_LIMIT);
    }
}
