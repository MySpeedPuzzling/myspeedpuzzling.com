<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\DataImport;

/**
 * The shapes of a time value that have more than one meaning - each is answered once per column
 * (DurationColumnResolver), never per row.
 */
enum DurationShape: string
{
    /** Text of two parts: "17:36" - minutes:seconds or hours:minutes */
    case TwoPartText = 'two_part_text';
    /** A time-of-day cell without seconds: "1:02" typed, stored as 1 h 2 min */
    case TimeOfDayCell = 'time_of_day_cell';
    /** A duration cell of 24 hours or more: "45:30" typed, stored as 45 h 30 min */
    case LongDurationCell = 'long_duration_cell';
    /** A duration cell under 24 hours whose seconds are :00 - taken as stored unless the column says otherwise */
    case ZeroSecondsDurationCell = 'zero_seconds_duration_cell';
    /** A number without a unit: "237", "8.10" */
    case BareNumber = 'bare_number';
    /** A number with two decimals and a unit: "1.30 h" - 1.3 hours or 1 h 30 min */
    case DecimalWithUnit = 'decimal_with_unit';
}
