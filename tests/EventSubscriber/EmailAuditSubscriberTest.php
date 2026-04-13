<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\EventSubscriber;

use Doctrine\DBAL\Connection;
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

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->subscriber = $container->get(EmailAuditSubscriber::class);
        $this->connection = $container->get(Connection::class);
    }

    public function testCreatesAuditLogOnMessage(): void
    {
        $email = $this->createTemplatedEmail('user@example.com', 'Test Subject', 'emails/competition_approved.html.twig');
        $event = $this->createMessageEvent($email);

        $this->subscriber->onMessage($event);

        $log = $this->findLastAuditLog();
        self::assertSame('user@example.com', $log['recipient_email']);
        self::assertSame('Test Subject', $log['subject']);
        self::assertSame('competition_approved', $log['email_type']);
        self::assertSame(EmailAuditStatus::Sent->value, $log['status']);
    }

    public function testExtractsEmailTypeFromTemplate(): void
    {
        $email = $this->createTemplatedEmail('user@example.com', 'Digest', 'emails/unread_digest.html.twig');
        $event = $this->createMessageEvent($email);

        $this->subscriber->onMessage($event);

        $log = $this->findLastAuditLog();
        self::assertSame('unread_digest', $log['email_type']);
    }

    public function testEmailTypeIsNullForNonTemplatedEmail(): void
    {
        $email = (new Email())
            ->from('robot@mail.myspeedpuzzling.com')
            ->to('user@example.com')
            ->subject('Plain email')
            ->text('Hello');

        $event = $this->createMessageEvent($email);

        $this->subscriber->onMessage($event);

        $log = $this->findLastAuditLog();
        self::assertNull($log['email_type']);
    }

    public function testSetsVerpReturnPath(): void
    {
        // Create subscriber with explicit bounce domain for this test
        $container = self::getContainer();
        $subscriber = new EmailAuditSubscriber(
            $container->get(\SpeedPuzzling\Web\Repository\EmailAuditLogRepository::class),
            $container->get(\Psr\Log\LoggerInterface::class),
            'mail.test.example.com',
        );

        $email = $this->createTemplatedEmail('user@example.com', 'Test', 'emails/feedback.html.twig');
        $envelope = Envelope::create($email);
        $event = new MessageEvent($email, $envelope, 'smtp://mailer:1025');

        $subscriber->onMessage($event);

        $sender = $event->getEnvelope()->getSender();
        $log = $this->findLastAuditLog();
        self::assertStringStartsWith('bounce+', $sender->getAddress());
        self::assertStringEndsWith('@mail.test.example.com', $sender->getAddress());
        /** @var string $logId */
        $logId = $log['id'];
        self::assertStringContainsString($logId, $sender->getAddress());
    }

    public function testSkipsVerpWhenDomainEmpty(): void
    {
        $email = $this->createTemplatedEmail('verp-skip@example.com', 'Test', 'emails/feedback.html.twig');
        $envelope = Envelope::create($email);
        $event = new MessageEvent($email, $envelope, 'smtp://mailer:1025');

        $this->subscriber->onMessage($event);

        // With empty BOUNCE_EMAIL_DOMAIN, sender should remain unchanged
        $sender = $event->getEnvelope()->getSender();
        self::assertSame('robot@mail.myspeedpuzzling.com', $sender->getAddress());
    }

    public function testSkipsQueuedMessages(): void
    {
        /** @var int|string $countBefore */
        $countBefore = $this->connection->fetchOne('SELECT COUNT(*) FROM email_audit_log');

        $email = $this->createTemplatedEmail('user@example.com', 'Test', 'emails/feedback.html.twig');
        $envelope = Envelope::create($email);
        $event = new MessageEvent($email, $envelope, 'smtp://mailer:1025', true);

        $this->subscriber->onMessage($event);

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

        /** @var int|string $countAfter */
        $countAfter = $this->connection->fetchOne('SELECT COUNT(*) FROM email_audit_log');
        self::assertSame((int) $countBefore, (int) $countAfter);
    }

    public function testUpdatesLogOnSentMessage(): void
    {
        $email = $this->createTemplatedEmail('user@example.com', 'Test', 'emails/feedback.html.twig');
        $envelope = Envelope::create($email);

        $messageEvent = new MessageEvent($email, $envelope, 'smtp://mailer:1025');
        $this->subscriber->onMessage($messageEvent);

        /** @phpstan-ignore method.internal */
        $sentMessage = new SentMessage($email, $envelope);
        $sentMessage->setMessageId('queue-id-123');
        $sentMessage->appendDebug('> EHLO mailer' . "\n" . '< 250 OK' . "\n");

        $sentEvent = new SentMessageEvent($sentMessage);
        $this->subscriber->onSentMessage($sentEvent);

        $log = $this->findLastAuditLog();
        self::assertSame(EmailAuditStatus::Sent->value, $log['status']);
        self::assertSame('queue-id-123', $log['message_id']);
        self::assertNotNull($log['smtp_debug_log']);
    }

    public function testUpdatesLogOnFailedMessage(): void
    {
        $email = $this->createTemplatedEmail('user@example.com', 'Test', 'emails/feedback.html.twig');
        $envelope = Envelope::create($email);

        $messageEvent = new MessageEvent($email, $envelope, 'smtp://mailer:1025');
        $this->subscriber->onMessage($messageEvent);

        $error = new TransportException('Connection refused');
        $failedEvent = new FailedMessageEvent($email, $error);
        $this->subscriber->onFailedMessage($failedEvent);

        $log = $this->findLastAuditLog();
        self::assertSame(EmailAuditStatus::Failed->value, $log['status']);
        self::assertSame('Connection refused', $log['error_message']);
    }

    public function testResetClearsPendingAudits(): void
    {
        $email = $this->createTemplatedEmail('user@example.com', 'Test', 'emails/feedback.html.twig');
        $envelope = Envelope::create($email);
        $messageEvent = new MessageEvent($email, $envelope, 'smtp://mailer:1025');
        $this->subscriber->onMessage($messageEvent);

        $this->subscriber->reset();

        // After reset, onSentMessage should not find the pending audit
        /** @phpstan-ignore method.internal */
        $sentMessage = new SentMessage($email, $envelope);
        $sentMessage->setMessageId('after-reset');
        $sentEvent = new SentMessageEvent($sentMessage);
        $this->subscriber->onSentMessage($sentEvent);

        // The message_id should still be null (not updated)
        $log = $this->findLastAuditLog();
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
    private function findLastAuditLog(): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM email_audit_log ORDER BY sent_at DESC LIMIT 1',
        );

        self::assertIsArray($row, 'Expected to find an audit log entry');

        return $row;
    }
}
