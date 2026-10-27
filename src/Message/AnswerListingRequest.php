<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;
use SpeedPuzzling\Web\Value\ListingRequestLock;

/**
 * The seller answers a listing request (docs/features/marketplace/12-listing-requests.md): per listing yes (= reserved for
 * the buyer) / gone / declined, optionally taking the listings they no longer have off their sell/swap list. A "yes" - or
 * "Reply in chat" - opens a chat with the buyer from the seller's side. Answers a ListingRequestAnswerOutcome.
 */
readonly final class AnswerListingRequest implements SerializedByLock
{
    /**
     * @param array<string, string> $answers item id => ListingRequestItemStatus value
     * @param list<string> $removeFromList item ids answered gone whose listing leaves the seller's sell/swap list
     */
    public function __construct(
        public string $requestId,
        // Who answers - must be the request's seller
        public string $playerId,
        public array $answers,
        public array $removeFromList = [],
        // "Reply in chat" - a chat opens even without a yes
        public bool $openChat = false,
        // The language of the chat's first message - the seller's page; null = their profile's
        public null|string $locale = null,
    ) {
    }

    public function lockKey(): string
    {
        return ListingRequestLock::requestKey($this->requestId);
    }
}
