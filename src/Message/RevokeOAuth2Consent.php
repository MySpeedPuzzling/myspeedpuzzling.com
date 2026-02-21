<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

readonly final class RevokeOAuth2Consent
{
    public function __construct(
        public string $playerId,
        public string $consentId,
    ) {
    }
}
