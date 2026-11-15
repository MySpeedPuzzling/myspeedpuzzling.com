<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * What a turn of a group answer did, written in a transaction of its own under the answer's lock
 * (ProcessImportGroupAnswerHandler): the rows it went through and the ones that took the answer - only when the
 * answer still stands where the turn began ($from: a second chain's turn is void) - and, with $finish, the end of
 * the answer. With $batchId / $addStatus: the "Add" its end started, or why none was. The handler answers whether
 * the write was this turn's to make.
 */
readonly final class RecordImportGroupAnswer
{
    /**
     * @param list<string> $answeredRowIds
     */
    public function __construct(
        public string $answerId,
        public null|string $from = null,
        public int $rows = 0,
        public array $answeredRowIds = [],
        public null|string $cursor = null,
        public bool $finish = false,
        public bool $added = false,
        public null|string $batchId = null,
        public null|string $addStatus = null,
    ) {
    }
}
