<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * One answer for a whole pile of the player's import is given (docs/features/data-import/guided-flow.md §6.3): the
 * pile, the answer and the state of the import the screen showed - never a puzzle. The handler writes it down under
 * the import's lock (DataImportPileAnswer) - or says why it does not: another answer for the pile still runs, the
 * import changed, puzzles are still looked up, nothing waits. Nothing is decided here.
 */
readonly final class StartImportPuzzlePileAnswer
{
    public function __construct(
        public string $answerId,
        public string $importId,
        public string $playerId,
        public string $pile,
        public string $choice,
        public string $shownRevision,
    ) {
    }
}
