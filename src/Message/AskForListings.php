<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;
use SpeedPuzzling\Web\Value\ListingRequestLock;

/**
 * One "Send" of the listing request sheet (docs/features/marketplace/12-listing-requests.md): a request to every seller,
 * for their listings the buyer ticked, with the buyer's own listings offered in exchange and a note. The send id makes it
 * idempotent - a resent form asks nobody twice. Answers a ListingAskOutcome.
 */
readonly final class AskForListings implements SerializedByLock
{
    public const int MAX_SELLERS = 10;
    public const int MAX_LISTINGS = 30;
    public const int MAX_OFFERS_PER_SELLER = 10;

    /**
     * @param array<string, list<string>> $listingsBySeller seller id => their listing ids
     * @param array<string, list<string>> $offersBySeller seller id => the buyer's own listing ids offered in exchange
     * @param array<string, null|string> $notes seller id => an optional note to them
     */
    public function __construct(
        public string $sendId,
        public string $buyerId,
        public array $listingsBySeller,
        public array $offersBySeller = [],
        public array $notes = [],
    ) {
    }

    public function lockKey(): string
    {
        return ListingRequestLock::buyerKey($this->buyerId);
    }
}
