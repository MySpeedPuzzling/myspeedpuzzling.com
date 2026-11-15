<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * A turn of ProcessImportBatch starts: is the batch still running, and may it go on (the safety switch)? Answers
 * what the turn needs - or null: nothing to do.
 */
readonly final class BeginImportBatchTurn
{
    public function __construct(
        public string $batchId,
    ) {
    }
}
