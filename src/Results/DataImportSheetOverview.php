<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

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
    ) {
    }
}
