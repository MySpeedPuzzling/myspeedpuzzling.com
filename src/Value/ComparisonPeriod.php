<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * "Solved in": which times count, by the day they were solved. The custom from-to range is members-only.
 */
enum ComparisonPeriod: string
{
    case All = 'all';
    case Last12Months = '12m';
    case Last6Months = '6m';
    case Last3Months = '3m';
    case Custom = 'custom';

    public function months(): null|int
    {
        return match ($this) {
            self::Last12Months => 12,
            self::Last6Months => 6,
            self::Last3Months => 3,
            self::All, self::Custom => null,
        };
    }
}
