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
    /** Puzzles the player wants - every row goes to the wish list */
    case WishList = 'wish_list';
    case PuzzleList = 'puzzle_list';
    case NotPuzzles = 'not_puzzles';
    case SomebodyElse = 'somebody_else';
}
