<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\RequiresFreshEntityManagerState;

/**
 * The last row of a batch is done: what is owed for its results once (ImportedResultsFollowUp), the batch finished
 * with its counts, the player told - one notification and one e-mail. Sent twice, the second finds the batch
 * finished.
 */
readonly final class FinishImportBatch implements RequiresFreshEntityManagerState
{
    public function __construct(
        public string $batchId,
    ) {
    }
}
