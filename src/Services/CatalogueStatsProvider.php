<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use SpeedPuzzling\Web\Query\GetBrandDirectory;
use SpeedPuzzling\Web\Query\GetBrandHub;
use SpeedPuzzling\Web\Query\GetPiecesHub;
use SpeedPuzzling\Web\Results\BrandDirectoryEntry;
use SpeedPuzzling\Web\Results\BrandHubStats;
use SpeedPuzzling\Web\Results\PiecesHubStats;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Stats of the catalogue pages (brand hubs, pieces hubs, brand × pieces pages,
 * the brand directory) are the same for every visitor and expensive to compute
 * for the big brands - they are cached for 6 hours.
 *
 * The keys carry a version: the cached value is a serialized result object,
 * so a change of its shape needs a new key (an old entry would unserialize
 * with uninitialized properties during the blue-green overlap).
 */
readonly final class CatalogueStatsProvider
{
    private const int CACHE_TTL = 21600; // 6 hours

    public const int MOST_POPULAR_BRANDS = 12;

    public function __construct(
        private GetBrandHub $getBrandHub,
        private GetPiecesHub $getPiecesHub,
        private GetBrandDirectory $getBrandDirectory,
        private CacheInterface $cache,
    ) {
    }

    /**
     * Brand hub stats incl. its brand × pieces pages. An unknown slug throws
     * ManufacturerNotFound (404) inside the callback, so misses are never cached.
     */
    public function brandHub(string $slug): BrandHubStats
    {
        return $this->cache->get('brand_hub_stats_v2_' . $slug, function (ItemInterface $item) use ($slug): BrandHubStats {
            $item->expiresAfter(self::CACHE_TTL);

            return $this->getBrandHub->bySlug($slug);
        });
    }

    public function piecesHub(int $piecesCount): PiecesHubStats
    {
        return $this->cache->get('pieces_hub_stats_v2_' . $piecesCount, function (ItemInterface $item) use ($piecesCount): PiecesHubStats {
            $item->expiresAfter(self::CACHE_TTL);

            return $this->getPiecesHub->stats($piecesCount);
        });
    }

    /**
     * @return list<BrandDirectoryEntry>
     */
    public function brandDirectory(): array
    {
        /** @var list<BrandDirectoryEntry> $entries */
        $entries = $this->cache->get('brand_directory_v1', function (ItemInterface $item): array {
            $item->expiresAfter(self::CACHE_TTL);

            return $this->getBrandDirectory->indexableBrands();
        });

        return $entries;
    }

    /**
     * The directory's brands with the most recorded solves - its "most popular"
     * block and the "Browse by brand" links of the puzzle database. Cached on
     * their own, so a page that needs only these never loads the ~800 entries
     * of the whole directory.
     *
     * @return list<BrandDirectoryEntry>
     */
    public function mostPopularBrands(): array
    {
        /** @var list<BrandDirectoryEntry> $entries */
        $entries = $this->cache->get('most_popular_brands_v1', function (ItemInterface $item): array {
            $item->expiresAfter(self::CACHE_TTL);

            $brands = $this->brandDirectory();
            usort($brands, static fn (BrandDirectoryEntry $a, BrandDirectoryEntry $b): int => [$b->solvesCount, $a->brandName] <=> [$a->solvesCount, $b->brandName]);

            return array_slice($brands, 0, self::MOST_POPULAR_BRANDS);
        });

        return $entries;
    }

    /**
     * After a brand merge: the merged slugs must stop serving their cached hub (it would
     * show the deleted brand) and start redirecting, and the survivor's counts changed.
     *
     * @param list<string> $slugs
     */
    public function forgetBrands(array $slugs): void
    {
        foreach ($slugs as $slug) {
            $this->cache->delete('brand_hub_stats_v2_' . $slug);
        }

        $this->cache->delete('brand_directory_v1');
        $this->cache->delete('most_popular_brands_v1');
    }
}
