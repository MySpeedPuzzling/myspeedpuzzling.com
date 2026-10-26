<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use SpeedPuzzling\Web\Tests\TestClock;
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

    public function testForPuzzlesIsAllForPlayerNarrowedToThem(): void
    {
        $all = $this->query->allForPlayer(PlayerFixture::PLAYER_REGULAR);
        self::assertGreaterThan(2, count($all));
        $wanted = array_slice(array_keys($all), 1, 2);
        $this->query->reset();

        $some = $this->query->forPuzzles(PlayerFixture::PLAYER_REGULAR, [...$wanted, Uuid::uuid7()->toString()]);

        ksort($some);
        $expected = array_intersect_key($all, array_flip($wanted));
        ksort($expected);
        self::assertEquals($expected, $some);
        self::assertSame([], $this->query->forPuzzles(PlayerFixture::PLAYER_REGULAR, []));
    }

    public function testCachedRanksAreAllForPlayer(): void
    {
        $all = $this->query->allForPlayer(PlayerFixture::PLAYER_REGULAR);
        $this->query->reset();

        self::assertEquals($all, $this->query->allForPlayerCached(PlayerFixture::PLAYER_REGULAR, Uuid::uuid7()->toString()));
        // The request's copy serves the shown rows' ranks too
        self::assertEquals(
            array_intersect_key($all, [PuzzleFixture::PUZZLE_500_01 => true]),
            $this->query->forPuzzles(PlayerFixture::PLAYER_REGULAR, [PuzzleFixture::PUZZLE_500_01]),
        );
    }

    public function testCachedRanksMoveWithTheFingerprintOfThePlayersOwnResults(): void
    {
        $fingerprint = Uuid::uuid7()->toString();
        self::assertSame(2, $this->query->allForPlayerCached(PlayerFixture::PLAYER_REGULAR, $fingerprint)[PuzzleFixture::PUZZLE_500_01]->rank);

        // Somebody else's time changes: the next request keeps the cached ranks for CACHE_TTL_SECONDS
        $this->markSuspicious(PuzzleSolvingTimeFixture::TIME_32);
        $this->query->reset();
        self::assertSame(2, $this->query->allForPlayerCached(PlayerFixture::PLAYER_REGULAR, $fingerprint)[PuzzleFixture::PUZZLE_500_01]->rank);

        // Another fingerprint (the player's own results changed) counts again
        $this->query->reset();
        self::assertSame(1, $this->query->allForPlayerCached(PlayerFixture::PLAYER_REGULAR, Uuid::uuid7()->toString())[PuzzleFixture::PUZZLE_500_01]->rank);
    }

    public function testEqualBestTimesShareARank(): void
    {
        // PUZZLE_500_01, public pool: PLAYER_ADMIN 1200, PLAYER_REGULAR 1750, PLAYER_WITH_STRIPE 2100, PLAYER_WITH_FAVORITES 3000 -> 1750
        $this->setSoloTime(PlayerFixture::PLAYER_WITH_FAVORITES, PuzzleFixture::PUZZLE_500_01, 1750);

        $regular = $this->query->allForPlayer(PlayerFixture::PLAYER_REGULAR)[PuzzleFixture::PUZZLE_500_01];
        $favorites = $this->query->allForPlayer(PlayerFixture::PLAYER_WITH_FAVORITES)[PuzzleFixture::PUZZLE_500_01];
        $stripe = $this->query->allForPlayer(PlayerFixture::PLAYER_WITH_STRIPE)[PuzzleFixture::PUZZLE_500_01];

        self::assertSame([2, 4], [$regular->rank, $regular->totalPlayers]);
        self::assertSame([2, 4], [$favorites->rank, $favorites->totalPlayers]);
        self::assertSame(1750, $favorites->time);
        // Shared ranks skip numbers, like RANK() and the puzzle page
        self::assertSame([4, 4], [$stripe->rank, $stripe->totalPlayers]);
    }

    public function testAPlayerTheViewerHidesRanksNowhere(): void
    {
        $this->block(PlayerFixture::PLAYER_WITH_STRIPE, PlayerFixture::PLAYER_REGULAR);
        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_WITH_STRIPE);

        self::assertSame([], $this->query->allForPlayer(PlayerFixture::PLAYER_REGULAR));
    }

    public function testAZeroSecondTimeIsNoTimeInThePool(): void
    {
        // Only the API could store 00:00 (the web form keeps 0 s as no time); like the Times boards it ranks nobody
        $this->setSoloTime(PlayerFixture::PLAYER_WITH_STRIPE, PuzzleFixture::PUZZLE_500_01, 0);

        $regular = $this->query->allForPlayer(PlayerFixture::PLAYER_REGULAR)[PuzzleFixture::PUZZLE_500_01];
        self::assertSame([2, 3], [$regular->rank, $regular->totalPlayers]);
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

    private function setSoloTime(string $playerId, string $puzzleId, int $seconds): void
    {
        self::getContainer()->get(Connection::class)->executeStatement(
            "UPDATE puzzle_solving_time SET seconds_to_solve = :seconds WHERE player_id = :playerId AND puzzle_id = :puzzleId AND puzzling_type = 'solo'",
            ['seconds' => $seconds, 'playerId' => $playerId, 'puzzleId' => $puzzleId],
        );
    }

    private function block(string $blockerId, string $blockedId): void
    {
        self::getContainer()->get(Connection::class)->executeStatement(
            "INSERT INTO user_block (id, blocker_id, blocked_id, blocked_at, source) VALUES (:id, :blocker, :blocked, " . TestClock::SQL_NOW . ", 'self')",
            ['id' => Uuid::uuid7()->toString(), 'blocker' => $blockerId, 'blocked' => $blockedId],
        );
    }
}
