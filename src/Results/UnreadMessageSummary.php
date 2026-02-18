<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

readonly final class UnreadMessageSummary
{
    public function __construct(
        public string $senderName,
        public string $senderCode,
        public int $unreadCount,
        public null|string $puzzleName,
        public string $conversationId,
    ) {
    }
}
