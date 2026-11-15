<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * The end of a group answer, written in a transaction of its own under the answer's lock
 * (ProcessImportGroupAnswerHandler): with nothing else, the answer is ended - the handler answers whether this call
 * ended it (only that turn starts the "Add"); with $added, the "Add" its end started ($batchId), or why none was
 * ($addStatus), is written down.
 */
readonly final class RecordImportGroupAnswer
{
    public function __construct(
        public string $answerId,
        public bool $added = false,
        public null|string $batchId = null,
        public null|string $addStatus = null,
    ) {
    }
}
