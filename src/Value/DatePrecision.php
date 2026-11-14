<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * How exactly a date is known: a day, only its month, only its year (docs/features/data-import/README.md D13). The
 * one precision enum: the import's readers use it, the spreadsheet reader hands its cells over in it, and it is what
 * `finished_at_precision` will store - never rename a value.
 */
enum DatePrecision: string
{
    case Day = 'day';
    case Month = 'month';
    case Year = 'year';
}
