<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\CleanupEmailAuditLogs;
use SpeedPuzzling\Web\MessageHandler\CleanupEmailAuditLogsHandler;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CleanupEmailAuditLogsHandlerTest extends KernelTestCase
{
    private CleanupEmailAuditLogsHandler $handler;
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->handler = $container->get(CleanupEmailAuditLogsHandler::class);
        $this->connection = $container->get(Connection::class);
    }

    public function testDeletesOldEntries(): void
    {
        $oldId = Uuid::uuid7()->toString();
        $recentId = Uuid::uuid7()->toString();

        $this->connection->insert('email_audit_log', [
            'id' => $oldId,
            'sent_at' => (new \DateTimeImmutable('-100 days'))->format('Y-m-d H:i:s'),
            'recipient_email' => 'old@example.com',
            'subject' => 'Old email',
            'transport_name' => 'smtp://mailer:1025',
            'status' => 'sent',
        ]);

        $this->connection->insert('email_audit_log', [
            'id' => $recentId,
            'sent_at' => (new \DateTimeImmutable('-10 days'))->format('Y-m-d H:i:s'),
            'recipient_email' => 'recent@example.com',
            'subject' => 'Recent email',
            'transport_name' => 'smtp://mailer:1025',
            'status' => 'sent',
        ]);

        $deleted = ($this->handler)(new CleanupEmailAuditLogs(retentionDays: 90));

        self::assertGreaterThanOrEqual(1, $deleted);

        /** @var int|string $oldExists */
        $oldExists = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM email_audit_log WHERE id = :id',
            ['id' => $oldId],
        );
        /** @var int|string $recentExists */
        $recentExists = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM email_audit_log WHERE id = :id',
            ['id' => $recentId],
        );

        self::assertSame(0, (int) $oldExists, 'Old entry should be deleted');
        self::assertSame(1, (int) $recentExists, 'Recent entry should remain');
    }

    public function testCustomRetentionDays(): void
    {
        $id = Uuid::uuid7()->toString();
        $this->connection->insert('email_audit_log', [
            'id' => $id,
            'sent_at' => (new \DateTimeImmutable('-40 days'))->format('Y-m-d H:i:s'),
            'recipient_email' => 'test@example.com',
            'subject' => 'Test',
            'transport_name' => 'smtp://mailer:1025',
            'status' => 'sent',
        ]);

        ($this->handler)(new CleanupEmailAuditLogs(retentionDays: 30));

        /** @var int|string $exists */
        $exists = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM email_audit_log WHERE id = :id',
            ['id' => $id],
        );
        self::assertSame(0, (int) $exists);
    }
}
