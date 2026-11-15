<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * The next turn of a running "Add" or Undo is put on the `imports` queue - by the turn before it, by the sweeper, by
 * an Undo that stopped a batch (docs/features/data-import/adding.md "One chain of turns"). It raises the turn's
 * number first: whatever job of an older turn still waits or is delivered again finds itself void, so there is one
 * chain of turns however often this is asked for - and the moment is noted, so the sweeper leaves alone what waits
 * in the queue.
 */
readonly final class QueueImportTurn
{
    private function __construct(
        public null|string $batchId,
        public null|string $undoId,
    ) {
    }

    public static function ofBatch(string $batchId): self
    {
        return new self($batchId, null);
    }

    public static function ofUndo(string $undoId): self
    {
        return new self(null, $undoId);
    }
}
