<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use SpeedPuzzling\Web\Query\GetPlayerStatsSnapshot;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GetPlayerStatsSnapshotTest extends KernelTestCase
{
    private GetPlayerStatsSnapshot $query;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->query = self::getContainer()->get(GetPlayerStatsSnapshot::class);
    }

    public function testReturnsSnapshotForPlayerWithSolveTimes(): void
    {
        $snapshot = $this->query->forPlayer(PlayerFixture::PLAYER_REGULAR);

        self::assertSame(PlayerFixture::PLAYER_REGULAR, $snapshot->playerId);
        self::assertGreaterThan(0, $snapshot->distinctPuzzlesSolved);
        self::assertGreaterThan(0, $snapshot->totalPiecesSolved);
        self::assertNotNull($snapshot->best500PieceSoloSeconds);
        self::assertGreaterThan(0, $snapshot->best500PieceSoloSeconds);
        self::assertGreaterThanOrEqual(0, $snapshot->allTimeLongestStreakDays);
        self::assertGreaterThanOrEqual(0, $snapshot->teamSolvesCount);
    }

    public function testReturnsZerosForNonExistentPlayer(): void
    {
        $snapshot = $this->query->forPlayer('00000000-0000-0000-0000-000000000099');

        self::assertSame(0, $snapshot->distinctPuzzlesSolved);
        self::assertSame(0, $snapshot->totalPiecesSolved);
        self::assertNull($snapshot->best500PieceSoloSeconds);
        self::assertSame(0, $snapshot->allTimeLongestStreakDays);
        self::assertSame(0, $snapshot->teamSolvesCount);
    }

    public function testBest500PieceSoloSecondsIsSmallestValue(): void
    {
        // PLAYER_REGULAR has multiple 500pc solo solves; verify we get the fastest
        $snapshot = $this->query->forPlayer(PlayerFixture::PLAYER_REGULAR);

        self::assertNotNull($snapshot->best500PieceSoloSeconds);
        // The fastest 500pc time in fixtures is 1700s (TIME_08) for PLAYER_REGULAR
        self::assertLessThanOrEqual(1800, $snapshot->best500PieceSoloSeconds);
    }

    public function testPiecesSolvedCountsAllParticipation(): void
    {
        // PLAYER_REGULAR has solo + team solves across multiple piece counts
        $snapshot = $this->query->forPlayer(PlayerFixture::PLAYER_REGULAR);

        // Multiple puzzles of 500, 1000, 1500, 2000 pieces = well over 10,000 total
        self::assertGreaterThan(5000, $snapshot->totalPiecesSolved);
    }

    public function testPlayerWithOnlySoloSolvesHasZeroTeamCount(): void
    {
        // PLAYER_WITH_FAVORITES has only solo solves in fixtures
        $snapshot = $this->query->forPlayer(PlayerFixture::PLAYER_WITH_FAVORITES);

        self::assertSame(0, $snapshot->teamSolvesCount);
    }

    public function testPlayerWithTeamSolvesCountsThem(): void
    {
        // PLAYER_REGULAR has at least TIME_12 and TIME_41 as team solves
        $snapshot = $this->query->forPlayer(PlayerFixture::PLAYER_REGULAR);

        self::assertGreaterThanOrEqual(2, $snapshot->teamSolvesCount);
    }
}
