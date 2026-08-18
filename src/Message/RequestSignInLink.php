<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

final readonly class RequestSignInLink
{
    public function __construct(
        public string $email,
        /** Used when the account has no player locale yet (the email must still be readable) */
        public string $fallbackLocale,
    ) {
    }
}
