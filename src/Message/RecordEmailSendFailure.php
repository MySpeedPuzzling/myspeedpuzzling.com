<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

readonly final class RecordEmailSendFailure
{
    public function __construct(
        public string $auditLogId,
        public string $errorMessage,
        public null|string $smtpDebugLog,
    ) {
    }
}
