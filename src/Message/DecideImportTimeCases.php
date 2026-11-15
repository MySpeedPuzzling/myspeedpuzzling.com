<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\HandledOutsideTransaction;
use SpeedPuzzling\Web\Value\SuspicionDirection;

/**
 * One decision for an import card of the time verification queue (docs/features/suspicious-time-review.md "Import
 * cards"): "Looks fine - all N" or "Mark all N as needing verification" for the pending cases of one direction on
 * results that one "Add" of a spreadsheet import made.
 *
 * Nothing new is decided here: the handler works the cases out and sends, **per case**, the very message the single
 * card sends (TrustSolvingTime / MarkSolvingTimeSuspicious) - each in its own transaction, with its own lock, its
 * own "changed meanwhile" and its own line in the decision log. Outside a transaction for that reason. Bounded: at
 * most LIMIT cases a message - the card stays with the rest. It answers how many were decided, how many had changed
 * meanwhile, and whether more are left.
 */
readonly final class DecideImportTimeCases implements HandledOutsideTransaction
{
    public const int LIMIT = 200;

    public function __construct(
        public string $batchId,
        public SuspicionDirection $direction,
        public string $decidedById,
        /** true: "Needs verification" for all; false: "Looks fine" for all */
        public bool $mark,
        /** The note the player reads with a mark (the same for every result of the group) */
        public null|string $note,
    ) {
    }
}
