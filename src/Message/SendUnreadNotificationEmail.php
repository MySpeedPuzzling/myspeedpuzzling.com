<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use DateTimeImmutable;
use SpeedPuzzling\Web\Results\UnreadMessageSummary;

readonly final class SendUnreadNotificationEmail
{
    /**
     * @param UnreadMessageSummary[] $summaries
     */
    public function __construct(
        public string $playerId,
        public string $playerEmail,
        public null|string $playerName,
        public null|string $playerLocale,
        public array $summaries,
        public int $pendingRequestCount,
        public int $unreadNotificationCount,
        public null|DateTimeImmutable $oldestUnreadAt,
        public null|DateTimeImmutable $oldestPendingAt,
    ) {
    }
}
