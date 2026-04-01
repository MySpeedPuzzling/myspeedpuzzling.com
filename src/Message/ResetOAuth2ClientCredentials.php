<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

final readonly class ResetOAuth2ClientCredentials
{
    public function __construct(
        public string $requestId,
        public string $playerId,
    ) {
    }
}
