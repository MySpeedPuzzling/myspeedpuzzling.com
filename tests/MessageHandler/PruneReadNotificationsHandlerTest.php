<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\PruneReadNotifications;
use SpeedPuzzling\Web\MessageHandler\PruneReadNotificationsHandler;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use SpeedPuzzling\Web\Tests\TestClock;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PruneReadNotificationsHandlerTest extends KernelTestCase
{
    private PruneReadNotificationsHandler $handler;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->handler = $container->get(PruneReadNotificationsHandler::class);
        $this->database = $container->get(Connection::class);
    }

    public function testDeletesOnlyOldReadFeedNotificationsOneBatchAtATime(): void
    {
        $oldRead = [$this->insert('SubscribedPlayerAddedTime', 100, true), $this->insert('SubscribedPlayerAddedTime', 120, true)];
        $oldUnread = $this->insert('SubscribedPlayerAddedTime', 100, false);
        $recentRead = $this->insert('SubscribedPlayerAddedTime', 10, true);
        $oldReadOtherType = $this->insert('ModeratorRoleGranted', 100, true);

        self::assertSame(1, ($this->handler)(new PruneReadNotifications(retentionDays: 90, batchSize: 1)));
        $deleted = 1 + ($this->handler)(new PruneReadNotifications(retentionDays: 90, batchSize: 1000));

        self::assertGreaterThanOrEqual(2, $deleted);
        self::assertFalse($this->exists($oldRead[0]));
        self::assertFalse($this->exists($oldRead[1]));
        self::assertTrue($this->exists($oldUnread), 'Unread notifications stay');
        self::assertTrue($this->exists($recentRead), 'Recent notifications stay');
        self::assertTrue($this->exists($oldReadOtherType), 'Other types stay');
    }

    private function insert(string $type, int $daysAgo, bool $read): string
    {
        $id = Uuid::uuid7()->toString();
        $notifiedAt = TestClock::now()->modify("-{$daysAgo} days")->format('Y-m-d H:i:s');

        $this->database->executeStatement(
            'INSERT INTO notification (id, player_id, type, notified_at, read_at, target_solving_time_id) VALUES (:id, :player, :type, :notified, :read, :time)',
            [
                'id' => $id,
                'player' => PlayerFixture::PLAYER_WITH_FAVORITES,
                'type' => $type,
                'notified' => $notifiedAt,
                'read' => $read ? $notifiedAt : null,
                'time' => $type === 'SubscribedPlayerAddedTime' ? PuzzleSolvingTimeFixture::TIME_01 : null,
            ],
        );

        return $id;
    }

    private function exists(string $id): bool
    {
        return $this->database->fetchOne('SELECT COUNT(*) FROM notification WHERE id = :id', ['id' => $id]) > 0;
    }
}
