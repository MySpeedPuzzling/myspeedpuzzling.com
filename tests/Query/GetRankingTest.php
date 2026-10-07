<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Component\PuzzleTimes;
use SpeedPuzzling\Web\Query\GetRanking;
use SpeedPuzzling\Web\Results\PuzzleSolver;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use SpeedPuzzling\Web\Tests\TestingViewer;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GetRankingTest extends KernelTestCase
{
    private GetRanking $query;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        /** @var GetRanking $query */
        $query = $container->get(GetRanking::class);
        $this->query = $query;
    }

    public function testOfPuzzleForPlayerExcludesPrivatePeersForPublicSubject(): void
    {
        // PUZZLE_500_01 best solo times per player (no verified filter, no suspicious time in the fixtures):
        //   PLAYER_ADMIN            1200 (public)
        //   PLAYER_PRIVATE          1400 (private — must be excluded)
        //   PLAYER_REGULAR          1750 (public, subject)
        //   PLAYER_WITH_STRIPE      2100 (public)
        //   PLAYER_WITH_FAVORITES   3000 (public)
        // Without privacy filter PLAYER_REGULAR would be rank 3 of 5.
        $ranking = $this->query->ofPuzzleForPlayer(
            PuzzleFixture::PUZZLE_500_01,
            PlayerFixture::PLAYER_REGULAR,
        );

        self::assertNotNull($ranking);
        self::assertSame(2, $ranking->rank);
        self::assertSame(4, $ranking->totalPlayers);
    }

    public function testOfPuzzleForPlayerIncludesPrivateSubjectInOwnPool(): void
    {
        // PLAYER_PRIVATE viewing themselves on PUZZLE_500_01 must include self.
        // Pool: all public + self → 5 players, PLAYER_PRIVATE at rank 2 (1400 vs PLAYER_ADMIN 1200).
        $ranking = $this->query->ofPuzzleForPlayer(
            PuzzleFixture::PUZZLE_500_01,
            PlayerFixture::PLAYER_PRIVATE,
        );

        self::assertNotNull($ranking);
        self::assertSame(2, $ranking->rank);
        self::assertSame(5, $ranking->totalPlayers);
    }

    public function testOfPuzzleForPlayerReturnsNullForPlayerWhoDidNotSolve(): void
    {
        // Random uuid7 — guaranteed not in fixtures.
        $randomPlayerId = Uuid::uuid7()->toString();

        $ranking = $this->query->ofPuzzleForPlayer(
            PuzzleFixture::PUZZLE_500_01,
            $randomPlayerId,
        );

        self::assertNull($ranking);
    }

    public function testAllForPlayerExcludesPrivatePeers(): void
    {
        // PUZZLE_500_01 only — verify the PLAYER_REGULAR row matches the per-puzzle method.
        $rankings = $this->query->allForPlayer(PlayerFixture::PLAYER_REGULAR);

        self::assertArrayHasKey(PuzzleFixture::PUZZLE_500_01, $rankings);
        $entry = $rankings[PuzzleFixture::PUZZLE_500_01];
        self::assertSame(2, $entry->rank);
        self::assertSame(4, $entry->totalPlayers);
    }

    public function testAllForPlayerIncludesPrivateSubject(): void
    {
        $rankings = $this->query->allForPlayer(PlayerFixture::PLAYER_PRIVATE);

        // The private subject must see their own puzzles' rankings.
        self::assertArrayHasKey(PuzzleFixture::PUZZLE_500_01, $rankings);
        $entry = $rankings[PuzzleFixture::PUZZLE_500_01];
        self::assertSame(2, $entry->rank);
        self::assertSame(5, $entry->totalPlayers);
    }

    public function testBlockedPlayerLeavesTheRankedPool(): void
    {
        // PUZZLE_500_01, public pool: PLAYER_ADMIN 1200, PLAYER_REGULAR 1750, PLAYER_WITH_STRIPE 2100, PLAYER_WITH_FAVORITES 3000
        $this->block(PlayerFixture::PLAYER_WITH_STRIPE, PlayerFixture::PLAYER_ADMIN);
        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_WITH_STRIPE);

        $ranking = $this->query->ofPuzzleForPlayer(PuzzleFixture::PUZZLE_500_01, PlayerFixture::PLAYER_REGULAR);
        self::assertNotNull($ranking);
        self::assertSame(1, $ranking->rank);
        self::assertSame(3, $ranking->totalPlayers);

        $all = $this->query->allForPlayer(PlayerFixture::PLAYER_REGULAR);
        self::assertSame(1, $all[PuzzleFixture::PUZZLE_500_01]->rank);
        self::assertSame(3, $all[PuzzleFixture::PUZZLE_500_01]->totalPlayers);

        // Another viewer still gets the whole pool
        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_REGULAR);
        $this->query->reset();

        $ranking = $this->query->ofPuzzleForPlayer(PuzzleFixture::PUZZLE_500_01, PlayerFixture::PLAYER_REGULAR);
        self::assertNotNull($ranking);
        self::assertSame(2, $ranking->rank);
        self::assertSame(4, $ranking->totalPlayers);
        self::assertSame(2, $this->query->allForPlayer(PlayerFixture::PLAYER_REGULAR)[PuzzleFixture::PUZZLE_500_01]->rank);
    }

    public function testPrivatePlayerCountsForTheViewersSheAllows(): void
    {
        // PLAYER_PRIVATE (1400) lets PLAYER_WITH_FAVORITES see her - the puzzle page lists her for that viewer, so the rank counts her
        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_WITH_FAVORITES);

        $ranking = $this->query->ofPuzzleForPlayer(PuzzleFixture::PUZZLE_500_01, PlayerFixture::PLAYER_REGULAR);
        self::assertNotNull($ranking);
        self::assertSame(3, $ranking->rank);
        self::assertSame(5, $ranking->totalPlayers);
    }

    public function testSuspiciousTimesLeaveTheRankedPool(): void
    {
        // PLAYER_ADMIN's 1200 (TIME_32) turns suspicious - their next best is 1780, slower than PLAYER_REGULAR's 1750
        $this->markSuspicious(PuzzleSolvingTimeFixture::TIME_32);

        $ranking = $this->query->ofPuzzleForPlayer(PuzzleFixture::PUZZLE_500_01, PlayerFixture::PLAYER_REGULAR);
        self::assertNotNull($ranking);
        self::assertSame(1, $ranking->rank);
        self::assertSame(4, $ranking->totalPlayers);
        self::assertSame(1, $this->query->allForPlayer(PlayerFixture::PLAYER_REGULAR)[PuzzleFixture::PUZZLE_500_01]->rank);
    }

    /**
     * The profile shows the rank the puzzle page shows: every row of the solo leaderboard, with a suspicious time in it
     */
    #[DataProvider('provideSoloLeaderboards')]
    public function testRankEqualsTheLeaderboard(string $puzzleId): void
    {
        $this->markSuspicious(PuzzleSolvingTimeFixture::TIME_32);

        $component = self::getContainer()->get(PuzzleTimes::class);
        $component->puzzleId = $puzzleId;
        $component->category = 'solo';
        $component->populate();
        self::assertNotEmpty($component->times);

        foreach ($component->times as $rowKey => $grouped) {
            $best = $grouped[0];
            self::assertInstanceOf(PuzzleSolver::class, $best);

            $this->query->reset();
            $ranking = $this->query->ofPuzzleForPlayer($puzzleId, $best->playerId);
            self::assertNotNull($ranking, "Row {$rowKey}");
            self::assertSame($component->ranks[$rowKey], $ranking->rank, "Rank of {$rowKey}");
            self::assertSame(count($component->times), $ranking->totalPlayers, "Total for {$rowKey}");
            self::assertSame($ranking->rank, $this->query->allForPlayer($best->playerId)[$puzzleId]->rank, "Profile rank of {$rowKey}");
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideSoloLeaderboards(): iterable
    {
        yield '500_01' => [PuzzleFixture::PUZZLE_500_01];
        yield '500_02' => [PuzzleFixture::PUZZLE_500_02];
        yield '1000_01' => [PuzzleFixture::PUZZLE_1000_01];
    }

    private function markSuspicious(string $timeId): void
    {
        self::getContainer()->get(Connection::class)->executeStatement(
            'UPDATE puzzle_solving_time SET suspicious = true WHERE id = :id',
            ['id' => $timeId],
        );
    }

    private function block(string $blockerId, string $blockedId): void
    {
        self::getContainer()->get(Connection::class)->executeStatement(
            "INSERT INTO user_block (id, blocker_id, blocked_id, blocked_at, source) VALUES (:id, :blocker, :blocked, NOW(), 'self')",
            ['id' => Uuid::uuid7()->toString(), 'blocker' => $blockerId, 'blocked' => $blockedId],
        );
    }
}
