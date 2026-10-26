<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;
use SpeedPuzzling\Web\Value\BorrowRequestLock;

/**
 * One "Send" of the ask-to-borrow sheet (docs/features/wishlist-availability.md): a borrow request to every owner, for
 * the puzzles assigned to them. The send id makes it idempotent - a resent form asks nobody twice. Answers a
 * BorrowAskOutcome.
 */
readonly final class AskToBorrowPuzzles implements SerializedByLock
{
    public const int MAX_OWNERS = 10;
    public const int MAX_PUZZLES = 30;

    /**
     * @param array<string, list<string>> $puzzlesByOwner owner id => puzzle ids
     * @param array<string, null|string> $notes owner id => an optional note to them
     */
    public function __construct(
        public string $sendId,
        public string $askerId,
        public array $puzzlesByOwner,
        public array $notes = [],
    ) {
    }

    public function lockKey(): string
    {
        return BorrowRequestLock::askerKey($this->askerId);
    }
}
