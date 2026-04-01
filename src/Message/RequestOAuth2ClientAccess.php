<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Value\OAuth2ApplicationType;

final readonly class RequestOAuth2ClientAccess
{
    /**
     * @param array<string> $requestedScopes
     * @param array<string> $redirectUris
     */
    public function __construct(
        public string $requestId,
        public string $playerId,
        public string $clientName,
        public string $clientDescription,
        public string $purpose,
        public OAuth2ApplicationType $applicationType,
        public array $requestedScopes,
        public array $redirectUris,
    ) {
    }
}
