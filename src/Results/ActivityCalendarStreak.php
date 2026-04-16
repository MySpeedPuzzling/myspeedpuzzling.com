<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

readonly final class ActivityCalendarStreak
{
    /**
     * @param list<string> $currentStreakDates List of 'Y-m-d' strings covered by the current streak (empty when current = 0).
     */
    public function __construct(
        public int $current,
        public int $longest,
        public array $currentStreakDates,
    ) {
    }
}
