<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * What one "Send" of the ask-to-borrow sheet did: how many people were asked for how many puzzles, and the puzzles left
 * out with their reason.
 */
final readonly class BorrowAskOutcome
{
    /**
     * @param array<string, BorrowAskSkipReason> $skipped puzzle id => why it was not asked for
     */
    public function __construct(
        public int $requestsSent,
        public int $puzzlesAsked,
        public array $skipped = [],
    ) {
    }

    public function skippedFor(BorrowAskSkipReason $reason): int
    {
        return count(array_filter($this->skipped, static fn (BorrowAskSkipReason $skipped): bool => $skipped === $reason));
    }
}
