<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * What a sheet of an import holds (docs/features/data-import/README.md "Data model").
 */
enum DataImportSheetKind: string
{
    case Results = 'results';
    case Owned = 'owned';
    case PuzzleList = 'puzzle_list';
    case NotPuzzles = 'not_puzzles';
    case SomebodyElse = 'somebody_else';
}
