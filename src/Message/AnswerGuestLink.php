<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

readonly final class AnswerGuestLink
{
    public function __construct(
        public string $requestId,
        public string $playerId,
        public bool $accept,
    ) {
    }
}
