<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * One row of an import for its row sheet (docs/features/data-import/pairing-screens.md "The row sheet"): the cells
 * as the file has them, what was understood, what the player answered - and its sheet's mapping, which says what
 * each cell is.
 */
readonly final class ImportRowDetail
{
    /**
     * @param array<int, array<string, mixed>> $cells
     * @param array<mixed> $answers
     * @param null|array<mixed> $mapping
     */
    public function __construct(
        public ImportSheetRow $row,
        public string $rowId,
        public string $sheetId,
        public string $sheetTitle,
        /** A DataImportRowState's value */
        public string $state,
        public array $cells,
        public array $answers,
        public null|array $mapping,
        public string $stamp,
        /** Its sheet is read for its current mapping, and nothing was made of the row yet */
        public bool $canBeAnswered,
        public bool $beingSetUp,
    ) {
    }
}
