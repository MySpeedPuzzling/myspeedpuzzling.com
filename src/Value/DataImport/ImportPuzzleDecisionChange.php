<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\DataImport;

use SpeedPuzzling\Web\Value\ImportPuzzleChoice;

/**
 * One decision of a DecideImportPuzzles message.
 */
readonly final class ImportPuzzleDecisionChange
{
    /**
     * @param ImportPuzzleChoice $choice `Undecided` takes the decision back
     * @param null|string $puzzleId the catalogue puzzle - with `Catalogue` only
     * @param int $seenVersion the puzzle's `decision_version` as the page showed it - another number in the
     *        database refuses the change
     */
    public function __construct(
        public string $importPuzzleId,
        public ImportPuzzleChoice $choice,
        public null|string $puzzleId,
        public int $seenVersion,
    ) {
    }
}
