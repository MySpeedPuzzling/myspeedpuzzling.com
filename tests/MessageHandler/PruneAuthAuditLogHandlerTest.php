<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\PruneAuthAuditLog;
use SpeedPuzzling\Web\MessageHandler\PruneAuthAuditLogHandler;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PruneAuthAuditLogHandlerTest extends KernelTestCase
{
    private PruneAuthAuditLogHandler $handler;
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->handler = $container->get(PruneAuthAuditLogHandler::class);
        $this->connection = $container->get(Connection::class);
    }

    public function testDeletesOldEntriesAndKeepsRecentOnes(): void
    {
        $oldId = $this->insertEvent(occurredAt: new \DateTimeImmutable('-25 months'));
        $recentId = $this->insertEvent(occurredAt: new \DateTimeImmutable('-1 month'));

        $deleted = ($this->handler)(new PruneAuthAuditLog(retentionMonths: 24));

        self::assertGreaterThanOrEqual(1, $deleted);
        self::assertFalse($this->eventExists($oldId), 'Entry beyond retention should be deleted');
        self::assertTrue($this->eventExists($recentId), 'Recent entry should remain');
    }

    public function testCustomRetentionMonths(): void
    {
        $id = $this->insertEvent(occurredAt: new \DateTimeImmutable('-7 months'));

        ($this->handler)(new PruneAuthAuditLog(retentionMonths: 6));

        self::assertFalse($this->eventExists($id));
    }

    private function insertEvent(\DateTimeImmutable $occurredAt): string
    {
        $id = Uuid::uuid7()->toString();

        $this->connection->insert('auth_audit_log', [
            'id' => $id,
            'event_type' => 'login_success',
            'email' => 'prune@example.com',
            'occurred_at' => $occurredAt->format('Y-m-d H:i:sP'),
        ]);

        return $id;
    }

    private function eventExists(string $id): bool
    {
        /** @var int|string $count */
        $count = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM auth_audit_log WHERE id = :id',
            ['id' => $id],
        );

        return (int) $count === 1;
    }
}
