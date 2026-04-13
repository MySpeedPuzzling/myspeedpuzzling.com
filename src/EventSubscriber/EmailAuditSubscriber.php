<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\EventSubscriber;

use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\EmailAuditLog;
use SpeedPuzzling\Web\Repository\EmailAuditLogRepository;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Mailer\Event\FailedMessageEvent;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mailer\Event\SentMessageEvent;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\Service\ResetInterface;

final class EmailAuditSubscriber implements EventSubscriberInterface, ResetInterface
{
    /** @var array<int, EmailAuditLog> */
    private array $pendingAudits = [];

    public function __construct(
        private readonly EmailAuditLogRepository $emailAuditLogRepository,
        private readonly LoggerInterface $logger,
        private readonly string $bounceEmailDomain,
    ) {
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            MessageEvent::class => ['onMessage', 0],
            SentMessageEvent::class => ['onSentMessage', 0],
            FailedMessageEvent::class => ['onFailedMessage', 0],
        ];
    }

    public function onMessage(MessageEvent $event): void
    {
        if ($event->isQueued()) {
            return;
        }

        try {
            $message = $event->getMessage();

            if (!$message instanceof Email) {
                return;
            }

            $recipients = $message->getTo();

            if ($recipients === []) {
                return;
            }

            $recipientEmail = $recipients[0]->getAddress();
            $subject = $message->getSubject() ?? '';
            $transportName = $event->getTransport();
            $emailType = $this->extractEmailType($message);

            $auditLog = new EmailAuditLog(
                id: Uuid::uuid7(),
                sentAt: new \DateTimeImmutable(),
                recipientEmail: $recipientEmail,
                subject: $subject,
                transportName: $transportName,
                emailType: $emailType,
            );

            $this->emailAuditLogRepository->save($auditLog);

            $objectId = spl_object_id($message);
            $this->pendingAudits[$objectId] = $auditLog;

            // Set VERP return path for bounce processing
            if ($this->bounceEmailDomain !== '') {
                $verpAddress = 'bounce+' . $auditLog->id->toString() . '@' . $this->bounceEmailDomain;
                $event->getEnvelope()->setSender(new Address($verpAddress));
            }
        } catch (\Throwable $e) {
            $this->logger->error('Failed to create email audit log', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function onSentMessage(SentMessageEvent $event): void
    {
        try {
            $sentMessage = $event->getMessage();
            $objectId = spl_object_id($sentMessage->getOriginalMessage());

            if (!isset($this->pendingAudits[$objectId])) {
                return;
            }

            $auditLog = $this->pendingAudits[$objectId];
            $auditLog->markAsSent(
                messageId: $sentMessage->getMessageId(),
                smtpDebugLog: $sentMessage->getDebug(),
            );

            $this->emailAuditLogRepository->save($auditLog);
            unset($this->pendingAudits[$objectId]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to update email audit log on sent', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function onFailedMessage(FailedMessageEvent $event): void
    {
        try {
            $objectId = spl_object_id($event->getMessage());

            if (!isset($this->pendingAudits[$objectId])) {
                return;
            }

            $auditLog = $this->pendingAudits[$objectId];
            $error = $event->getError();

            $debugLog = null;
            if ($error instanceof TransportExceptionInterface) {
                $debugLog = $error->getDebug();
            }

            $auditLog->markAsFailed(
                errorMessage: $error->getMessage(),
                smtpDebugLog: $debugLog,
            );

            $this->emailAuditLogRepository->save($auditLog);
            unset($this->pendingAudits[$objectId]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to update email audit log on failure', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function reset(): void
    {
        $this->pendingAudits = [];
    }

    private function extractEmailType(Email $message): null|string
    {
        if (!$message instanceof TemplatedEmail) {
            return null;
        }

        $template = $message->getHtmlTemplate();

        if ($template === null) {
            return null;
        }

        return basename($template, '.html.twig');
    }
}
