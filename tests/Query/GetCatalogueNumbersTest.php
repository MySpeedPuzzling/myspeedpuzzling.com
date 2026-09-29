<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Query\GetCatalogueNumbers;
use SpeedPuzzling\Web\Results\CatalogueNumbers;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GetCatalogueNumbersTest extends KernelTestCase
{
    private GetCatalogueNumbers $query;

    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->query = self::getContainer()->get(GetCatalogueNumbers::class);
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testCountsTheFixtureCatalogue(): void
    {
        $numbers = $this->query->current();

        // At least Ravensburger and Trefl; "Unknown Brand" is not approved
        self::assertGreaterThanOrEqual(2, $numbers->brands);
        self::assertGreaterThanOrEqual(20, $numbers->puzzles);
        self::assertGreaterThan(0, $numbers->puzzlesWithEan);
        self::assertLessThan($numbers->puzzles, $numbers->puzzlesWithEan);
        self::assertGreaterThan(0, $numbers->solveTimes);
    }

    public function testHiddenPuzzleIsNotCounted(): void
    {
        $before = $this->query->current();

        // PUZZLE_300 carries an EAN, so it leaves both counts
        $this->database->executeStatement(
            "UPDATE puzzle SET hide_until = '2099-12-31 00:00:00' WHERE id = :id",
            ['id' => PuzzleFixture::PUZZLE_300],
        );

        $after = $this->query->current();

        self::assertSame($before->puzzles - 1, $after->puzzles);
        self::assertSame($before->puzzlesWithEan - 1, $after->puzzlesWithEan);
    }

    public function testPuzzleWithAHiddenImageStillCounts(): void
    {
        $before = $this->query->current();

        $this->database->executeStatement(
            "UPDATE puzzle SET hide_image_until = '2099-12-31 00:00:00' WHERE id = :id",
            ['id' => PuzzleFixture::PUZZLE_300],
        );

        self::assertEquals($before, $this->query->current());
    }

    public function testUnapprovedPuzzleIsNotCountedAndItsBrandNeedsApprovalToo(): void
    {
        $before = $this->query->current();

        $this->database->executeStatement(
            'UPDATE puzzle SET approved = true WHERE id = :id',
            ['id' => PuzzleFixture::PUZZLE_UNAPPROVED],
        );

        $puzzleApproved = $this->query->current();
        self::assertSame($before->puzzles + 1, $puzzleApproved->puzzles);
        // Its brand is still waiting for approval (often a duplicate about to be merged)
        self::assertSame($before->brands, $puzzleApproved->brands);

        $this->database->executeStatement(
            'UPDATE manufacturer SET approved = true WHERE id = :id',
            ['id' => ManufacturerFixture::MANUFACTURER_UNAPPROVED],
        );

        self::assertSame($before->brands + 1, $this->query->current()->brands);
    }

    public function testBrandWithoutAVisiblePuzzleIsNotCounted(): void
    {
        $before = $this->query->current();

        $this->database->executeStatement(
            'UPDATE manufacturer SET approved = true WHERE id = :id',
            ['id' => ManufacturerFixture::MANUFACTURER_UNAPPROVED],
        );

        // Its only puzzle is not approved
        self::assertSame($before->brands, $this->query->current()->brands);
    }

    public function testEmptyEanIsNotCounted(): void
    {
        $before = $this->query->current();

        $this->database->executeStatement(
            "UPDATE puzzle SET ean = '' WHERE id = :id",
            ['id' => PuzzleFixture::PUZZLE_300],
        );

        self::assertSame($before->puzzlesWithEan - 1, $this->query->current()->puzzlesWithEan);
    }

    public function testOnlyValidTimedSolvesCount(): void
    {
        $before = $this->query->current();

        $this->database->executeStatement(
            'UPDATE puzzle_solving_time SET suspicious = true WHERE id = :id',
            ['id' => PuzzleSolvingTimeFixture::TIME_01],
        );
        $this->database->executeStatement(
            'UPDATE puzzle_solving_time SET seconds_to_solve = NULL WHERE id = :id',
            ['id' => PuzzleSolvingTimeFixture::TIME_02],
        );

        self::assertSame($before->solveTimes - 2, $this->query->current()->solveTimes);
    }

    public function testResultSurvivesTheCacheRoundTrip(): void
    {
        $numbers = $this->query->current();

        self::assertEquals($numbers, unserialize(serialize($numbers), ['allowed_classes' => [CatalogueNumbers::class]]));
    }
}
