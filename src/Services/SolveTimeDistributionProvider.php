<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Query\GetSolveTimeDistribution;
use SpeedPuzzling\Web\Results\SolveTimeDistribution;
use SpeedPuzzling\Web\Results\SolveTimeDistributionSnapshot;
use SpeedPuzzling\Web\Value\PuzzlingType;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Shared source of the community solve-time distribution behind the guides and
 * the FAQ. Everything reads the same cache entries, so the numbers these pages
 * publish - including the FAQ answers that end up in schema.org structured
 * data - can never disagree with each other.
 *
 * One cache entry per puzzling type (and per exact group size), each remembering
 * when it was computed: that is the date the guides publish as dateModified.
 */
readonly final class SolveTimeDistributionProvider
{
    /**
     * Standard retail piece counts; every bucket had well over 100 recorded
     * solo solves in production when the guide launched.
     *
     * @var list<int>
     */
    public const array PIECES_BUCKETS = [100, 200, 300, 500, 1000, 1500, 2000];

    private const string CACHE_KEY = 'guides_solve_time_distribution_v2';

    private const int CACHE_TTL = 21600; // 6 hours

    public function __construct(
        private GetSolveTimeDistribution $getSolveTimeDistribution,
        private CacheInterface $solveTimeDistributionCache,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return array<int, SolveTimeDistribution>
     */
    public function forStandardPiecesBuckets(PuzzlingType $puzzlingType = PuzzlingType::Solo): array
    {
        return $this->snapshot($puzzlingType)->distributions;
    }

    public function snapshot(PuzzlingType $puzzlingType = PuzzlingType::Solo): SolveTimeDistributionSnapshot
    {
        return $this->cached(
            self::cacheKey($puzzlingType),
            fn (): array => $this->getSolveTimeDistribution->byPiecesCounts(self::PIECES_BUCKETS, $puzzlingType),
        );
    }

    /**
     * Groups of exactly this many puzzlers ("with 4 people") - a team is any group of three or more.
     */
    public function snapshotForGroupSize(int $puzzlersCount): SolveTimeDistributionSnapshot
    {
        return $this->cached(
            self::cacheKey(PuzzlingType::fromPuzzlersCount($puzzlersCount), $puzzlersCount),
            fn (): array => $this->getSolveTimeDistribution->byPiecesCountsForGroupSize(self::PIECES_BUCKETS, $puzzlersCount),
        );
    }

    /**
     * Public so tests can pin a page to a known snapshot (the pool is an in-memory array in the test env).
     */
    public static function cacheKey(PuzzlingType $puzzlingType, null|int $puzzlersCount = null): string
    {
        return $puzzlersCount === null
            ? sprintf('%s_%s', self::CACHE_KEY, $puzzlingType->value)
            : sprintf('%s_%s_%d', self::CACHE_KEY, $puzzlingType->value, $puzzlersCount);
    }

    /**
     * @param callable(): array<int, SolveTimeDistribution> $compute
     */
    private function cached(string $key, callable $compute): SolveTimeDistributionSnapshot
    {
        /** @var SolveTimeDistributionSnapshot $snapshot */
        $snapshot = $this->solveTimeDistributionCache->get(
            $key,
            function (ItemInterface $item) use ($compute): SolveTimeDistributionSnapshot {
                $item->expiresAfter(self::CACHE_TTL);

                return new SolveTimeDistributionSnapshot($compute(), $this->clock->now());
            },
        );

        return $snapshot;
    }
}
