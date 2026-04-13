<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\BounceType;
use SpeedPuzzling\Web\Value\EmailAuditStatus;

readonly final class EmailAuditLogOverview
{
    public function __construct(
        public string $id,
        public DateTimeImmutable $sentAt,
        public string $recipientEmail,
        public string $subject,
        public string $transportName,
        public EmailAuditStatus $status,
        public null|string $emailType = null,
        public null|BounceType $bounceType = null,
    ) {
    }
}
