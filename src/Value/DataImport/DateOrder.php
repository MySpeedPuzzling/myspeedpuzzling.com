<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\DataImport;

/**
 * The order of the parts of a date written in numbers ("3/4/2023"). A value without a year follows the same order
 * with the year left out: day/month, month/day, month/day.
 */
enum DateOrder: string
{
    case DayMonthYear = 'dmy';
    case MonthDayYear = 'mdy';
    case YearMonthDay = 'ymd';
}
