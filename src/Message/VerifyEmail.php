<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SensitiveParameter;

final readonly class VerifyEmail
{
    public function __construct(
        #[SensitiveParameter]
        public string $token,
    ) {
    }
}
