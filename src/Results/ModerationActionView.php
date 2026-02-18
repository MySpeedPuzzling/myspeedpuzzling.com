<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\ModerationActionType;

readonly final class ModerationActionView
{
    public function __construct(
        public string $actionId,
        public ModerationActionType $actionType,
        public string $targetPlayerName,
        public string $targetPlayerId,
        public string $adminName,
        public null|string $reason,
        public DateTimeImmutable $performedAt,
        public null|DateTimeImmutable $expiresAt = null,
    ) {
    }
}
