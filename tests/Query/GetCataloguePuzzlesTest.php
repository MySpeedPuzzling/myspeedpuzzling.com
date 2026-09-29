<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Query\GetCataloguePuzzles;
use SpeedPuzzling\Web\Results\PuzzleOverview;
use SpeedPuzzling\Web\Tests\CatalogueTestData;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Value\PiecesRange;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GetCataloguePuzzlesTest extends KernelTestCase
{
    use CatalogueTestData;

    private GetCataloguePuzzles $getCataloguePuzzles;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->getCataloguePuzzles = self::getContainer()->get(GetCataloguePuzzles::class);
    }

    public function testCountsOnlyVisiblePuzzlesOfTheBrandAndPieceCount(): void
    {
        $brand = ManufacturerFixture::MANUFACTURER_RAVENSBURGER;
        $allRavensburger = $this->getCataloguePuzzles->count($brand, PiecesRange::any());
        $ravensburger500 = $this->getCataloguePuzzles->count($brand, PiecesRange::between(500, 500));

        self::assertSame(count($this->getCataloguePuzzles->page($brand, PiecesRange::any(), 0, 1000)), $allRavensburger);
        self::assertSame(count($this->getCataloguePuzzles->page($brand, PiecesRange::between(500, 500), 0, 1000)), $ravensburger500);
        self::assertGreaterThan($ravensburger500, $allRavensburger);

        // A secret competition puzzle (hide_until in the future) is not part of any list
        self::getContainer()->get(Connection::class)->executeStatement(
            "INSERT INTO puzzle (id, pieces_count, name, approved, manufacturer_id, is_available, hide_until)
             VALUES (gen_random_uuid(), 500, 'Secret competition puzzle', true, :brandId, true, now() + interval '30 days')",
            ['brandId' => $brand],
        );

        self::assertSame($allRavensburger, $this->getCataloguePuzzles->count($brand, PiecesRange::any()));
        self::assertSame($ravensburger500, $this->getCataloguePuzzles->count($brand, PiecesRange::between(500, 500)));

        foreach ($this->getCataloguePuzzles->page($brand, PiecesRange::any(), 0, 1000) as $puzzle) {
            self::assertSame(ManufacturerFixture::MANUFACTURER_RAVENSBURGER, $puzzle->manufacturerId);
            self::assertNotSame('Secret competition puzzle', $puzzle->puzzleName);
        }

        foreach ($this->getCataloguePuzzles->page(null, PiecesRange::between(1000, 1000), 0, 1000) as $puzzle) {
            self::assertSame(1000, $puzzle->piecesCount);
        }
    }

    public function testListIsMostSolvedFirstThenByName(): void
    {
        $puzzles = $this->getCataloguePuzzles->page(ManufacturerFixture::MANUFACTURER_RAVENSBURGER, PiecesRange::any(), 0, 1000);

        self::assertSame(PuzzleFixture::PUZZLE_500_01, $puzzles[0]->puzzleId, 'The most solved Ravensburger puzzle comes first');

        for ($i = 1; $i < count($puzzles); $i++) {
            $previous = $puzzles[$i - 1];
            $current = $puzzles[$i];

            self::assertGreaterThanOrEqual($current->solvedTimes, $previous->solvedTimes);

            if ($previous->solvedTimes === $current->solvedTimes) {
                self::assertLessThanOrEqual(0, strcmp($previous->puzzleName, $current->puzzleName));
            }
        }
    }

    public function testPagesNeitherOverlapNorSkipPuzzles(): void
    {
        $brand = ManufacturerFixture::MANUFACTURER_RAVENSBURGER;

        // Equal names and no solves: only the id tiebreaker keeps the order stable
        self::addFillerPuzzles(self::getContainer(), $brand, 1000, 12);
        self::getContainer()->get(Connection::class)->executeStatement(
            "UPDATE puzzle SET name = 'Same name' WHERE name LIKE 'Zz catalogue filler%'",
        );

        $all = self::ids($this->getCataloguePuzzles->page($brand, PiecesRange::any(), 0, 1000));
        $total = $this->getCataloguePuzzles->count($brand, PiecesRange::any());
        self::assertCount($total, $all);

        $paged = [];
        for ($offset = 0; $offset < $total; $offset += 5) {
            $paged = [...$paged, ...self::ids($this->getCataloguePuzzles->page($brand, PiecesRange::any(), $offset, 5))];
        }

        self::assertSame($all, $paged);
        self::assertSame([], $this->getCataloguePuzzles->page($brand, PiecesRange::any(), $total, 5));
    }

    public function testEmbargoedImageIsNotExposed(): void
    {
        $puzzles = $this->getCataloguePuzzles->page(ManufacturerFixture::MANUFACTURER_RAVENSBURGER, PiecesRange::between(1000, 1000), 0, 1000);

        $hiddenImage = array_values(array_filter(
            $puzzles,
            static fn (PuzzleOverview $puzzle): bool => $puzzle->puzzleId === PuzzleFixture::PUZZLE_HIDDEN_IMAGE,
        ));

        self::assertCount(1, $hiddenImage);
        self::assertNull($hiddenImage[0]->puzzleImage);
    }

    /**
     * @param list<PuzzleOverview> $puzzles
     * @return list<string>
     */
    private static function ids(array $puzzles): array
    {
        return array_map(static fn (PuzzleOverview $puzzle): string => $puzzle->puzzleId, $puzzles);
    }
}
