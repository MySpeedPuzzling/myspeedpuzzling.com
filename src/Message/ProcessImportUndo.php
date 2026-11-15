<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\HandledOutsideTransaction;

/**
 * Goes through the rows of an Undo, on the `imports` queue - the same way ProcessImportBatch goes through an "Add":
 * turns of a few rows, each row in its own transaction (UndoImportRow), driven by the rows' mark, so it can be
 * delivered twice or started again.
 */
readonly final class ProcessImportUndo implements HandledOutsideTransaction
{
    /**
     * @param int $turn the Undo's turn this job is (DataImportUndo::$turn) - a job of an older turn does nothing
     */
    public function __construct(
        public string $undoId,
        public int $turn,
    ) {
    }
}
