<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

readonly final class StartConversation
{
    public function __construct(
        public string $initiatorId,
        public string $recipientId,
        public string $initialMessage,
        public null|string $sellSwapListItemId = null,
        public null|string $puzzleId = null,
    ) {
    }
}
