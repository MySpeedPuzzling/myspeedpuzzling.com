<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SensitiveParameter;

readonly final class SendPasswordResetLink
{
    public function __construct(
        public string $email,
        #[SensitiveParameter]
        public string $token,
        public null|string $fallbackLocale,
    ) {
    }
}
