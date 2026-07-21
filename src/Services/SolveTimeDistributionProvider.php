<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use SpeedPuzzling\Web\Query\GetSolveTimeDistribution;
use SpeedPuzzling\Web\Results\SolveTimeDistribution;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Shared source of the community solve-time distribution behind the guides and
 * the FAQ. Everything reads the same cache entry, so the numbers these pages
 * publish - including the FAQ answers that end up in schema.org structured
 * data - can never disagree with each other.
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

    private const string CACHE_KEY = 'guides_solve_time_distribution_v1';

    private const int CACHE_TTL = 21600; // 6 hours

    public function __construct(
        private GetSolveTimeDistribution $getSolveTimeDistribution,
        private CacheInterface $cache,
        #[Autowire(param: 'kernel.environment')]
        private string $environment,
    ) {
    }

    /**
     * @return array<int, SolveTimeDistribution>
     */
    public function forStandardPiecesBuckets(): array
    {
        /** @var array<int, SolveTimeDistribution> $distributions */
        $distributions = $this->cache->get(
            sprintf('%s_%s', self::CACHE_KEY, $this->environment),
            function (ItemInterface $item): array {
                $item->expiresAfter(self::CACHE_TTL);

                return $this->getSolveTimeDistribution->byPiecesCounts(self::PIECES_BUCKETS);
            },
        );

        return $distributions;
    }
}
