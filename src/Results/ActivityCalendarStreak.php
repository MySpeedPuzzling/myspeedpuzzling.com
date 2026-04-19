<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

readonly final class ActivityCalendarStreak
{
    /**
     * @param list<string> $currentStreakDates List of 'Y-m-d' strings covered by the current streak (empty when current = 0).
     * @param list<string> $longestStreakDates List of 'Y-m-d' strings covered by run(s) matching the longest length. When multiple runs tie, all are included.
     */
    public function __construct(
        public int $current,
        public int $longest,
        public array $currentStreakDates,
        public array $longestStreakDates = [],
    ) {
    }
}
