<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * How many change receipts PruneRoundResultChangeReceiptsHandler deleted - of official results changes and of
 * participants sheet change sets.
 */
readonly final class PrunedChangeReceipts
{
    public function __construct(
        public int $roundResultReceipts,
        public int $participantSheetReceipts,
    ) {
    }
}
