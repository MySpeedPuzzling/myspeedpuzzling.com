<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\EventSubscriber;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\EventSubscriber\EmailAuditSubscriber;
use SpeedPuzzling\Web\Value\EmailAuditStatus;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Event\FailedMessageEvent;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mailer\Event\SentMessageEvent;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

final class EmailAuditSubscriberTest extends KernelTestCase
{
    private EmailAuditSubscriber $subscriber;
    private Connection $connection;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->subscriber = $container->get(EmailAuditSubscriber::class);
        $this->connection = $container->get(Connection::class);
        $this->entityManager = $container->get(EntityManagerInterface::class);
    }

    public function testCreatesAuditLogOnMessage(): void
    {
        $email = $this->createTemplatedEmail('audit-create@example.com', 'Test Subject', 'emails/competition_approved.html.twig');
        $event = $this->createMessageEvent($email);

        $this->subscriber->onMessage($event);

        $log = $this->findAuditLogByRecipient('audit-create@example.com');
        self::assertSame('audit-create@example.com', $log['recipient_email']);
        self::assertSame('Test Subject', $log['subject']);
        self::assertSame('competition_approved', $log['email_type']);
        self::assertSame(EmailAuditStatus::Pending->value, $log['status']);
    }

    public function testExtractsEmailTypeFromTemplate(): void
    {
        $email = $this->createTemplatedEmail('audit-type@example.com', 'Digest', 'emails/unread_digest.html.twig');
        $event = $this->createMessageEvent($email);

        $this->subscriber->onMessage($event);

        $log = $this->findAuditLogByRecipient('audit-type@example.com');
        self::assertSame('unread_digest', $log['email_type']);
    }

    public function testEmailTypeIsNullForNonTemplatedEmail(): void
    {
        $email = (new Email())
            ->from('robot@mail.myspeedpuzzling.com')
            ->to('audit-plain@example.com')
            ->subject('Plain email')
            ->text('Hello');

        $event = $this->createMessageEvent($email);
        $this->subscriber->onMessage($event);

        $log = $this->findAuditLogByRecipient('audit-plain@example.com');
        self::assertNull($log['email_type']);
    }

    public function testSetsVerpReturnPath(): void
    {
        $container = self::getContainer();
        $subscriber = new EmailAuditSubscriber(
            $container->get(\Symfony\Component\Messenger\MessageBusInterface::class),
            $container->get(\Psr\Log\LoggerInterface::class),
            'mail.test.example.com',
        );

        $email = $this->createTemplatedEmail('audit-verp@example.com', 'Test', 'emails/feedback.html.twig');
        $envelope = Envelope::create($email);
        $event = new MessageEvent($email, $envelope, 'smtp://mailer:1025');

        $subscriber->onMessage($event);

        $sender = $event->getEnvelope()->getSender();
        $log = $this->findAuditLogByRecipient('audit-verp@example.com');
        /** @var string $logId */
        $logId = $log['id'];
        self::assertStringStartsWith('bounce+', $sender->getAddress());
        self::assertStringEndsWith('@mail.test.example.com', $sender->getAddress());
        self::assertStringContainsString($logId, $sender->getAddress());
    }

    public function testSkipsVerpWhenDomainEmpty(): void
    {
        $email = $this->createTemplatedEmail('audit-noverp@example.com', 'Test', 'emails/feedback.html.twig');
        $envelope = Envelope::create($email);
        $event = new MessageEvent($email, $envelope, 'smtp://mailer:1025');

        $this->subscriber->onMessage($event);

        $sender = $event->getEnvelope()->getSender();
        self::assertSame('robot@mail.myspeedpuzzling.com', $sender->getAddress());
    }

    public function testSkipsQueuedMessages(): void
    {
        /** @var int|string $countBefore */
        $countBefore = $this->connection->fetchOne('SELECT COUNT(*) FROM email_audit_log');

        $email = $this->createTemplatedEmail('audit-queued@example.com', 'Test', 'emails/feedback.html.twig');
        $envelope = Envelope::create($email);
        $event = new MessageEvent($email, $envelope, 'smtp://mailer:1025', true);

        $this->subscriber->onMessage($event);
        $this->entityManager->flush();

        /** @var int|string $countAfter */
        $countAfter = $this->connection->fetchOne('SELECT COUNT(*) FROM email_audit_log');
        self::assertSame((int) $countBefore, (int) $countAfter);
    }

    public function testSkipsNonEmailMessages(): void
    {
        /** @var int|string $countBefore */
        $countBefore = $this->connection->fetchOne('SELECT COUNT(*) FROM email_audit_log');

        $message = new \Symfony\Component\Mime\RawMessage('raw content');
        $envelope = new Envelope(new Address('from@example.com'), [new Address('to@example.com')]);
        $event = new MessageEvent($message, $envelope, 'smtp://mailer:1025');

        $this->subscriber->onMessage($event);
        $this->entityManager->flush();

        /** @var int|string $countAfter */
        $countAfter = $this->connection->fetchOne('SELECT COUNT(*) FROM email_audit_log');
        self::assertSame((int) $countBefore, (int) $countAfter);
    }

    public function testUpdatesLogOnSentMessage(): void
    {
        $email = $this->createTemplatedEmail('audit-sent@example.com', 'Test', 'emails/feedback.html.twig');
        $envelope = Envelope::create($email);

        $messageEvent = new MessageEvent($email, $envelope, 'smtp://mailer:1025');
        $this->subscriber->onMessage($messageEvent);

        /** @phpstan-ignore method.internal */
        $sentMessage = new SentMessage($email, $envelope);
        $sentMessage->setMessageId('queue-id-123');
        $sentMessage->appendDebug('> EHLO mailer' . "\n" . '< 250 OK' . "\n");

        $sentEvent = new SentMessageEvent($sentMessage);
        $this->subscriber->onSentMessage($sentEvent);

        $log = $this->findAuditLogByRecipient('audit-sent@example.com');
        self::assertSame(EmailAuditStatus::Sent->value, $log['status']);
        self::assertSame('queue-id-123', $log['message_id']);
        self::assertNotNull($log['smtp_debug_log']);
    }

    public function testUpdatesLogOnFailedMessage(): void
    {
        $email = $this->createTemplatedEmail('audit-failed@example.com', 'Test', 'emails/feedback.html.twig');
        $envelope = Envelope::create($email);

        $messageEvent = new MessageEvent($email, $envelope, 'smtp://mailer:1025');
        $this->subscriber->onMessage($messageEvent);

        $error = new TransportException('Connection refused');
        $failedEvent = new FailedMessageEvent($email, $error);
        $this->subscriber->onFailedMessage($failedEvent);

        $log = $this->findAuditLogByRecipient('audit-failed@example.com');
        self::assertSame(EmailAuditStatus::Failed->value, $log['status']);
        self::assertSame('Connection refused', $log['error_message']);
    }

    public function testResetClearsPendingAudits(): void
    {
        $email = $this->createTemplatedEmail('audit-reset@example.com', 'Test', 'emails/feedback.html.twig');
        $envelope = Envelope::create($email);
        $messageEvent = new MessageEvent($email, $envelope, 'smtp://mailer:1025');
        $this->subscriber->onMessage($messageEvent);

        $this->subscriber->reset();

        /** @phpstan-ignore method.internal */
        $sentMessage = new SentMessage($email, $envelope);
        $sentMessage->setMessageId('after-reset');
        $sentEvent = new SentMessageEvent($sentMessage);
        $this->subscriber->onSentMessage($sentEvent);

        $log = $this->findAuditLogByRecipient('audit-reset@example.com');
        self::assertSame(EmailAuditStatus::Pending->value, $log['status']);
        self::assertNull($log['message_id']);
    }

    private function createTemplatedEmail(string $to, string $subject, string $template): TemplatedEmail
    {
        return (new TemplatedEmail())
            ->from('robot@mail.myspeedpuzzling.com')
            ->to($to)
            ->subject($subject)
            ->htmlTemplate($template)
            ->text('Test body');
    }

    private function createMessageEvent(Email $email): MessageEvent
    {
        $envelope = Envelope::create($email);

        return new MessageEvent($email, $envelope, 'smtp://mailer:1025');
    }

    /**
     * @return array<string, mixed>
     */
    private function findAuditLogByRecipient(string $recipient): array
    {
        // Flush to make EntityManager-persisted entities visible to DBAL queries
        // (in production, doctrine_transaction middleware handles this)
        $this->entityManager->flush();

        $row = $this->connection->fetchAssociative(
            'SELECT * FROM email_audit_log WHERE recipient_email = :recipient ORDER BY sent_at DESC LIMIT 1',
            ['recipient' => $recipient],
        );

        self::assertIsArray($row, "Expected to find an audit log entry for {$recipient}");

        return $row;
    }
}
