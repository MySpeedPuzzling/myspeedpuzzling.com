<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\Spreadsheet;

/**
 * How exact a date cell is, as far as its number format shows it: "mmmm yyyy" shows a month, "yyyy" a year.
 */
enum DatePrecision: string
{
    case Day = 'day';
    case Month = 'month';
    case Year = 'year';
}
