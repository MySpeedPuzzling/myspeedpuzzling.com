<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\RequiresFreshEntityManagerState;

/**
 * One row of an Undo, in its own transaction: what the row made is taken back, what it removed is put back, the row
 * waits again. $failed: the row threw - it is given up (it stays added, free for another Undo). $finish: the Undo's
 * last row is done.
 */
readonly final class UndoImportRow implements RequiresFreshEntityManagerState
{
    public function __construct(
        public string $undoId,
        public null|string $rowId,
        public bool $failed = false,
        public bool $finish = false,
    ) {
    }
}
