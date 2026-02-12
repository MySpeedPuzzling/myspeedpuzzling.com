<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;

readonly final class UnreadMessageNotification
{
    public function __construct(
        public string $playerId,
        public string $playerEmail,
        public null|string $playerName,
        public null|string $playerLocale,
        public DateTimeImmutable $oldestUnreadAt,
        public int $totalUnreadCount,
    ) {
    }
}
