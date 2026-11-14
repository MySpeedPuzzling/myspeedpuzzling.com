<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\DataImport;

/**
 * How exactly a date is known (docs/features/data-import/README.md D13): a day, only its month, only its year.
 */
enum DatePrecision: string
{
    case Day = 'day';
    case Month = 'month';
    case Year = 'year';
}
