<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\RequiresFreshEntityManagerState;

/**
 * What is owed for the results of a batch, a bounded number of results at a time (ImportedResultsFollowUp::forResults()):
 * the statistics of the puzzles they touched and each result's XP award - queued on `imports`, never on the shared
 * `async` queue. Not routed: handled at once by ProcessImportBatch, in its own transaction. Answers whether every
 * result of the batch is followed up.
 */
readonly final class FollowUpImportBatch implements RequiresFreshEntityManagerState
{
    public function __construct(
        public string $batchId,
    ) {
    }
}
