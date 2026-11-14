<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\Spreadsheet;

/**
 * What a cell holds (docs/features/data-import/reader.md "Cell kinds"). An empty cell has no kind - it is not there.
 */
enum CellKind: string
{
    case Text = 'text';
    case Number = 'number';
    /** A calendar day (and how exactly its format shows it); a date with a time keeps the time aside */
    case Date = 'date';
    /** An elapsed time ([h]:mm:ss), also over 24 hours */
    case Duration = 'duration';
    /** A time without a date (h:mm:ss, h:mm AM/PM) */
    case TimeOfDay = 'time_of_day';
    case Boolean = 'boolean';
    /** #DIV/0!, #N/A, #REF! … */
    case Error = 'error';
}
