<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\Insights;

/**
 * "When do I puzzle best?" Results per weekday are free; the pace per weekday, after breaks and by time of day are
 * members only. Time of day only with a time zone (`timesOfDay` null without one), "when you logged".
 */
readonly final class InsightsHabitsSection
{
    /** Days since the previous solo result: same day, 1, 2-7, 8-14, 15+ */
    public const array BREAK_BUCKETS = ['0', '1', '2-7', '8-14', '15+'];

    /**
     * @param list<InsightsHabitBucket> $weekdays Monday first
     * @param list<InsightsHabitBucket> $breaks
     * @param null|list<InsightsHabitBucket> $timesOfDay
     */
    public function __construct(
        public array $weekdays,
        public null|int $busiestWeekday,
        public array $breaks,
        public null|array $timesOfDay,
        public int $sampleSize,
        public bool $notSureYet,
    ) {
    }
}
