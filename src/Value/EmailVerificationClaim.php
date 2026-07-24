<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

final readonly class EmailVerificationClaim
{
    public function __construct(
        public string $userId,
        public string $email,
    ) {
    }
}
