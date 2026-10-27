<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;
use SpeedPuzzling\Web\Value\ListingRequestLock;

/**
 * The buyer takes back a listing request - all of it, or one listing (docs/features/marketplace/12-listing-requests.md).
 */
readonly final class WithdrawListingRequest implements SerializedByLock
{
    public function __construct(
        public string $requestId,
        public string $buyerId,
        // null = every open listing of the request
        public null|string $itemId = null,
    ) {
    }

    public function lockKey(): string
    {
        return ListingRequestLock::requestKey($this->requestId);
    }
}
