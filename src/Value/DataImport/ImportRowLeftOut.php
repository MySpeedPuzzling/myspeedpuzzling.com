<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\DataImport;

/**
 * Why a row of a sheet is no data of the import (docs/features/data-import/README.md "Which rows are puzzles") -
 * listed under "Left out", never an error. Stored in the row's `parsed` - never rename a value.
 */
enum ImportRowLeftOut: string
{
    /** Above or below the table, or beside it */
    case OutsideTable = 'outside_table';
    case Header = 'header';
    /** The header again, further down (a printed page, a second block) */
    case RepeatedHeader = 'repeated_header';
    case Blank = 'blank';
    /** Nothing but default values: unticked checkboxes, formulas over empty cells */
    case DefaultsOnly = 'defaults_only';
    /** A results row with the puzzle prefilled but no date and no time */
    case Template = 'template';
    /** A total, an average, a count under the data */
    case Summary = 'summary';
    case NotAPuzzle = 'not_a_puzzle';
    /** The row says the puzzle is not the player's (sold, returned, borrowed) and holds no result */
    case NotMine = 'not_mine';
    /** The sheet is "not puzzles" or somebody else's */
    case SheetLeftOut = 'sheet_left_out';
}
