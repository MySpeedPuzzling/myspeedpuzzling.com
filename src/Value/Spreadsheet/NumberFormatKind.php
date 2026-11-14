<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\Spreadsheet;

/**
 * What a number format makes of a number (NumberFormatClassifier).
 */
enum NumberFormatKind: string
{
    case General = 'general';
    case Number = 'number';
    /** "@": the cell is formatted as text */
    case Text = 'text';
    /** A date, with or without a time next to it */
    case Date = 'date';
    /** Hours, minutes, seconds of a day - no date part */
    case Time = 'time';
    /** Elapsed time: [h], [m] or [s] */
    case Duration = 'duration';
}
