<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Results;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Results\BrandHubStats;
use SpeedPuzzling\Web\Results\BrandPiecesHubStats;
use SpeedPuzzling\Web\Results\PuzzleCatalogueLinks;
use SpeedPuzzling\Web\Results\PuzzleOverview;

/**
 * Which catalogue pages a puzzle page links to - its breadcrumb, the piece count
 * in its header and the footer link of its related puzzles all follow these.
 */
final class PuzzleCatalogueLinksTest extends TestCase
{
    public function testIndexableBrandPiecesPageWins(): void
    {
        $links = PuzzleCatalogueLinks::forPuzzle(self::puzzle('ravensburger', 500), self::brandHub(500, puzzles: 8, solves: 20));

        self::assertTrue($links->linksBrandHub());
        self::assertTrue($links->linksBrandPiecesPage());
        self::assertFalse($links->linksPiecesHub());
    }

    public function testNonIndexableCombinationFallsBackToThePiecesHub(): void
    {
        // 5 puzzles: the brand × pieces page exists, but is noindex
        $links = PuzzleCatalogueLinks::forPuzzle(self::puzzle('ravensburger', 500), self::brandHub(500, puzzles: 5, solves: 20));

        self::assertTrue($links->linksBrandHub());
        self::assertFalse($links->linksBrandPiecesPage());
        self::assertTrue($links->linksPiecesHub());
    }

    public function testOddPieceCountHasNoPiecesLevel(): void
    {
        // 4000 is no allowed piece count: neither a brand × pieces page nor a pieces hub
        $links = PuzzleCatalogueLinks::forPuzzle(self::puzzle('ravensburger', 4000), null);

        self::assertTrue($links->linksBrandHub());
        self::assertFalse($links->linksBrandPiecesPage());
        self::assertFalse($links->linksPiecesHub());
    }

    public function testBrandWithoutSlugLinksOnlyThePiecesHub(): void
    {
        $links = PuzzleCatalogueLinks::forPuzzle(self::puzzle(null, 1000), null);

        self::assertFalse($links->linksBrandHub());
        self::assertFalse($links->linksBrandPiecesPage());
        self::assertTrue($links->linksPiecesHub());
    }

    public function testUnapprovedBrandNeverLinksItsBrandPiecesPage(): void
    {
        $links = PuzzleCatalogueLinks::forPuzzle(self::puzzle('unknown-brand', 500), self::brandHub(500, puzzles: 30, solves: 50, approved: false));

        self::assertTrue($links->linksBrandHub());
        self::assertFalse($links->linksBrandPiecesPage());
        self::assertTrue($links->linksPiecesHub());
    }

    private static function puzzle(null|string $brandSlug, int $piecesCount): PuzzleOverview
    {
        return new PuzzleOverview(
            puzzleId: '018d0003-0000-0000-0000-000000000001',
            puzzleName: 'Puzzle',
            puzzleAlternativeName: null,
            puzzleApproved: true,
            manufacturerId: '018d0002-0000-0000-0000-000000000001',
            manufacturerName: 'Brand',
            piecesCount: $piecesCount,
            averageTimeSolo: 0,
            fastestTimeSolo: 0,
            averageTimeDuo: 0,
            fastestTimeDuo: 0,
            averageTimeTeam: 0,
            fastestTimeTeam: 0,
            solvedTimes: 0,
            puzzleImage: null,
            puzzleImageRatio: null,
            isAvailable: true,
            puzzleEan: null,
            puzzleIdentificationNumber: null,
            manufacturerSlug: $brandSlug,
        );
    }

    private static function brandHub(int $piecesCount, int $puzzles, int $solves, bool $approved = true): BrandHubStats
    {
        return new BrandHubStats(
            brandId: '018d0002-0000-0000-0000-000000000001',
            brandName: 'Brand',
            slug: 'brand',
            approved: $approved,
            puzzlesCount: 100,
            solvesCount: 500,
            medianSeconds: 3600,
            piecesMedians: [],
            piecesPages: [
                new BrandPiecesHubStats(piecesCount: $piecesCount, puzzlesCount: $puzzles, solvesCount: $solves, medianSeconds: 3600),
            ],
        );
    }
}
