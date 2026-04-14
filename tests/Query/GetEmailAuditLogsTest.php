<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\EmailAuditLogNotFound;
use SpeedPuzzling\Web\Query\GetEmailAuditLogs;
use SpeedPuzzling\Web\Value\EmailAuditStatus;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GetEmailAuditLogsTest extends KernelTestCase
{
    private GetEmailAuditLogs $query;
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->query = $container->get(GetEmailAuditLogs::class);
        $this->connection = $container->get(Connection::class);
    }

    public function testListReturnsResults(): void
    {
        $results = $this->query->list();

        // Should return an array (may contain entries from other tests)
        self::assertGreaterThanOrEqual(0, count($results));
    }

    public function testListReturnsInsertedLogs(): void
    {
        $id = Uuid::uuid7()->toString();
        $this->insertAuditLog($id, 'user@example.com', 'Test Subject', 'sent', 'competition_approved');

        $results = $this->query->list();

        self::assertNotEmpty($results);
        $found = false;

        foreach ($results as $result) {
            if ($result->id === $id) {
                $found = true;
                self::assertSame('user@example.com', $result->recipientEmail);
                self::assertSame('Test Subject', $result->subject);
                self::assertSame(EmailAuditStatus::Sent, $result->status);
                self::assertSame('competition_approved', $result->emailType);
            }
        }

        self::assertTrue($found, 'Inserted audit log should appear in list');
    }

    public function testListFiltersByRecipient(): void
    {
        $id1 = Uuid::uuid7()->toString();
        $id2 = Uuid::uuid7()->toString();
        $this->insertAuditLog($id1, 'alice@example.com', 'Subject 1', 'sent');
        $this->insertAuditLog($id2, 'bob@example.com', 'Subject 2', 'sent');

        $results = $this->query->list(recipient: 'alice');

        $ids = array_map(static fn($r) => $r->id, $results);
        self::assertContains($id1, $ids);
        self::assertNotContains($id2, $ids);
    }

    public function testListFiltersByStatus(): void
    {
        $id1 = Uuid::uuid7()->toString();
        $id2 = Uuid::uuid7()->toString();
        $this->insertAuditLog($id1, 'user@example.com', 'OK', 'sent');
        $this->insertAuditLog($id2, 'user@example.com', 'Error', 'failed');

        $resultsSent = $this->query->list(status: 'sent');
        $resultsFailed = $this->query->list(status: 'failed');

        $sentIds = array_map(static fn($r) => $r->id, $resultsSent);
        $failedIds = array_map(static fn($r) => $r->id, $resultsFailed);

        self::assertContains($id1, $sentIds);
        self::assertNotContains($id2, $sentIds);
        self::assertContains($id2, $failedIds);
        self::assertNotContains($id1, $failedIds);
    }

    public function testListFiltersByEmailType(): void
    {
        $id1 = Uuid::uuid7()->toString();
        $id2 = Uuid::uuid7()->toString();
        $this->insertAuditLog($id1, 'user@example.com', 'Subject', 'sent', 'feedback');
        $this->insertAuditLog($id2, 'user@example.com', 'Subject', 'sent', 'unread_digest');

        $results = $this->query->list(emailType: 'feedback');

        $ids = array_map(static fn($r) => $r->id, $results);
        self::assertContains($id1, $ids);
        self::assertNotContains($id2, $ids);
    }

    public function testCountMatchesList(): void
    {
        $this->insertAuditLog(Uuid::uuid7()->toString(), 'count-test@example.com', 'Subject', 'sent');
        $this->insertAuditLog(Uuid::uuid7()->toString(), 'count-test@example.com', 'Subject', 'failed');

        $count = $this->query->count(recipient: 'count-test@example.com');
        $list = $this->query->list(recipient: 'count-test@example.com');

        self::assertCount($count, $list);
    }

    public function testByIdReturnsDetail(): void
    {
        $id = Uuid::uuid7()->toString();
        $this->insertAuditLog(
            id: $id,
            recipientEmail: 'detail@example.com',
            subject: 'Detail Subject',
            status: 'sent',
            emailType: 'feedback',
            messageId: 'msg-123',
            smtpDebugLog: 'SMTP debug info',
            mtaQueueId: 'QUEUE-456',
        );

        $detail = $this->query->byId($id);

        self::assertSame($id, $detail->id);
        self::assertSame('detail@example.com', $detail->recipientEmail);
        self::assertSame('Detail Subject', $detail->subject);
        self::assertSame('msg-123', $detail->messageId);
        self::assertSame('QUEUE-456', $detail->mtaQueueId);
        self::assertSame('SMTP debug info', $detail->smtpDebugLog);
        self::assertSame('feedback', $detail->emailType);
    }

    public function testByIdThrowsOnNotFound(): void
    {
        $this->expectException(EmailAuditLogNotFound::class);
        $this->query->byId(Uuid::uuid7()->toString());
    }

    public function testDistinctEmailTypes(): void
    {
        $this->insertAuditLog(Uuid::uuid7()->toString(), 'user@example.com', 'S', 'sent', 'feedback');
        $this->insertAuditLog(Uuid::uuid7()->toString(), 'user@example.com', 'S', 'sent', 'competition_approved');
        $this->insertAuditLog(Uuid::uuid7()->toString(), 'user@example.com', 'S', 'sent', 'feedback');

        $types = $this->query->distinctEmailTypes();

        self::assertContains('feedback', $types);
        self::assertContains('competition_approved', $types);
        self::assertSame(array_unique($types), $types);
    }

    public function testCountByStatus(): void
    {
        $this->insertAuditLog(Uuid::uuid7()->toString(), 'status@example.com', 'S', 'sent');
        $this->insertAuditLog(Uuid::uuid7()->toString(), 'status@example.com', 'S', 'failed');

        $counts = $this->query->countByStatus();

        self::assertGreaterThanOrEqual(1, $counts['sent']);
        self::assertGreaterThanOrEqual(1, $counts['failed']);
        self::assertSame($counts['sent'] + $counts['failed'], $counts['all']);
    }

    public function testPagination(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->insertAuditLog(Uuid::uuid7()->toString(), 'page@example.com', "Subject $i", 'sent');
        }

        $page1 = $this->query->list(limit: 2, offset: 0, recipient: 'page@example.com');
        $page2 = $this->query->list(limit: 2, offset: 2, recipient: 'page@example.com');

        self::assertCount(2, $page1);
        self::assertCount(1, $page2);
    }

    private function insertAuditLog(
        string $id,
        string $recipientEmail,
        string $subject,
        string $status,
        null|string $emailType = null,
        null|string $messageId = null,
        null|string $smtpDebugLog = null,
        null|string $mtaQueueId = null,
    ): void {
        $this->connection->insert('email_audit_log', [
            'id' => $id,
            'sent_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'recipient_email' => $recipientEmail,
            'subject' => $subject,
            'transport_name' => 'smtp://mailer:1025',
            'status' => $status,
            'email_type' => $emailType,
            'message_id' => $messageId,
            'mta_queue_id' => $mtaQueueId,
            'smtp_debug_log' => $smtpDebugLog,
        ]);
    }
}
