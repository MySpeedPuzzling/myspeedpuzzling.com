<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;

final readonly class OAuth2ClientRequestOverview
{
    /**
     * @param array<string> $requestedScopes
     * @param array<string> $redirectUris
     */
    public function __construct(
        public string $id,
        public string $playerName,
        public string $clientName,
        public string $clientDescription,
        public string $purpose,
        public string $applicationType,
        public array $requestedScopes,
        public array $redirectUris,
        public string $status,
        public DateTimeImmutable $createdAt,
        public null|DateTimeImmutable $reviewedAt,
        public null|string $rejectionReason,
        public null|string $clientIdentifier,
        public bool $credentialsClaimed,
    ) {
    }
}
