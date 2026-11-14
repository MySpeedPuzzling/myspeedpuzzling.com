<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\DataImport;

/**
 * The unit a column's header names for its numbers - "Time (min)", "Hours", "Zeit (Std)" (HeaderMatcher). It answers
 * what a bare number of that column means; the rows' pace never overrides it.
 */
enum DurationUnit: string
{
    case Seconds = 'seconds';
    case Minutes = 'minutes';
    case Hours = 'hours';

    public function rule(): DurationReadingRule
    {
        return match ($this) {
            self::Seconds => DurationReadingRule::Seconds,
            self::Minutes => DurationReadingRule::Minutes,
            self::Hours => DurationReadingRule::Hours,
        };
    }
}
