<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\EmailAuditLog;
use SpeedPuzzling\Web\Message\CreateEmailAuditLog;
use SpeedPuzzling\Web\Repository\EmailAuditLogRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class CreateEmailAuditLogHandler
{
    public function __construct(
        private EmailAuditLogRepository $emailAuditLogRepository,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(CreateEmailAuditLog $message): string
    {
        $auditLog = new EmailAuditLog(
            id: Uuid::uuid7(),
            sentAt: $this->clock->now(),
            recipientEmail: $message->recipientEmail,
            subject: $message->subject,
            transportName: $message->transportName,
            emailType: $message->emailType,
        );

        $this->emailAuditLogRepository->save($auditLog);

        return $auditLog->id->toString();
    }
}
