<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\DataImport;

/**
 * What a cell handed to a value reader holds (docs/features/data-import/value-readers.md "Input").
 */
enum CellInputKind: string
{
    /** No value at all */
    case Blank = 'blank';
    case Text = 'text';
    case Number = 'number';
    /** An elapsed time ([h]:mm:ss), in seconds - may be over a day */
    case Duration = 'duration';
    /** A time without a date (h:mm:ss, h:mm), in seconds since midnight */
    case TimeOfDay = 'time_of_day';
    case Date = 'date';
    case Boolean = 'boolean';
}
