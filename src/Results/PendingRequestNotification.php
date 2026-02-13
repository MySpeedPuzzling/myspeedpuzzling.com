<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;

readonly final class PendingRequestNotification
{
    public function __construct(
        public string $playerId,
        public string $playerEmail,
        public null|string $playerName,
        public null|string $playerLocale,
        public DateTimeImmutable $oldestPendingAt,
        public int $pendingCount,
    ) {
    }
}
