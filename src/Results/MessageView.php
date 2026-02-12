<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;

readonly final class MessageView
{
    public function __construct(
        public string $messageId,
        public string $senderId,
        public null|string $senderName,
        public null|string $senderAvatar,
        public string $content,
        public DateTimeImmutable $sentAt,
        public null|DateTimeImmutable $readAt,
        public bool $isOwnMessage,
    ) {
    }
}
