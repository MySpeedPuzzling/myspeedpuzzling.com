<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\DataImport;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\ImportPuzzleChoice;

/**
 * One decision of a DecideImportPuzzles message.
 */
readonly final class ImportPuzzleDecisionChange
{
    /**
     * @param ImportPuzzleChoice $choice `Undecided` takes the decision back
     * @param null|string $puzzleId the catalogue puzzle - with `Catalogue` only
     * @param null|DateTimeImmutable $seenDecidedAt the puzzle's `decided_at` as the page showed it (null: never
     *        decided) - another value in the database refuses the change
     */
    public function __construct(
        public string $importPuzzleId,
        public ImportPuzzleChoice $choice,
        public null|string $puzzleId,
        public null|DateTimeImmutable $seenDecidedAt,
    ) {
    }
}
