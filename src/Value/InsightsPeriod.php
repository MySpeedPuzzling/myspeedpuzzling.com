<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * The Insights period filter (docs/features/insights/computation.md "Core sections" §1). Free players get the last
 * 3 or 6 months only (README §3); a past year is the `PastYear` case with its year next to it in InsightsCriteria.
 */
enum InsightsPeriod: string
{
    case Last3Months = '3m';
    case Last6Months = '6m';
    case Last12Months = '12m';
    case Last24Months = '24m';
    case ThisYear = 'year';
    case PastYear = 'past_year';
    case All = 'all';
    case Custom = 'custom';

    public function months(): null|int
    {
        return match ($this) {
            self::Last3Months => 3,
            self::Last6Months => 6,
            self::Last12Months => 12,
            self::Last24Months => 24,
            default => null,
        };
    }

    public function isFree(): bool
    {
        return $this === self::Last3Months || $this === self::Last6Months;
    }
}
