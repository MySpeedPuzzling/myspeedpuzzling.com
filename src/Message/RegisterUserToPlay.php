<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

readonly final class RegisterUserToPlay
{
    public function __construct(
        public string $userId,
        public null|string $email,
        public null|string $name,
    ) {
    }
}
