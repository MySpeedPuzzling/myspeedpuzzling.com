<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\PrunePlayerActivity;
use SpeedPuzzling\Web\MessageHandler\PrunePlayerActivityHandler;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PrunePlayerActivityHandlerTest extends KernelTestCase
{
    private PrunePlayerActivityHandler $handler;
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->handler = $container->get(PrunePlayerActivityHandler::class);
        $this->connection = $container->get(Connection::class);
    }

    public function testDeletesOldRowsAndKeepsRecentOnes(): void
    {
        $oldId = $this->insertRow(new \DateTimeImmutable('-25 months'));
        $recentId = $this->insertRow(new \DateTimeImmutable('-1 month'));

        $deleted = ($this->handler)(new PrunePlayerActivity(retentionMonths: 24));

        self::assertGreaterThanOrEqual(1, $deleted);
        self::assertFalse($this->rowExists($oldId), 'Row beyond retention should be deleted');
        self::assertTrue($this->rowExists($recentId), 'Recent row should remain');
    }

    private function insertRow(\DateTimeImmutable $day): string
    {
        $id = Uuid::uuid7()->toString();

        $this->connection->insert('player_activity_day', [
            'id' => $id,
            'player_id' => PlayerFixture::PLAYER_REGULAR,
            'day' => $day->format('Y-m-d'),
            'first_seen_at' => $day->format('Y-m-d H:i:sP'),
        ]);

        return $id;
    }

    private function rowExists(string $id): bool
    {
        /** @var int|string $count */
        $count = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM player_activity_day WHERE id = :id',
            ['id' => $id],
        );

        return (int) $count === 1;
    }
}
