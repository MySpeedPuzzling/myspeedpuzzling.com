<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

final readonly class RejectOAuth2ClientRequest
{
    public function __construct(
        public string $requestId,
        public string $adminPlayerId,
        public string $reason,
    ) {
    }
}
