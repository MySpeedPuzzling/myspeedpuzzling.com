<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * Which API usage rows a page looks at. Every set field narrows the rows; an empty
 * filter is every caller (admin only).
 */
final readonly class ApiUsageFilter
{
    public function __construct(
        // Rows of this player: their personal access tokens and the apps acting for them
        public null|string $playerId = null,
        public null|string $personalAccessTokenId = null,
        public null|string $oauth2ClientIdentifier = null,
        public null|ApiCallerKind $callerKind = null,
        public null|string $callerKey = null,
        // Only on api_usage_day (api_caller_day has no request type)
        public null|string $operation = null,
    ) {
    }

    /**
     * Usage of one caller the viewer owns, picked on the usage page.
     */
    public static function forCaller(ApiCaller $caller): self
    {
        return new self(callerKey: $caller->key());
    }

    /**
     * An app's usage summed over every player using it (and its own client_credentials calls)
     */
    public static function forApp(string $oauth2ClientIdentifier): self
    {
        return new self(oauth2ClientIdentifier: $oauth2ClientIdentifier);
    }

    public function withOperation(null|string $operation): self
    {
        return new self(
            $this->playerId,
            $this->personalAccessTokenId,
            $this->oauth2ClientIdentifier,
            $this->callerKind,
            $this->callerKey,
            $operation,
        );
    }
}
