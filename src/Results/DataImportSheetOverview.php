<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\DataImportSheetKind;

/**
 * A sheet of the signed-in player's import as the file has it (GetDataImports::sheetsOfPlayer()).
 */
readonly final class DataImportSheetOverview
{
    public function __construct(
        public int $position,
        public string $title,
        public bool $hidden,
        /** Rows with at least one value */
        public int $rows,
        /** The right-most column a value stands in */
        public int $columns,
        /** What the import takes the sheet for (docs/features/data-import/columns.md) - null: not recognised yet */
        public null|DataImportSheetKind $kind = null,
        /** The import has proposed its mapping */
        public bool $mapped = false,
        /** It has a question only the player can answer - its rows wait */
        public bool $waitsForAnAnswer = false,
        /** Its rows are parsed for its current mapping */
        public bool $parsed = false,
        /** Rows that are puzzles and hold nothing unclear */
        public int $rowsUnderstood = 0,
        /** Rows that are puzzles and wait with something to look at */
        public int $rowsNeedLook = 0,
        public int $rowsLeftOut = 0,
        /** Its set-up ended without its rows (a fault of ours) - nothing more happens to it by itself */
        public bool $failed = false,
        public string $sheetId = '',
        /** The version a change of the player must name (ConfirmImportMapping) */
        public int $mappingVersion = 0,
        /** Why its set-up ended (DataImportSheet::SET_UP_*) - null while nothing failed */
        public null|string $failure = null,
    ) {
    }

    /**
     * What the "Sheets and columns" screen says about the sheet (docs/features/data-import/columns-screen.md):
     * `failed`, `recognising`, `waiting` (for the player's answer), `left_out` (not puzzles, somebody else's),
     * `parsing`, `parsed`.
     */
    public function state(): string
    {
        return match (true) {
            $this->failed => 'failed',
            $this->mapped === false => 'recognising',
            $this->isLeftOut() => $this->parsed ? 'left_out' : 'parsing',
            $this->waitsForAnAnswer => 'waiting',
            $this->parsed === false => 'parsing',
            default => 'parsed',
        };
    }

    public function isLeftOut(): bool
    {
        return $this->kind === DataImportSheetKind::NotPuzzles || $this->kind === DataImportSheetKind::SomebodyElse;
    }
}
