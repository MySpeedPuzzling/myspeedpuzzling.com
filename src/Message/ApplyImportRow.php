<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\RequiresFreshEntityManagerState;

/**
 * One queued row of an "Add", in its own transaction (ImportRowAdder): settled as "already yours" in the batch's
 * first pass ($settleOnly), or added. Not routed - handled at once by ProcessImportBatch. A row that is not queued in
 * the batch any more is left alone.
 *
 * A fresh entity manager for every row: the rows of a batch are thousands, and a row must be read as the database
 * has it now.
 */
readonly final class ApplyImportRow implements RequiresFreshEntityManagerState
{
    public function __construct(
        public string $rowId,
        public string $batchId,
        public string $playerId,
        public bool $settleOnly = false,
    ) {
    }
}
