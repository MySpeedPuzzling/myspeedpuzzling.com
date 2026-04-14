<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\RecordEmailSendSuccess;
use SpeedPuzzling\Web\Repository\EmailAuditLogRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class RecordEmailSendSuccessHandler
{
    public function __construct(
        private EmailAuditLogRepository $emailAuditLogRepository,
    ) {
    }

    public function __invoke(RecordEmailSendSuccess $message): void
    {
        $auditLog = $this->emailAuditLogRepository->get(Uuid::fromString($message->auditLogId));
        $auditLog->markAsSent($message->messageId, $message->mtaQueueId, $message->smtpDebugLog);
    }
}
