<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * The next chunk of a group answer's rows takes the answer - **claimed and answered in one transaction**, under the
 * answer's lock (docs/features/data-import/group-answers.md "One turn, one chunk"): the rows after the answer's
 * cursor are read, answered, written down as answered and the cursor moves past them, all or nothing. Two turns that
 * run side by side (a job delivered twice, "Go on" pressed while a turn runs) therefore never take the same rows:
 * the second waits for the lock and finds the cursor moved. Each row is confirmed by exactly one turn, and the
 * answer's list of confirmed rows is exactly the rows that were confirmed.
 *
 * The handler answers how many rows the chunk held and whether it was the last one.
 */
readonly final class AnswerImportGroupChunk
{
    public function __construct(
        public string $answerId,
    ) {
    }
}
