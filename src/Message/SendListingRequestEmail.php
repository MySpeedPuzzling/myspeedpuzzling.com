<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * The e-mail to the seller about a listing request (docs/features/marketplace/12-listing-requests.md), async after the send.
 */
readonly final class SendListingRequestEmail
{
    public function __construct(
        public string $requestId,
    ) {
    }
}
