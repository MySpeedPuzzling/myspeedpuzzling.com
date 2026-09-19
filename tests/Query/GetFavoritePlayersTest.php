<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Query\GetFavoritePlayers;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingViewer;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * PLAYER_WITH_FAVORITES follows PLAYER_REGULAR and PLAYER_ADMIN.
 */
final class GetFavoritePlayersTest extends KernelTestCase
{
    private GetFavoritePlayers $query;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->query = self::getContainer()->get(GetFavoritePlayers::class);
    }

    public function testSomeoneElsesFavoritesLeaveOutThePlayerTheViewerBlocks(): void
    {
        self::assertEqualsCanonicalizing(
            [PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_ADMIN],
            $this->favoritesOfPlayerWithFavorites(),
        );

        $this->block(PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_ADMIN);
        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_REGULAR);

        self::assertSame([PlayerFixture::PLAYER_REGULAR], $this->favoritesOfPlayerWithFavorites());

        // The follower's own list is untouched for themselves and for anyone else
        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_WITH_FAVORITES);

        self::assertEqualsCanonicalizing(
            [PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_ADMIN],
            $this->favoritesOfPlayerWithFavorites(),
        );
    }

    public function testMostFavoriteLeavesOutTheBlockedPlayerAndTheLimitClosesUp(): void
    {
        $everyone = array_map(static fn ($p) => $p->playerId, $this->query->mostFavorite(100));
        self::assertContains(PlayerFixture::PLAYER_ADMIN, $everyone);

        $this->block(PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_ADMIN);
        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_REGULAR);

        $visible = array_map(static fn ($p) => $p->playerId, $this->query->mostFavorite(100));
        self::assertNotContains(PlayerFixture::PLAYER_ADMIN, $visible);
        self::assertCount(count($everyone) - 1, $visible);
        self::assertCount(1, $this->query->mostFavorite(1));

        TestingViewer::signOut(self::getContainer());
        self::assertContains(PlayerFixture::PLAYER_ADMIN, array_map(static fn ($p) => $p->playerId, $this->query->mostFavorite(100)));
    }

    /**
     * @return list<string>
     */
    private function favoritesOfPlayerWithFavorites(): array
    {
        return array_values(array_map(
            static fn ($p) => $p->playerId,
            $this->query->forPlayerId(PlayerFixture::PLAYER_WITH_FAVORITES),
        ));
    }

    private function block(string $blockerId, string $blockedId): void
    {
        self::getContainer()->get(Connection::class)->executeStatement(
            "INSERT INTO user_block (id, blocker_id, blocked_id, blocked_at, source) VALUES (:id, :blocker, :blocked, NOW(), 'self')",
            ['id' => Uuid::uuid7()->toString(), 'blocker' => $blockerId, 'blocked' => $blockedId],
        );
    }
}
