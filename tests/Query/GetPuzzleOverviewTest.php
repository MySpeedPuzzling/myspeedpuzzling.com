<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\Query\GetPuzzleOverview;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GetPuzzleOverviewTest extends KernelTestCase
{
    private GetPuzzleOverview $query;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->query = $container->get(GetPuzzleOverview::class);
    }

    public function testByIdReturnsPuzzleWithStatistics(): void
    {
        // PUZZLE_500_01 has many solving times - statistics should be populated
        $overview = $this->query->byId(PuzzleFixture::PUZZLE_500_01);

        self::assertSame(PuzzleFixture::PUZZLE_500_01, $overview->puzzleId);
        self::assertSame('Puzzle 1', $overview->puzzleName);
        self::assertSame(500, $overview->piecesCount);

        // Should have solving times count from puzzle_statistics
        self::assertGreaterThan(0, $overview->solvedTimes);

        // Should have solo statistics (PUZZLE_500_01 has solo solves)
        // Statistics are cast to int so 0 means no data
        self::assertGreaterThan(0, $overview->fastestTimeSolo);
        self::assertGreaterThan(0, $overview->averageTimeSolo);
    }

    public function testByIdReturnsZeroStatisticsForUnsolvedPuzzle(): void
    {
        // PUZZLE_9000 has no solving times in fixtures
        $overview = $this->query->byId(PuzzleFixture::PUZZLE_9000);

        self::assertSame(PuzzleFixture::PUZZLE_9000, $overview->puzzleId);
        self::assertSame(0, $overview->solvedTimes);
        // When no statistics exist, values are cast to 0
        self::assertSame(0, $overview->fastestTimeSolo);
        self::assertSame(0, $overview->averageTimeSolo);
    }

    public function testByIdThrowsExceptionForInvalidUuid(): void
    {
        $this->expectException(PuzzleNotFound::class);

        $this->query->byId('invalid-uuid');
    }

    public function testByIdThrowsExceptionForNonExistentPuzzle(): void
    {
        $this->expectException(PuzzleNotFound::class);

        $this->query->byId('00000000-0000-0000-0000-000000000000');
    }

    public function testByEanReturnsPuzzleWithStatistics(): void
    {
        // PUZZLE_500_02 has EAN 4005556123456
        $overview = $this->query->byEan('4005556123456');

        self::assertSame(PuzzleFixture::PUZZLE_500_02, $overview->puzzleId);
        self::assertSame('Puzzle 2', $overview->puzzleName);

        // Should have statistics since PUZZLE_500_02 has solving times
        self::assertGreaterThan(0, $overview->solvedTimes);
    }

    public function testByEanThrowsExceptionForNonExistentEan(): void
    {
        $this->expectException(PuzzleNotFound::class);

        $this->query->byEan('9999999999999');
    }

    public function testByEanThrowsExceptionForInvalidEan(): void
    {
        $this->expectException(PuzzleNotFound::class);

        // Too short EAN
        $this->query->byEan('123');
    }

    public function testStatisticsIncludeSoloDuoTeamSeparation(): void
    {
        // PUZZLE_1000_01 has both solo and duo solves
        $overview = $this->query->byId(PuzzleFixture::PUZZLE_1000_01);

        // Should have solo statistics
        self::assertGreaterThan(0, $overview->fastestTimeSolo);
        self::assertGreaterThan(0, $overview->averageTimeSolo);

        // Should have duo statistics (TIME_12 is duo)
        self::assertGreaterThan(0, $overview->fastestTimeDuo);
        self::assertGreaterThan(0, $overview->averageTimeDuo);

        // Team (3+ players) should be 0 since no team fixtures (cast null to 0)
        self::assertSame(0, $overview->fastestTimeTeam);
        self::assertSame(0, $overview->averageTimeTeam);
    }

    public function testSoloFastestTimeIsCorrect(): void
    {
        // PUZZLE_500_01: Solo times from fixtures include TIME_32 at 1200 seconds (fastest)
        $overview = $this->query->byId(PuzzleFixture::PUZZLE_500_01);

        // Fastest should be 1200 seconds (TIME_32)
        self::assertSame(1200, $overview->fastestTimeSolo);
    }
}
