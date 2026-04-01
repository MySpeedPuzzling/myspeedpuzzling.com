<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

final readonly class ApproveOAuth2ClientRequest
{
    public function __construct(
        public string $requestId,
        public string $adminPlayerId,
    ) {
    }
}
