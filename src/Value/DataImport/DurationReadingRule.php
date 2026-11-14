<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\DataImport;

/**
 * One way to read a time value of an ambiguous shape (DurationShape). The order of the cases is the order the
 * readings are offered in, and which of two rules that read every value of a column the same is kept.
 */
enum DurationReadingRule: string
{
    /** "17:36" = 17 min 36 s */
    case MinutesSeconds = 'minutes_seconds';
    /** "17:36" = 17 h 36 min */
    case HoursMinutes = 'hours_minutes';
    /** A cell's stored value as it is: 45:30:00 = 45 h 30 min */
    case AsStored = 'as_stored';
    /** A cell's stored hours and minutes were typed as minutes and seconds: 45:30:00 = 45 min 30 s */
    case StoredHoursAsMinutes = 'stored_hours_as_minutes';
    /** "237" = 237 min, "1.5" = 1.5 min */
    case Minutes = 'minutes';
    /** "237" = 237 s */
    case Seconds = 'seconds';
    /** "2" = 2 h, "1.5" = 1.5 h */
    case Hours = 'hours';
    /** "8.10" = 8 h 10 min */
    case HoursDotMinutes = 'hours_dot_minutes';
    /** "8.10" = 8 min 10 s */
    case MinutesDotSeconds = 'minutes_dot_seconds';
    /** A number below 1 with many decimals: the part of a day a spreadsheet stores a time as (0.048113 = 1:09:17) */
    case DayFraction = 'day_fraction';
}
