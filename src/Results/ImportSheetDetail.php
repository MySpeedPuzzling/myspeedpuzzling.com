<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\DataImportSheetKind;

/**
 * One sheet of the signed-in player's import with its mapping, as the "Sheets and columns" screen reads it
 * (GetImportSheetScreen::sheet()).
 */
readonly final class ImportSheetDetail
{
    /**
     * @param null|array<mixed> $mapping ImportMapping's stored shape - null: not recognised yet
     * @param null|array<mixed> $options ImportSheetOptions' stored shape
     */
    public function __construct(
        public string $sheetId,
        /** '' for a text file - "The whole file" */
        public string $title,
        public int $position,
        public bool $hidden,
        public int $rows,
        public null|DataImportSheetKind $kind,
        /** 0: no mapping yet */
        public int $mappingVersion,
        /** Its rows are read for its current mapping */
        public bool $parsed,
        public null|array $mapping,
        public null|array $options,
        public int $rowsUnderstood,
        public int $rowsNeedLook,
        public int $rowsLeftOut,
        /** Why its set-up ended (DataImportSheet::SET_UP_*) - null while nothing failed */
        public null|string $failure,
        /** The import is in the state its sheets and columns can be changed in */
        public bool $beingSetUp,
        /** The sheet brand and pieces are looked up in, by the puzzle's name */
        public null|string $lookupSheetTitle = null,
        /** Rows a question that is not answered yet decides */
        public int $rowsWaiting = 0,
    ) {
    }
}
