<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\ConsoleCommands;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class CleanupEmailAuditLogsCommandTest extends KernelTestCase
{
    private CommandTester $commandTester;
    private Connection $connection;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $application = new Application($kernel);
        $command = $application->find('myspeedpuzzling:cleanup-email-audit-logs');
        $this->commandTester = new CommandTester($command);
        $this->connection = self::getContainer()->get(Connection::class);
    }

    public function testCommandRunsSuccessfully(): void
    {
        $this->commandTester->execute([]);

        self::assertSame(0, $this->commandTester->getStatusCode());
        self::assertStringContainsString('Deleted', $this->commandTester->getDisplay());
    }

    public function testDeletesOldEntries(): void
    {
        $oldId = Uuid::uuid7()->toString();
        $recentId = Uuid::uuid7()->toString();

        // Insert an old entry (100 days ago)
        $this->connection->insert('email_audit_log', [
            'id' => $oldId,
            'sent_at' => (new \DateTimeImmutable('-100 days'))->format('Y-m-d H:i:s'),
            'recipient_email' => 'old@example.com',
            'subject' => 'Old email',
            'transport_name' => 'smtp://mailer:1025',
            'status' => 'sent',
        ]);

        // Insert a recent entry (10 days ago)
        $this->connection->insert('email_audit_log', [
            'id' => $recentId,
            'sent_at' => (new \DateTimeImmutable('-10 days'))->format('Y-m-d H:i:s'),
            'recipient_email' => 'recent@example.com',
            'subject' => 'Recent email',
            'transport_name' => 'smtp://mailer:1025',
            'status' => 'sent',
        ]);

        $this->commandTester->execute(['days' => '90']);

        self::assertSame(0, $this->commandTester->getStatusCode());

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

    public function testCustomDaysArgument(): void
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

        // 30 days retention should delete the 40-day-old entry
        $this->commandTester->execute(['days' => '30']);

        /** @var int|string $exists */
        $exists = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM email_audit_log WHERE id = :id',
            ['id' => $id],
        );
        self::assertSame(0, (int) $exists);
    }
}
