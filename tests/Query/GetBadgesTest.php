<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Query\GetBadges;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Value\BadgeType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GetBadgesTest extends KernelTestCase
{
    private GetBadges $query;
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->query = $container->get(GetBadges::class);
        $this->connection = $container->get(Connection::class);
    }

    public function testReturnsKnownBadges(): void
    {
        $this->insertBadge(PlayerFixture::PLAYER_REGULAR, BadgeType::Supporter->value);

        $badges = $this->query->forPlayer(PlayerFixture::PLAYER_REGULAR);

        self::assertCount(1, $badges);
        self::assertSame(BadgeType::Supporter, $badges[0]->type);
        self::assertNull($badges[0]->tier);
        self::assertSame('2026-01-01 12:00:00', $badges[0]->earnedAt->format('Y-m-d H:i:s'));
    }

    public function testUnknownBadgeTypesInDatabaseAreSkipped(): void
    {
        $this->insertBadge(PlayerFixture::PLAYER_REGULAR, BadgeType::Supporter->value);
        $this->insertBadge(PlayerFixture::PLAYER_REGULAR, 'removed_experimental_badge');

        $badges = $this->query->forPlayer(PlayerFixture::PLAYER_REGULAR);

        self::assertCount(1, $badges);
        self::assertSame(BadgeType::Supporter, $badges[0]->type);
    }

    public function testReturnsEmptyArrayForPlayerWithNoBadges(): void
    {
        // No badge fixtures exist — empty is correct
        self::assertSame([], $this->query->forPlayer(PlayerFixture::PLAYER_REGULAR));
        self::assertSame([], $this->query->forPlayer(PlayerFixture::PLAYER_ADMIN));
    }

    public function testReturnsEmptyArrayForNonExistentPlayer(): void
    {
        $badges = $this->query->forPlayer('00000000-0000-0000-0000-000000000099');

        self::assertSame([], $badges);
    }

    private function insertBadge(string $playerId, string $type): void
    {
        $this->connection->executeStatement(
            'INSERT INTO badge (id, player_id, type, earned_at) VALUES (:id, :playerId, :type, :earnedAt)',
            [
                'id' => Uuid::uuid7()->toString(),
                'playerId' => $playerId,
                'type' => $type,
                'earnedAt' => '2026-01-01 12:00:00',
            ],
        );
    }
}
