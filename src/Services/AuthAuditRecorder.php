<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Message\RecordAuthAuditEvent;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The single door to the auth audit trail: swallows every failure so an audit
 * write can never break login (mirrors the EmailAuditSubscriber guarantee).
 * All dispatch sites go through here instead of repeating the try/catch.
 */
final readonly class AuthAuditRecorder
{
    public function __construct(
        private MessageBusInterface $messageBus,
        private LoggerInterface $logger,
    ) {
    }

    public function record(RecordAuthAuditEvent $event): void
    {
        try {
            $this->messageBus->dispatch($event);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to record auth audit event', [
                'exception' => $e,
                'event_type' => $event->eventType->value,
            ]);
        }
    }
}
