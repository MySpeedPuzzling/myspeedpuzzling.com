<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\EventSubscriber;

use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Services\EmailAuditLogger;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Mailer\Event\FailedMessageEvent;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mailer\Event\SentMessageEvent;
use Symfony\Component\Mime\Email;

final readonly class EmailAuditSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private EmailAuditLogger $emailAuditLogger,
        private LoggerInterface $logger,
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

        $message = $event->getMessage();

        if (!$message instanceof Email || $message->getTo() === []) {
            return;
        }

        try {
            $auditLog = $this->emailAuditLogger->createAuditLog($message, $event->getTransport());

            $verpAddress = $this->emailAuditLogger->buildVerpAddress($auditLog);

            if ($verpAddress !== null) {
                $event->getEnvelope()->setSender($verpAddress);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Failed to create email audit log', [
                'exception' => $e,
            ]);
        }
    }

    public function onSentMessage(SentMessageEvent $event): void
    {
        try {
            $sentMessage = $event->getMessage();

            $this->emailAuditLogger->recordSuccess(
                spl_object_id($sentMessage->getOriginalMessage()),
                $sentMessage->getMessageId(),
                $sentMessage->getDebug(),
            );
        } catch (\Throwable $e) {
            $this->logger->error('Failed to update email audit log on sent', [
                'exception' => $e,
            ]);
        }
    }

    public function onFailedMessage(FailedMessageEvent $event): void
    {
        try {
            $this->emailAuditLogger->recordFailure(
                spl_object_id($event->getMessage()),
                $event->getError(),
            );
        } catch (\Throwable $e) {
            $this->logger->error('Failed to update email audit log on failure', [
                'exception' => $e,
            ]);
        }
    }
}
