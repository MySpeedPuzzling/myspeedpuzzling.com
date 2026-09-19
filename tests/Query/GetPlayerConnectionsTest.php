<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Query\GetPlayerConnections;
use SpeedPuzzling\Web\Results\PlayerConnection;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingViewer;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * PlayerFixture: PLAYER_WITH_FAVORITES follows PLAYER_REGULAR and PLAYER_ADMIN.
 */
final class GetPlayerConnectionsTest extends KernelTestCase
{
    private GetPlayerConnections $query;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->query = $container->get(GetPlayerConnections::class);
        $this->database = $container->get(Connection::class);
    }

    public function testHiddenFavoriteIsLeftOutAndTheCountAgrees(): void
    {
        $this->block(PlayerFixture::PLAYER_WITH_STRIPE, PlayerFixture::PLAYER_REGULAR);

        self::assertContains(PlayerFixture::PLAYER_REGULAR, $this->ids($this->query->favoritesOf(PlayerFixture::PLAYER_WITH_FAVORITES)));
        $countBefore = $this->query->countsOf(PlayerFixture::PLAYER_WITH_FAVORITES)->favorites;

        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_ADMIN);
        self::assertContains(PlayerFixture::PLAYER_REGULAR, $this->ids($this->query->favoritesOf(PlayerFixture::PLAYER_WITH_FAVORITES)));
        self::assertSame($countBefore, $this->query->countsOf(PlayerFixture::PLAYER_WITH_FAVORITES)->favorites);

        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_WITH_STRIPE);
        $favorites = $this->ids($this->query->favoritesOf(PlayerFixture::PLAYER_WITH_FAVORITES));
        self::assertNotContains(PlayerFixture::PLAYER_REGULAR, $favorites);
        self::assertContains(PlayerFixture::PLAYER_ADMIN, $favorites);
        self::assertCount($countBefore - 1, $favorites);
        self::assertSame(count($favorites), $this->query->countsOf(PlayerFixture::PLAYER_WITH_FAVORITES)->favorites);
    }

    public function testHiddenFollowerIsLeftOutAndTheCountAgrees(): void
    {
        $this->block(PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_WITH_FAVORITES);

        $followersBefore = $this->ids($this->query->followersOf(PlayerFixture::PLAYER_ADMIN));
        self::assertContains(PlayerFixture::PLAYER_WITH_FAVORITES, $followersBefore);
        self::assertSame(count($followersBefore), $this->query->countsOf(PlayerFixture::PLAYER_ADMIN)->followers);

        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_REGULAR);
        $followers = $this->ids($this->query->followersOf(PlayerFixture::PLAYER_ADMIN));
        self::assertNotContains(PlayerFixture::PLAYER_WITH_FAVORITES, $followers);
        self::assertCount(count($followersBefore) - 1, $followers);
        self::assertSame(count($followers), $this->query->countsOf(PlayerFixture::PLAYER_ADMIN)->followers);
    }

    /**
     * @param list<PlayerConnection> $connections
     * @return list<string>
     */
    private function ids(array $connections): array
    {
        return array_map(static fn (PlayerConnection $connection): string => $connection->playerId, $connections);
    }

    private function block(string $blockerId, string $blockedId): void
    {
        $this->database->executeStatement(
            "INSERT INTO user_block (id, blocker_id, blocked_id, blocked_at, source) VALUES (:id, :blocker, :blocked, NOW(), 'self')",
            ['id' => Uuid::uuid7()->toString(), 'blocker' => $blockerId, 'blocked' => $blockedId],
        );
    }
}
