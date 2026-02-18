<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

readonly final class SendMessage
{
    public function __construct(
        public string $conversationId,
        public string $senderId,
        public string $content,
    ) {
    }
}
