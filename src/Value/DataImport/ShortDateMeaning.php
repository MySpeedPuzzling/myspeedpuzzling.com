<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\DataImport;

/**
 * What the last number of a short date is - "Jan 23", "10/23": a day of the month (the year is not written), or a
 * two-digit year (the day is not). One answer per column (DateOrderResolver), never per value.
 */
enum ShortDateMeaning: string
{
    /** "Jan 23" = 23 January, "10/23" = 23 October - the year unknown */
    case DayOfMonth = 'day_of_month';
    /** "Jan 23" = January 2023, "10/23" = October 2023 - a month, no day */
    case MonthOfYear = 'month_of_year';
}
