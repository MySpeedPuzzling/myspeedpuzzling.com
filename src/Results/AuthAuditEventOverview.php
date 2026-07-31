<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\AuthAuditEventType;

readonly final class AuthAuditEventOverview
{
    public function __construct(
        public AuthAuditEventType $eventType,
        public DateTimeImmutable $occurredAt,
        public null|string $ipAddress,
        public null|string $deviceLabel,
    ) {
    }
}
