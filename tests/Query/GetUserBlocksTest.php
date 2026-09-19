<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Query\GetUserBlocks;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * UserBlockFixture: PLAYER_REGULAR blocks PLAYER_PRIVATE.
 */
final class GetUserBlocksTest extends KernelTestCase
{
    private GetUserBlocks $query;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->query = $container->get(GetUserBlocks::class);
        $this->database = $container->get(Connection::class);
    }

    public function testBlockersOfAnyOfThePlayersOnceEach(): void
    {
        $this->block(PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_ADMIN);
        $this->block(PlayerFixture::PLAYER_WITH_STRIPE, PlayerFixture::PLAYER_ADMIN);

        self::assertSame([], $this->query->blockersOf([]));
        self::assertSame([], $this->query->blockersOf([PlayerFixture::PLAYER_REGULAR]));
        self::assertSame([PlayerFixture::PLAYER_REGULAR], $this->query->blockersOf([PlayerFixture::PLAYER_PRIVATE]));
        self::assertEqualsCanonicalizing(
            [PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_WITH_STRIPE],
            $this->query->blockersOf([PlayerFixture::PLAYER_PRIVATE, PlayerFixture::PLAYER_ADMIN]),
        );
    }

    private function block(string $blockerId, string $blockedId): void
    {
        $this->database->executeStatement(
            "INSERT INTO user_block (id, blocker_id, blocked_id, blocked_at, source) VALUES (:id, :blocker, :blocked, NOW(), 'self')",
            ['id' => Uuid::uuid7()->toString(), 'blocker' => $blockerId, 'blocked' => $blockedId],
        );
    }
}
