<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;
use SpeedPuzzling\Web\Value\BorrowRequestLock;

/**
 * The asker takes back a borrow request - every open puzzle of it, or one (docs/features/wishlist-availability.md).
 * Quiet: the owner is not told.
 */
readonly final class WithdrawBorrowRequest implements SerializedByLock
{
    public function __construct(
        public string $requestId,
        public string $askerId,
        public null|string $itemId = null,
    ) {
    }

    public function lockKey(): string
    {
        return BorrowRequestLock::requestKey($this->requestId);
    }
}
