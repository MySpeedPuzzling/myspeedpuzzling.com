<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * Requests in the last 30 days for everything listed on the edit-profile page
 */
readonly final class ApiUsageRecentTotals
{
    public const int DAYS = 30;

    /**
     * @param array<string, int> $byPersonalAccessToken token id => requests
     * @param array<string, int> $byConnectedApp client id => the player's own requests through that app
     * @param array<string, int> $byOwnApp client id => requests of the player's own app, all its users
     */
    public function __construct(
        public array $byPersonalAccessToken,
        public array $byConnectedApp,
        public array $byOwnApp,
    ) {
    }

    public function personalAccessToken(string $tokenId): int
    {
        return $this->byPersonalAccessToken[$tokenId] ?? 0;
    }

    public function connectedApp(string $clientIdentifier): int
    {
        return $this->byConnectedApp[$clientIdentifier] ?? 0;
    }

    public function ownApp(string $clientIdentifier): int
    {
        return $this->byOwnApp[$clientIdentifier] ?? 0;
    }
}
