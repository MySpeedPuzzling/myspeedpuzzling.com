<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Query\GetPlayerPuzzleTimes;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The "My times" read model of collection pages must agree with the picker
 * card (same CTE semantics): every own solving time counts as a solve, team
 * participation counts too, the times themselves are solo only.
 */
final class GetPlayerPuzzleTimesTest extends KernelTestCase
{
    private GetPlayerPuzzleTimes $query;

    private ClockInterface $clock;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $this->query = $container->get(GetPlayerPuzzleTimes::class);
        $this->clock = $container->get(ClockInterface::class);
    }

    public function testRepeatedlySolvedPuzzleWhereTheLatestIsTheFastest(): void
    {
        // PLAYER_REGULAR on PUZZLE_500_02: 36:40 (20 days ago) -> 31:40 (15) -> 28:20 (10)
        $times = $this->query->forPuzzles(PlayerFixture::PLAYER_REGULAR, [PuzzleFixture::PUZZLE_500_02]);

        self::assertArrayHasKey(PuzzleFixture::PUZZLE_500_02, $times);
        $time = $times[PuzzleFixture::PUZZLE_500_02];

        self::assertSame(PuzzleFixture::PUZZLE_500_02, $time->puzzleId);
        self::assertSame(3, $time->solveCountAny);
        self::assertSame(3, $time->solveCountSolo);
        self::assertSame(1700, $time->fastestSeconds);
        self::assertSame(1700, $time->latestSeconds);
        self::assertFalse($time->latestDiffersFromFastest(), 'The template omits the latest line when it is the fastest result');
        self::assertNotNull($time->lastSolvedAt);
        self::assertSame(
            $this->clock->now()->modify('-10 days')->format('Y-m-d'),
            $time->lastSolvedAt->format('Y-m-d'),
        );
    }

    public function testLatestSlowerThanFastest(): void
    {
        // PLAYER_REGULAR on PUZZLE_1000_02: 3950 (16 days ago), then 4500 (4 days ago, competition
        // final) and an untimed relax row (3 days ago) that counts as a solve but never as a time
        $time = $this->query->forPuzzles(PlayerFixture::PLAYER_REGULAR, [PuzzleFixture::PUZZLE_1000_02])[PuzzleFixture::PUZZLE_1000_02];

        self::assertSame(3, $time->solveCountAny);
        self::assertSame(2, $time->solveCountSolo);
        self::assertSame(3950, $time->fastestSeconds);
        self::assertSame(4500, $time->latestSeconds);
        self::assertTrue($time->latestDiffersFromFastest());
        self::assertNotNull($time->lastSolvedAt);
        self::assertSame(
            $this->clock->now()->modify('-3 days')->format('Y-m-d'),
            $time->lastSolvedAt->format('Y-m-d'),
        );
    }

    public function testTeamParticipationCountsAsSolvedWithoutSoloTimes(): void
    {
        // team-002 on PUZZLE_1000_03: PLAYER_REGULAR owns the row, PLAYER_PRIVATE is only a participant
        $private = $this->query->forPuzzles(PlayerFixture::PLAYER_PRIVATE, [PuzzleFixture::PUZZLE_1000_03, PuzzleFixture::PUZZLE_1000_01]);

        self::assertSame(1, $private[PuzzleFixture::PUZZLE_1000_03]->solveCountAny);
        self::assertSame(0, $private[PuzzleFixture::PUZZLE_1000_03]->solveCountSolo);
        self::assertNull($private[PuzzleFixture::PUZZLE_1000_03]->fastestSeconds);
        self::assertNull($private[PuzzleFixture::PUZZLE_1000_03]->latestSeconds);
        self::assertNotNull($private[PuzzleFixture::PUZZLE_1000_03]->lastSolvedAt);

        // team-001 on PUZZLE_1000_01: PLAYER_PRIVATE is a participant AND has her own solo row (TIME_16)
        self::assertSame(2, $private[PuzzleFixture::PUZZLE_1000_01]->solveCountAny, 'Own solo row + team participation');
        self::assertSame(1, $private[PuzzleFixture::PUZZLE_1000_01]->solveCountSolo);
        self::assertSame(4200, $private[PuzzleFixture::PUZZLE_1000_01]->fastestSeconds);

        // The duo row PLAYER_REGULAR owns on PUZZLE_1000_01 is a solve without a solo time
        $regular = $this->query->forPuzzles(PlayerFixture::PLAYER_REGULAR, [PuzzleFixture::PUZZLE_1000_01])[PuzzleFixture::PUZZLE_1000_01];

        self::assertSame(1, $regular->solveCountAny);
        self::assertSame(0, $regular->solveCountSolo);
        self::assertNull($regular->fastestSeconds);
    }

    public function testNeverSolvedPuzzlesAreAbsentAndOnlyAskedPuzzlesAreReturned(): void
    {
        $times = $this->query->forPuzzles(PlayerFixture::PLAYER_REGULAR, [PuzzleFixture::PUZZLE_9000, PuzzleFixture::PUZZLE_500_02, PuzzleFixture::PUZZLE_500_02]);

        self::assertSame([PuzzleFixture::PUZZLE_500_02], array_keys($times));
        self::assertSame([], $this->query->forPuzzles(PlayerFixture::PLAYER_REGULAR, []));
        self::assertSame([], $this->query->forPuzzles('00000000-0000-0000-0000-000000000099', [PuzzleFixture::PUZZLE_500_02]));
    }
}
