<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\HandledOutsideTransaction;

/**
 * One turn of one answer for a whole pile of an import's puzzles (DataImportPileAnswer, ImportPuzzlePile,
 * docs/features/data-import/guided-flow.md §6.3) - on the `imports` queue, for a pile too large for a request: the
 * next chunk of the pile's puzzles takes the answer, each step a message with a transaction of its own; then the
 * job sends itself again. It carries the answer's id only: everything is read from the answer's row, so a turn
 * delivered twice, or one that waited in a long queue, repeats nothing and never goes on after the answer ended.
 */
readonly final class AnswerImportPuzzlePile implements HandledOutsideTransaction
{
    public function __construct(
        public string $answerId,
    ) {
    }
}
