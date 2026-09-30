<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Exceptions\ManufacturerNotFound;
use SpeedPuzzling\Web\Query\GetBrandHub;
use SpeedPuzzling\Web\Query\GetCataloguePuzzles;
use SpeedPuzzling\Web\Results\BrandPiecesHubStats;
use SpeedPuzzling\Web\Results\PiecesMedian;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Value\PiecesRange;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GetBrandHubTest extends KernelTestCase
{
    private GetBrandHub $getBrandHub;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->getBrandHub = self::getContainer()->get(GetBrandHub::class);
    }

    public function testBrandStats(): void
    {
        $stats = $this->getBrandHub->bySlug('ravensburger');

        self::assertSame(ManufacturerFixture::MANUFACTURER_RAVENSBURGER, $stats->brandId);
        self::assertSame('Ravensburger', $stats->brandName);
        self::assertTrue($stats->isIndexable());
        self::assertSame(
            self::getContainer()->get(GetCataloguePuzzles::class)->count($stats->brandId, PiecesRange::any()),
            $stats->puzzlesCount,
        );
        self::assertGreaterThan(0, $stats->solvesCount);
        self::assertNotNull($stats->medianSeconds);
    }

    public function testPiecesPagesAreTheAllowedPieceCountsWithAVisiblePuzzle(): void
    {
        $stats = $this->getBrandHub->bySlug('ravensburger');

        // 4000/5000/9000-piece Ravensburger puzzles exist but have no pieces hub
        self::assertSame([300, 500, 1000, 1500, 2000], array_map(
            static fn (BrandPiecesHubStats $page): int => $page->piecesCount,
            $stats->piecesPages,
        ));

        $getCataloguePuzzles = self::getContainer()->get(GetCataloguePuzzles::class);

        foreach ($stats->piecesPages as $page) {
            self::assertSame(
                $getCataloguePuzzles->count($stats->brandId, PiecesRange::between($page->piecesCount, $page->piecesCount)),
                $page->puzzlesCount,
                sprintf('%d pieces: visible puzzles', $page->piecesCount),
            );
            self::assertGreaterThan(0, $page->solvesCount, sprintf('%d pieces: every fixture size has a solve', $page->piecesCount));
            self::assertNotNull($page->medianSeconds);
        }

        $ravensburger500 = $stats->piecesPage(500);
        self::assertNotNull($ravensburger500);
        self::assertSame(8, $ravensburger500->puzzlesCount, 'The 9th 500-piece puzzle is a secret competition puzzle');
        self::assertTrue($stats->hasIndexablePiecesPage(500));
        self::assertTrue($stats->hasIndexablePiecesPage(1000));
        self::assertFalse($stats->hasIndexablePiecesPage(300));
    }

    public function testMediansByPiecesNeedTenSoloSolves(): void
    {
        $stats = $this->getBrandHub->bySlug('ravensburger');

        // Only the 500-piece bucket has 10+ solo solves in the fixtures
        self::assertSame([500], array_map(
            static fn (PiecesMedian $median): int => $median->piecesCount,
            $stats->piecesMedians,
        ));
        self::assertGreaterThanOrEqual(10, $stats->piecesMedians[0]->solvesCount);
        self::assertSame($stats->piecesPage(500)?->medianSeconds, $stats->piecesMedians[0]->medianSeconds);
    }

    public function testBrandTotalsCountEveryTimedSolveOfTheBrand(): void
    {
        $stats = $this->getBrandHub->bySlug('ravensburger');

        /** @var array{solves_count: int, median_seconds: null|float} $expected */
        $expected = self::getContainer()->get(Connection::class)->fetchAssociative(
            <<<SQL
SELECT
    COUNT(*) AS solves_count,
    percentile_cont(0.5) WITHIN GROUP (ORDER BY pst.seconds_to_solve) FILTER (WHERE pst.puzzlers_count = 1) AS median_seconds
FROM puzzle_solving_time pst
INNER JOIN puzzle ON puzzle.id = pst.puzzle_id
WHERE puzzle.manufacturer_id = :brandId
    AND pst.seconds_to_solve IS NOT NULL
SQL,
            ['brandId' => ManufacturerFixture::MANUFACTURER_RAVENSBURGER],
        );

        self::assertSame($expected['solves_count'], $stats->solvesCount);
        self::assertNotNull($expected['median_seconds']);
        self::assertSame((int) round($expected['median_seconds']), $stats->medianSeconds);
    }

    public function testBrandWithoutSolvesHasZeroSolvesAndNoMedian(): void
    {
        // The unapproved fixture brand has a puzzle, but nobody solved it
        $stats = $this->getBrandHub->bySlug('unknown-brand');

        self::assertSame(1, $stats->puzzlesCount);
        self::assertSame(0, $stats->solvesCount);
        self::assertNull($stats->medianSeconds);
        self::assertSame([], $stats->piecesMedians);

        foreach ($stats->piecesPages as $page) {
            self::assertSame(0, $page->solvesCount);
            self::assertNull($page->medianSeconds);
        }
    }

    public function testUnknownSlugThrows(): void
    {
        $this->expectException(ManufacturerNotFound::class);

        $this->getBrandHub->bySlug('does-not-exist');
    }
}
