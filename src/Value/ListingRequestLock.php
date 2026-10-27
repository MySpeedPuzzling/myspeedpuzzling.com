<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * Lock keys (SerializedByLock) of the listing request writes: a buyer's sends take turns, so a resent form or a double
 * click never asks the same seller twice; answers and withdrawals take turns per request.
 */
final readonly class ListingRequestLock
{
    public static function buyerKey(string $buyerId): string
    {
        return 'listing-ask-' . strtolower($buyerId);
    }

    public static function requestKey(string $requestId): string
    {
        return 'listing-request-' . strtolower($requestId);
    }
}
