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
 * `$bulkConfirm` - "Yes, these N are right" of the picture grid (docs/features/data-import/guided-flow.md §6.1): every
 * decision is then a catalogue puzzle, and each must be one that may be confirmed several at once
 * (ImportPuzzleBulkConfirm - the same name with the file's piece count, the barcode or the brand's own code, and
 * neither the brand nor the piece count differs). A message with more than one catalogue decision is held to the
 * same rule whether it says so or not: a wrong match is a result on the wrong puzzle's public leaderboard.
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
        public bool $bulkConfirm = false,
    ) {
    }
}
