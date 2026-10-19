<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Insights;

use SpeedPuzzling\Web\Value\InsightsPeriod;

/**
 * What a monthly snapshot is taken over: a member's view of the last 12 months, no other filter.
 */
final class InsightsSnapshotCriteria
{
    public const InsightsPeriod PERIOD = InsightsPeriod::Last12Months;

    /** Players with fewer solo results in the period get no snapshot (nothing to compare yet) */
    public const int MIN_RESULTS = 10;

    public static function criteria(): InsightsCriteria
    {
        return InsightsCriteria::fromUserInput(['period' => self::PERIOD->value], isMember: true);
    }
}
