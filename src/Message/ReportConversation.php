<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

readonly final class ReportConversation
{
    public function __construct(
        public string $reporterId,
        public string $conversationId,
        public string $reason,
    ) {
    }
}
