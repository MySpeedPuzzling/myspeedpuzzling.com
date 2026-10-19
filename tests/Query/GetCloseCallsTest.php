<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Query\GetCloseCalls;
use SpeedPuzzling\Web\Query\GetRanking;
use SpeedPuzzling\Web\Services\HiddenPlayers;
use SpeedPuzzling\Web\Services\PrivateProfileAccess;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\TestingViewer;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Close calls read only the times faster than the player's - their places must equal the leaderboards'
 * (GetRanking::allForPlayer(), the puzzle page's pool), for every player and viewer.
 */
final class GetCloseCallsTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function players(): iterable
    {
        yield 'regular' => [PlayerFixture::PLAYER_REGULAR];
        yield 'private' => [PlayerFixture::PLAYER_PRIVATE];
        yield 'admin' => [PlayerFixture::PLAYER_ADMIN];
        yield 'favorites' => [PlayerFixture::PLAYER_WITH_FAVORITES];
        yield 'stripe' => [PlayerFixture::PLAYER_WITH_STRIPE];
    }

    #[DataProvider('players')]
    public function testThePlacesEqualTheLeaderboards(string $playerId): void
    {
        self::bootKernel();

        // Without a viewer (nobody hidden, nobody revealed) and as the Insights page sees it: the player themselves
        $this->assertParity($playerId);

        TestingViewer::signIn(self::getContainer(), $playerId);
        $this->resetViewerCaches();
        $this->assertParity($playerId);
    }

    public function testABlockedPlayerLeavesThePlacesToo(): void
    {
        self::bootKernel();
        $this->block(PlayerFixture::PLAYER_WITH_FAVORITES, PlayerFixture::PLAYER_ADMIN);
        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_WITH_FAVORITES);
        $this->resetViewerCaches();

        $this->assertParity(PlayerFixture::PLAYER_WITH_FAVORITES);

        // PUZZLE_500_01 as this viewer sees it: the blocked admin left out, PLAYER_PRIVATE (1400) revealed to them by
        // her allow list - 1400, 1750, 2100, 3000 (you)
        $candidate = self::getContainer()->get(GetCloseCalls::class)->forPlayer(PlayerFixture::PLAYER_WITH_FAVORITES)[PuzzleFixture::PUZZLE_500_01];
        self::assertSame(4, $candidate->rank);
        self::assertSame([1 => 1400, 3 => 2100], $candidate->milestoneSeconds);
    }

    public function testTheTimesOfThePlacesAbove(): void
    {
        self::bootKernel();

        // PUZZLE_500_01: admin 1200, private 1400 (left out), regular 1750, stripe 2100, favorites 3000
        $candidate = self::getContainer()->get(GetCloseCalls::class)->forPlayer(PlayerFixture::PLAYER_WITH_FAVORITES)[PuzzleFixture::PUZZLE_500_01];

        self::assertSame(3000, $candidate->bestSeconds);
        self::assertSame(4, $candidate->rank);
        self::assertSame(2100, $candidate->nextPlaceSeconds);
        self::assertSame([1 => 1200, 3 => 2100], $candidate->milestoneSeconds);

        $first = self::getContainer()->get(GetCloseCalls::class)->forPlayer(PlayerFixture::PLAYER_ADMIN)[PuzzleFixture::PUZZLE_500_01];
        self::assertSame(1, $first->rank);
        self::assertNull($first->nextPlaceSeconds);
        self::assertSame([], $first->milestoneSeconds);
    }

    private function assertParity(string $playerId): void
    {
        $container = self::getContainer();
        $ranking = $container->get(GetRanking::class);
        $ranking->reset();

        /** @var list<string> $secret */
        $secret = $container->get(Connection::class)->fetchFirstColumn(
            'SELECT id::text FROM puzzle WHERE hide_until > NOW() OR hide_image_until > NOW()',
        );

        $expected = [];

        foreach ($ranking->allForPlayer($playerId) as $puzzleId => $entry) {
            if (in_array($puzzleId, $secret, true) === false) {
                $expected[$puzzleId] = $entry->rank;
            }
        }

        $actual = [];

        foreach ($container->get(GetCloseCalls::class)->forPlayer($playerId) as $puzzleId => $candidate) {
            $actual[$puzzleId] = $candidate->rank;
        }

        ksort($expected);
        ksort($actual);

        self::assertNotSame([], $expected);
        self::assertSame($expected, $actual);
    }

    private function resetViewerCaches(): void
    {
        self::getContainer()->get(HiddenPlayers::class)->reset();
        self::getContainer()->get(PrivateProfileAccess::class)->reset();
    }

    private function block(string $blockerId, string $blockedId): void
    {
        self::getContainer()->get(Connection::class)->executeStatement(
            "INSERT INTO user_block (id, blocker_id, blocked_id, blocked_at, source) VALUES (:id, :blocker, :blocked, NOW(), 'self')",
            ['id' => Uuid::uuid7()->toString(), 'blocker' => $blockerId, 'blocked' => $blockedId],
        );
    }
}
