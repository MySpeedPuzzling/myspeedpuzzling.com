<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

readonly final class SendEmailVerificationLink
{
    public function __construct(
        public string $userId,
        public null|string $fallbackLocale = null,
    ) {
    }
}
