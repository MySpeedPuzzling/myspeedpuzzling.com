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
    private GetBadges $getBadges;
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->getBadges = $container->get(GetBadges::class);
        $this->connection = $container->get(Connection::class);
    }

    public function testReturnsKnownBadges(): void
    {
        $this->insertBadge(PlayerFixture::PLAYER_REGULAR, BadgeType::Supporter->value);

        $badges = $this->getBadges->forPlayer(PlayerFixture::PLAYER_REGULAR);

        self::assertSame([BadgeType::Supporter], $badges);
    }

    public function testUnknownBadgeTypesInDatabaseAreSkipped(): void
    {
        $this->insertBadge(PlayerFixture::PLAYER_REGULAR, BadgeType::Supporter->value);
        $this->insertBadge(PlayerFixture::PLAYER_REGULAR, 'pieces_solved');

        $badges = $this->getBadges->forPlayer(PlayerFixture::PLAYER_REGULAR);

        self::assertSame([BadgeType::Supporter], $badges);
    }

    public function testReturnsEmptyListForPlayerWithoutBadges(): void
    {
        self::assertSame([], $this->getBadges->forPlayer(PlayerFixture::PLAYER_ADMIN));
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
