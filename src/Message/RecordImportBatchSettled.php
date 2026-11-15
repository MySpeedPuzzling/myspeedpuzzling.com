<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * How far the first pass of a batch is ("already yours" settled before anything is added) - so a turn that ends, or
 * a worker that dies, goes on from there.
 */
readonly final class RecordImportBatchSettled
{
    public function __construct(
        public string $batchId,
        public null|string $upToRowId,
        public bool $through,
    ) {
    }
}
