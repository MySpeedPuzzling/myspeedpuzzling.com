<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * What goes with the account - shown on the last-chance page so the number the
 * user is about to throw away is concrete, not abstract.
 */
readonly final class AccountDeletionSummary
{
    public function __construct(
        public int $solvingTimesCount,
        public int $totalPieces,
        public int $totalSeconds,
        public int $collectionPuzzlesCount,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->solvingTimesCount === 0 && $this->collectionPuzzlesCount === 0;
    }
}
