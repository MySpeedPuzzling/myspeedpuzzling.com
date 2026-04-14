<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

readonly final class CreateEmailAuditLog
{
    public function __construct(
        public string $recipientEmail,
        public string $subject,
        public string $transportName,
        public null|string $emailType,
        public null|string $bodyHtml,
        public null|string $bodyText,
    ) {
    }
}
