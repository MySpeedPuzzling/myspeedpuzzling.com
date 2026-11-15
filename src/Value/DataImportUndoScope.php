<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * What an Undo of an import takes back (docs/features/data-import/adding.md "Undo").
 */
enum DataImportUndoScope: string
{
    /** Chosen rows */
    case Rows = 'rows';
    /** Everything one "Add" added */
    case Batch = 'batch';
    /** Everything the import added */
    case Import = 'import';
}
