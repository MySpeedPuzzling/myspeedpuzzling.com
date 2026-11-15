<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\HandledOutsideTransaction;

/**
 * One turn of one answer for a whole pile of an import's puzzles (ImportPuzzlePile,
 * docs/features/data-import/guided-flow.md §6.3) - on the `imports` queue, for a pile too large for a request: the
 * next chunk of the pile's puzzles that wait takes the answer (DecideImportPuzzles - a message with a transaction
 * of its own), then the job sends itself again with where it got to. It carries no puzzle: each turn works out
 * which puzzles of the pile wait **then**. Delivered twice or started again it repeats nothing - a puzzle that was
 * decided no longer waits.
 */
readonly final class AnswerImportPuzzlePile implements HandledOutsideTransaction
{
    public function __construct(
        public string $importId,
        public string $playerId,
        /** A key of GetImportPuzzleScreen::PILES */
        public string $pile,
        /** ImportPuzzleChoice::Unlisted or LeftOut - what the pile allows (ImportPuzzlePile::CHOICES) */
        public string $choice,
        public null|string $after = null,
    ) {
    }
}
