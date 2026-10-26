<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * The e-mail to the owner about a new borrow request (docs/features/wishlist-availability.md), sent async once the
 * request is committed.
 */
readonly final class SendBorrowRequestEmail
{
    public function __construct(
        public string $requestId,
    ) {
    }
}
