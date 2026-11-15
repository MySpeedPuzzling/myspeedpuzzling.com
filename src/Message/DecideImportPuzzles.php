<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Value\DataImport\ImportPuzzleDecisionChange;

/**
 * The player's word on one or many distinct puzzles of their import (docs/features/data-import/pairing.md
 * "Decisions"): this catalogue puzzle, keep as unlisted, leave out - or take the decision back. All of them or none:
 * a decision somebody changed meanwhile (ImportPuzzleDecidedMeanwhile) or a puzzle that may not be chosen
 * (ImportPuzzleChoiceRefused) refuses the whole message before anything is written. Each decision is remembered for
 * the player's next file (import_puzzle_decision).
 *
 * The message and its handler only: the screens that send it are the next stage's.
 */
readonly final class DecideImportPuzzles
{
    /**
     * @param list<ImportPuzzleDecisionChange> $decisions
     */
    public function __construct(
        public string $importId,
        public string $playerId,
        public array $decisions,
    ) {
    }
}
