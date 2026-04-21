<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Query\GetRanking;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
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
        // PUZZLE_500_01 best solo times per player (no verified/suspicious filter):
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
}
