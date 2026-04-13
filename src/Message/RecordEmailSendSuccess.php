<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

readonly final class RecordEmailSendSuccess
{
    public function __construct(
        public string $auditLogId,
        public string $messageId,
        public string $smtpDebugLog,
    ) {
    }
}
