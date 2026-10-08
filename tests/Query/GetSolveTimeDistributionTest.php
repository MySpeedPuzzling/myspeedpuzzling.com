<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Query\GetSolveTimeDistribution;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use SpeedPuzzling\Web\Value\PuzzlingType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Fixture group times: TIME_12 (PUZZLE_1000_01, 3600 s) and TIME_41 (PUZZLE_1000_03, 4000 s),
 * both by the same pair (PLAYER_REGULAR tracking, PLAYER_PRIVATE along). No team times exist.
 */
final class GetSolveTimeDistributionTest extends KernelTestCase
{
    private const array BUCKETS = [100, 200, 300, 500, 1000, 1500, 2000];

    private GetSolveTimeDistribution $query;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->query = self::getContainer()->get(GetSolveTimeDistribution::class);
    }

    public function testSoloIsTheDefault(): void
    {
        $distributions = $this->query->byPiecesCounts(self::BUCKETS);

        self::assertEquals($distributions, $this->query->byPiecesCounts(self::BUCKETS, PuzzlingType::Solo));

        // No 100- or 200-piece puzzle in the fixtures: buckets without data are omitted
        self::assertSame([300, 500, 1000, 1500, 2000], array_keys($distributions));
        self::assertSame(41, $distributions[500]->solvesCount);
        self::assertSame(12, $distributions[1000]->solvesCount);

        foreach ($distributions as $distribution) {
            self::assertSame(
                $distribution->solvesCount,
                $distribution->firstAttemptCount + $distribution->notFirstAttemptCount,
                'Every solve is either marked as a first attempt or not',
            );
            self::assertLessThanOrEqual($distribution->p25Seconds, $distribution->p10Seconds);
            self::assertLessThanOrEqual($distribution->medianSeconds, $distribution->p25Seconds);
            self::assertLessThanOrEqual($distribution->p75Seconds, $distribution->medianSeconds);
            self::assertLessThanOrEqual($distribution->p90Seconds, $distribution->p75Seconds);
        }
    }

    public function testPairsCountGroupSolvesAndDistinctPairs(): void
    {
        $distributions = $this->query->byPiecesCounts(self::BUCKETS, PuzzlingType::Duo);

        self::assertSame([1000], array_keys($distributions));

        $pairs = $distributions[1000];
        self::assertSame(2, $pairs->solvesCount);
        // Both times belong to the same two people - one pair, not "one tracker"
        self::assertSame(1, $pairs->playersCount);
        self::assertSame(3800, $pairs->medianSeconds);
        self::assertSame(3600, $pairs->fastestSeconds);
    }

    public function testTeamsAndExactGroupSizes(): void
    {
        $connection = self::getContainer()->get(Connection::class);
        $connection->executeStatement(
            "UPDATE puzzle_solving_time SET puzzling_type = 'team', puzzlers_count = 3 WHERE id = :id",
            ['id' => PuzzleSolvingTimeFixture::TIME_12],
        );
        $connection->executeStatement(
            "UPDATE puzzle_solving_time SET puzzling_type = 'team', puzzlers_count = 4 WHERE id = :id",
            ['id' => PuzzleSolvingTimeFixture::TIME_41],
        );

        self::assertSame([], $this->query->byPiecesCounts(self::BUCKETS, PuzzlingType::Duo));

        $teams = $this->query->byPiecesCounts(self::BUCKETS, PuzzlingType::Team);
        self::assertSame([1000], array_keys($teams));
        self::assertSame(2, $teams[1000]->solvesCount);
        self::assertSame(3800, $teams[1000]->medianSeconds);

        $groupsOfFour = $this->query->byPiecesCountsForGroupSize(self::BUCKETS, 4);
        self::assertSame(1, $groupsOfFour[1000]->solvesCount);
        self::assertSame(4000, $groupsOfFour[1000]->medianSeconds);

        $groupsOfThree = $this->query->byPiecesCountsForGroupSize(self::BUCKETS, 3);
        self::assertSame(1, $groupsOfThree[1000]->solvesCount);
        self::assertSame(3600, $groupsOfThree[1000]->medianSeconds);

        self::assertSame([], $this->query->byPiecesCountsForGroupSize(self::BUCKETS, 5));
    }

    public function testGroupSizeOfTwoIsAPair(): void
    {
        self::assertEquals(
            $this->query->byPiecesCounts(self::BUCKETS, PuzzlingType::Duo),
            $this->query->byPiecesCountsForGroupSize(self::BUCKETS, 2),
        );
    }

    public function testSanityFiltersApplyToGroupsToo(): void
    {
        $connection = self::getContainer()->get(Connection::class);

        // 9 minutes for 1000 pieces is below the 0.6 s/piece floor - a typo, not a record
        $connection->executeStatement(
            'UPDATE puzzle_solving_time SET seconds_to_solve = 540 WHERE id = :id',
            ['id' => PuzzleSolvingTimeFixture::TIME_12],
        );
        $connection->executeStatement(
            'UPDATE puzzle_solving_time SET unboxed = true WHERE id = :id',
            ['id' => PuzzleSolvingTimeFixture::TIME_41],
        );

        self::assertSame([], $this->query->byPiecesCounts(self::BUCKETS, PuzzlingType::Duo));
    }
}
