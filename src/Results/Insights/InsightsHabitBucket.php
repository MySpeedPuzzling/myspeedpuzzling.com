<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\Insights;

/**
 * One bar of a habits chart. key: ISO weekday 1-7, a break bucket (BREAK_BUCKETS) or an InsightsTimeOfDay value.
 * stats = your pace there against your usual (members).
 */
readonly final class InsightsHabitBucket
{
    public function __construct(
        public string $key,
        public int $count,
        public InsightsSegmentStats $stats,
    ) {
    }
}
