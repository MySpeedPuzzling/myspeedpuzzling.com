<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Helpers for the catalogue pages (brand hub, pieces hub, brand × pieces, brand
 * directory). The fixtures hold at most 20 puzzles per brand, so the tests that
 * need a second page (48 puzzles per page) add filler puzzles - DAMA rolls them
 * back with the test.
 *
 * The pages' stats are cached for 6 hours in cache.app, a filesystem pool in
 * the test environment that outlives a test run: a test that adds data clears
 * it before its first request (no stale stats in) and after (no inflated stats
 * out for the next test).
 */
trait CatalogueTestData
{
    protected static function addFillerPuzzles(ContainerInterface $container, string $manufacturerId, int $piecesCount, int $count): void
    {
        // Names sort after every fixture puzzle, ids are random: the fillers
        // have no solves and land at the end of the most-solved-first lists.
        $container->get(Connection::class)->executeStatement(
            "INSERT INTO puzzle (id, pieces_count, name, approved, manufacturer_id, is_available)
             SELECT gen_random_uuid(), :piecesCount, 'Zz catalogue filler ' || lpad(i::text, 3, '0'), true, :manufacturerId, true
             FROM generate_series(1, :count) AS i",
            [
                'piecesCount' => $piecesCount,
                'manufacturerId' => $manufacturerId,
                'count' => $count,
            ],
        );
    }

    protected static function clearCatalogueStatsCache(ContainerInterface $container): void
    {
        $container->get('cache.app')->clear();
    }

    /**
     * The JSON-LD blocks are assembled with Twig conditionals (first page vs
     * numbered pages) - every one of them must still be valid JSON.
     */
    protected static function assertJsonLdIsValid(Crawler $crawler): void
    {
        $blocks = $crawler->filter('script[type="application/ld+json"]');
        self::assertGreaterThan(0, $blocks->count());

        foreach ($blocks as $block) {
            self::assertIsArray(json_decode((string) $block->textContent, true), (string) $block->textContent);
        }
    }
}
