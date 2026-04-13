<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\RecordEmailSendFailure;
use SpeedPuzzling\Web\Repository\EmailAuditLogRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class RecordEmailSendFailureHandler
{
    public function __construct(
        private EmailAuditLogRepository $emailAuditLogRepository,
    ) {
    }

    public function __invoke(RecordEmailSendFailure $message): void
    {
        $auditLog = $this->emailAuditLogRepository->get(Uuid::fromString($message->auditLogId));
        $auditLog->markAsFailed($message->errorMessage, $message->smtpDebugLog);
    }
}
