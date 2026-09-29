<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use SpeedPuzzling\Web\Query\GetCatalogueNumbers;
use SpeedPuzzling\Web\Results\CatalogueNumbers;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * One cached snapshot of the puzzle database size, shared by every page that quotes it (the
 * tracker page and the homepage), so they always show the same numbers. At tens of thousands
 * of puzzles and half a million times, six hours of staleness is invisible. Holds no state of
 * its own (the snapshot lives in the app cache), so it is safe across requests in the
 * FrankenPHP worker.
 */
readonly final class CatalogueNumbersProvider
{
    public const string CACHE_KEY = 'catalogue_numbers_v1';

    private const int CACHE_TTL = 21600; // 6 hours

    public function __construct(
        private GetCatalogueNumbers $getCatalogueNumbers,
        private CacheInterface $cache,
    ) {
    }

    public function numbers(): CatalogueNumbers
    {
        /** @var CatalogueNumbers $numbers */
        $numbers = $this->cache->get(self::CACHE_KEY, function (ItemInterface $item): CatalogueNumbers {
            $item->expiresAfter(self::CACHE_TTL);

            return $this->getCatalogueNumbers->current();
        });

        return $numbers;
    }
}
