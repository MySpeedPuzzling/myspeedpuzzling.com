<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;
use SpeedPuzzling\Web\Value\BorrowRequestLock;

/**
 * The owner answers a borrow request (docs/features/wishlist-availability.md): per puzzle yes / not_owned / declined,
 * optionally taking the puzzles they no longer have out of their collection. A "yes" - or "Reply in chat" - opens a chat
 * with the asker from the owner's side.
 */
readonly final class AnswerBorrowRequest implements SerializedByLock
{
    /**
     * @param array<string, string> $answers item id => BorrowRequestItemStatus value
     * @param list<string> $removeFromCollection item ids answered not_owned whose puzzle leaves the owner's collection
     */
    public function __construct(
        public string $requestId,
        // Who answers - must be the request's owner
        public string $playerId,
        public array $answers,
        public array $removeFromCollection = [],
        // "Reply in chat" - a chat opens even without a yes
        public bool $openChat = false,
        // The language of the chat's first message - the owner's page; null = their profile's
        public null|string $locale = null,
    ) {
    }

    public function lockKey(): string
    {
        return BorrowRequestLock::requestKey($this->requestId);
    }
}
