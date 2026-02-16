<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Events;

use Ramsey\Uuid\UuidInterface;

readonly final class ChatMessageSent
{
    public function __construct(
        public UuidInterface $chatMessageId,
        public UuidInterface $conversationId,
        public string $senderId,
    ) {
    }
}
