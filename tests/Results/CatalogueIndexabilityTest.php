<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Results;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Results\BrandHubStats;
use SpeedPuzzling\Web\Results\BrandPiecesHubStats;
use SpeedPuzzling\Web\Results\PiecesHubBrand;

/**
 * The thin-page rules of the catalogue pages - the robots meta, the links
 * between the hubs, the directory and the sitemap all go through these.
 */
final class CatalogueIndexabilityTest extends TestCase
{
    /**
     * @return iterable<string, array{bool, int, int, bool}>
     */
    public static function brandHubs(): iterable
    {
        yield 'approved, enough puzzles, solved' => [true, 3, 1, true];
        yield 'not approved' => [false, 100, 100, false];
        yield 'too few puzzles' => [true, 2, 100, false];
        yield 'never solved' => [true, 100, 0, false];
    }

    #[DataProvider('brandHubs')]
    public function testBrandHubRule(bool $approved, int $puzzles, int $solves, bool $expected): void
    {
        self::assertSame($expected, BrandHubStats::isIndexableBrand($approved, $puzzles, $solves));
        self::assertSame($expected, self::brand($approved, $puzzles, $solves, [])->isIndexable());
    }

    /**
     * @return iterable<string, array{bool, int, int, bool}>
     */
    public static function combinations(): iterable
    {
        yield 'indexable brand, 6 puzzles, solved' => [true, 6, 1, true];
        yield 'brand hub not indexable' => [false, 100, 100, false];
        yield '5 puzzles only' => [true, 5, 100, false];
        yield 'never solved' => [true, 100, 0, false];
    }

    #[DataProvider('combinations')]
    public function testBrandPiecesRule(bool $brandHubIndexable, int $puzzles, int $solves, bool $expected): void
    {
        self::assertSame($expected, BrandPiecesHubStats::isIndexableCombination($brandHubIndexable, $puzzles, $solves));
    }

    public function testBrandHubKnowsWhichOfItsPiecesPagesAreIndexable(): void
    {
        $brand = self::brand(true, 40, 50, [
            new BrandPiecesHubStats(piecesCount: 300, puzzlesCount: 2, solvesCount: 10, medianSeconds: 1800),
            new BrandPiecesHubStats(piecesCount: 500, puzzlesCount: 30, solvesCount: 40, medianSeconds: 3600),
            new BrandPiecesHubStats(piecesCount: 1000, puzzlesCount: 8, solvesCount: 0, medianSeconds: null),
        ]);

        self::assertTrue($brand->hasIndexablePiecesPage(500));
        self::assertFalse($brand->hasIndexablePiecesPage(300), 'Too few puzzles');
        self::assertFalse($brand->hasIndexablePiecesPage(1000), 'Never solved');
        self::assertFalse($brand->hasIndexablePiecesPage(750), 'No such page');
        self::assertSame(1000, $brand->piecesPage(1000)?->piecesCount);
        self::assertNull($brand->piecesPage(750));
        self::assertSame([500], array_map(
            static fn (BrandPiecesHubStats $page): int => $page->piecesCount,
            $brand->indexablePiecesPages(),
        ));

        // A brand whose own hub is noindex has no indexable piece-count page
        $unapproved = self::brand(false, 40, 50, $brand->piecesPages);
        self::assertFalse($unapproved->hasIndexablePiecesPage(500));
        self::assertSame([], $unapproved->indexablePiecesPages());
    }

    public function testPiecesHubBrandBadgeTarget(): void
    {
        self::assertTrue((new PiecesHubBrand('Ravensburger', 'ravensburger', solvesCount: 39, puzzlesCount: 8, brandPuzzlesCount: 20))->hasIndexableBrandPiecesPage());
        self::assertFalse((new PiecesHubBrand('Trefl', 'trefl', solvesCount: 2, puzzlesCount: 2, brandPuzzlesCount: 7))->hasIndexableBrandPiecesPage());
    }

    /**
     * @param list<BrandPiecesHubStats> $piecesPages
     */
    private static function brand(bool $approved, int $puzzles, int $solves, array $piecesPages): BrandHubStats
    {
        return new BrandHubStats(
            brandId: '018d0002-0000-0000-0000-000000000001',
            brandName: 'Ravensburger',
            slug: 'ravensburger',
            approved: $approved,
            puzzlesCount: $puzzles,
            solvesCount: $solves,
            medianSeconds: null,
            piecesMedians: [],
            piecesPages: $piecesPages,
        );
    }
}
