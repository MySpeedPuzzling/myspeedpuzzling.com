<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * What a turn of an answer for a pile did (ImportPuzzlePile): how many puzzles took the answer, how many were left
 * as the player decided meanwhile, where the next turn goes on - and, with `$endedAs`, that the answer has ended
 * (DataImportPileAnswer::DONE, CHANGED, FAILED). An answer that has ended is not touched again.
 */
readonly final class RecordImportPuzzlePileAnswer
{
    public function __construct(
        public string $answerId,
        public int $decided = 0,
        public int $skipped = 0,
        public null|string $lastPuzzleId = null,
        public null|string $endedAs = null,
    ) {
    }
}
