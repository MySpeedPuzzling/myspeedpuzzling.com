<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Entity\PuzzleStatistics;
use SpeedPuzzling\Web\Query\GetPuzzleStatistics;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Value\PuzzleStatisticsData;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The community statistics of the public API's puzzle cards, read in one
 * batch from the precomputed puzzle_statistics table.
 */
final class GetPuzzleStatisticsTest extends KernelTestCase
{
    private GetPuzzleStatistics $query;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->query = self::getContainer()->get(GetPuzzleStatistics::class);
    }

    public function testStatisticsAreSplitByDiscipline(): void
    {
        // PUZZLE_1000_01: eight solo solves (3900-6500 s) and one duo solve (3600 s)
        $statistics = $this->query->forPuzzleList([PuzzleFixture::PUZZLE_1000_01, PuzzleFixture::PUZZLE_500_01]);

        self::assertEqualsCanonicalizing([PuzzleFixture::PUZZLE_1000_01, PuzzleFixture::PUZZLE_500_01], array_keys($statistics));

        $puzzle = $statistics[PuzzleFixture::PUZZLE_1000_01];
        self::assertSame(PuzzleFixture::PUZZLE_1000_01, $puzzle->puzzleId);
        self::assertSame($puzzle->solo->count + $puzzle->duo->count + $puzzle->team->count, $puzzle->solvedTimes);
        self::assertSame(8, $puzzle->solo->count);
        self::assertSame(3900, $puzzle->solo->fastestSeconds);
        self::assertSame(6500, $puzzle->solo->slowestSeconds);
        self::assertNotNull($puzzle->solo->averageSeconds);
        self::assertGreaterThan(3900, $puzzle->solo->averageSeconds);
        self::assertLessThan(6500, $puzzle->solo->averageSeconds);
        self::assertSame(1, $puzzle->duo->count);
        self::assertSame(3600, $puzzle->duo->fastestSeconds);
        self::assertSame(3600, $puzzle->duo->averageSeconds);
        self::assertSame(3600, $puzzle->duo->slowestSeconds);
        self::assertSame(0, $puzzle->team->count);
        self::assertNull($puzzle->team->fastestSeconds);

        // PUZZLE_500_01: solo only, 1200-3000 s
        $puzzle = $statistics[PuzzleFixture::PUZZLE_500_01];
        self::assertSame(11, $puzzle->solvedTimes);
        self::assertSame(1200, $puzzle->solo->fastestSeconds);
        self::assertSame(3000, $puzzle->solo->slowestSeconds);
        self::assertSame(0, $puzzle->duo->count);
    }

    public function testMedianIsReadPerDiscipline(): void
    {
        // a seeded row with distinct medians per discipline
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $puzzle = $entityManager->find(Puzzle::class, PuzzleFixture::PUZZLE_5000);
        self::assertNotNull($puzzle);

        $row = new PuzzleStatistics($puzzle);
        $row->update(new PuzzleStatisticsData(
            totalCount: 6,
            fastestTime: 1000,
            averageTime: 2500,
            slowestTime: 6000,
            soloCount: 3,
            fastestTimeSolo: 1000,
            averageTimeSolo: 1600,
            slowestTimeSolo: 2200,
            duoCount: 2,
            fastestTimeDuo: 3000,
            averageTimeDuo: 3500,
            slowestTimeDuo: 4000,
            teamCount: 1,
            fastestTimeTeam: 6000,
            averageTimeTeam: 6000,
            slowestTimeTeam: 6000,
            medianTime: 2600,
            medianTimeSolo: 1500,
            medianTimeDuo: 3500,
            medianTimeTeam: 6000,
        ));
        $entityManager->persist($row);
        $entityManager->flush();
        $entityManager->clear();

        $statistics = $this->query->forPuzzleList([PuzzleFixture::PUZZLE_5000, PuzzleFixture::PUZZLE_1000_01]);

        $seeded = $statistics[PuzzleFixture::PUZZLE_5000];
        self::assertSame(1500, $seeded->solo->medianSeconds);
        self::assertSame(3500, $seeded->duo->medianSeconds);
        self::assertSame(6000, $seeded->team->medianSeconds);

        // and the fixture row, computed by PuzzleStatisticsCalculator: PUZZLE_1000_01's solo
        // solvers' best times are 3900 4100 4200 5100 -> (4100 + 4200) / 2; one duo solve of 3600 s
        $computed = $statistics[PuzzleFixture::PUZZLE_1000_01];
        self::assertSame(4150, $computed->solo->medianSeconds);
        self::assertSame(3600, $computed->duo->medianSeconds);
        self::assertNull($computed->team->medianSeconds);
    }

    public function testNeverSolvedPuzzlesAreAbsent(): void
    {
        self::assertSame([], $this->query->forPuzzleList([PuzzleFixture::PUZZLE_9000]));
        self::assertSame([], $this->query->forPuzzleList([]));
    }
}
