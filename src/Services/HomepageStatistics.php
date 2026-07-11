<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use SpeedPuzzling\Web\Query\GetStatistics;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Community-wide numbers for the homepage live counters. Cached in the app
 * cache (no session dependency): the homepage and the polling JSON endpoint
 * share the same 60s snapshot, so anonymous traffic never hits the
 * aggregation queries more than once a minute.
 */
final readonly class HomepageStatistics
{
    public function __construct(
        private GetStatistics $getStatistics,
        private CacheInterface $cache,
    ) {
    }

    /**
     * @return array{players: int, puzzles: int, pieces: int, hours: int}
     */
    public function all(): array
    {
        return $this->cache->get('homepage_statistics', function (ItemInterface $item): array {
            $item->expiresAfter(60);

            $statistics = $this->getStatistics->globally();

            return [
                'players' => $this->getStatistics->countPlayers(),
                'puzzles' => $statistics->solvedPuzzlesCount,
                'pieces' => $statistics->totalPieces,
                'hours' => intdiv($statistics->totalSeconds, 3600),
            ];
        });
    }
}
