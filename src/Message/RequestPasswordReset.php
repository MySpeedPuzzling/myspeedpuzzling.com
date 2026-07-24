<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

final readonly class RequestPasswordReset
{
    public function __construct(
        public string $email,
    ) {
    }
}
