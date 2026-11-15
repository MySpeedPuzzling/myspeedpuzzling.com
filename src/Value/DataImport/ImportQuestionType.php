<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\DataImport;

/**
 * What a sheet can ask the player before its rows are parsed (docs/features/data-import/columns.md "Questions") -
 * only what neither the values nor the header could settle. Stored with a sheet's mapping - never rename one.
 */
enum ImportQuestionType: string
{
    /** What a shape of the time column means: `17:36`, a bare `237`, a time cell `1:02` (DurationShape) */
    case DurationShape = 'duration_shape';
    /** `Jan 23`, `10/23`: a day of a month, or a month of a year */
    case ShortDates = 'short_dates';
    /** Day first or month first, when no value settles it */
    case DateOrder = 'date_order';
    /** Typed dates beside day-first text dates: were they turned around by the spreadsheet? */
    case TypedDates = 'typed_dates';
    /** The year of dates written without one, when the sheet's name does not say it - or says another */
    case Year = 'year';
}
