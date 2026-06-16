<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
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
        $this->connection = $container->get(EntityManagerInterface::class)->getConnection();
    }

    public function testReturnsKnownBadgesAndSkipsLegacyTypes(): void
    {
        $this->insertBadge(PlayerFixture::PLAYER_REGULAR, 'supporter');
        // A legacy type left over in the database that is no longer part of the enum.
        $this->insertBadge(PlayerFixture::PLAYER_REGULAR, 'pieces_solved');

        $badges = $this->query->forPlayer(PlayerFixture::PLAYER_REGULAR);

        self::assertSame([BadgeType::Supporter], $badges);
    }

    public function testReturnsEmptyWhenOnlyLegacyBadgesExist(): void
    {
        $this->insertBadge(PlayerFixture::PLAYER_PRIVATE, 'speed_500_pieces');

        self::assertSame([], $this->query->forPlayer(PlayerFixture::PLAYER_PRIVATE));
    }

    private function insertBadge(string $playerId, string $type): void
    {
        $this->connection->insert('badge', [
            'id' => Uuid::uuid7()->toString(),
            'player_id' => $playerId,
            'type' => $type,
            'earned_at' => '2024-01-01 00:00:00',
        ]);
    }
}
