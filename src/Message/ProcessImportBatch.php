<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\HandledOutsideTransaction;

/**
 * Goes through the queued rows of an "Add" (docs/features/data-import/adding.md "The work"), on the `imports` queue:
 * a turn of at most DataImportLimits::ROWS_PER_TURN rows, then the message sends itself again - other players'
 * imports take their turns in between.
 *
 * Handled outside a transaction: every row is a small message of its own, handled at once in its own transaction
 * (ApplyImportRow). Everything is driven by the rows' state - sent twice, delivered again or started again after a
 * killed worker, it goes on where the rows say and adds no row twice.
 */
readonly final class ProcessImportBatch implements HandledOutsideTransaction
{
    /**
     * @param int $turn the batch's turn this job is (DataImportBatch::$turn) - a job of an older turn does nothing
     */
    public function __construct(
        public string $batchId,
        public int $turn,
    ) {
    }
}
