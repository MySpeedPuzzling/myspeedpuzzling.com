<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\DataImport;

/**
 * How sure the import is about a date it read (docs/features/data-import/value-readers.md "Dates").
 */
enum DateCertainty: string
{
    /** Nothing in the cell */
    case NoDate = 'no_date';
    /** One reading */
    case Certain = 'certain';
    /** Numbers that are a date in more than one order ("3/4/2023") - the column, or the player, settles it */
    case OrderOpen = 'order_open';
    /** A short date whose last number is a day or a two-digit year ("Jan 23", "10/23") - the column, or the player, settles it */
    case DayOrYearOpen = 'day_or_year_open';
    /** No date can be read - a problem of the row */
    case Unreadable = 'unreadable';
}
