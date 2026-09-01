<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

readonly final class MessagesPage
{
    /**
     * @param array<MessageView> $messages Messages in chronological (ascending) order.
     */
    public function __construct(
        public array $messages,
        public bool $hasOlderMessages,
    ) {
    }

    public function oldestMessageId(): null|string
    {
        $first = $this->messages[0] ?? null;

        return $first?->messageId;
    }
}
