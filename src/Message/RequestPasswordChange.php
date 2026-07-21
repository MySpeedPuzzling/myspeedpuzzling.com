<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

readonly final class RequestPasswordChange
{
    public function __construct(
        public string $userId,
        public string $email,
    ) {
    }
}
