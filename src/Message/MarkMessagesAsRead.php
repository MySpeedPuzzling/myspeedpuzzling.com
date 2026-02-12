<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

readonly final class MarkMessagesAsRead
{
    public function __construct(
        public string $conversationId,
        public string $playerId,
    ) {
    }
}
