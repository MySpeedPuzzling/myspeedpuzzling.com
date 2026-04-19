<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Results\ActivityCalendarStreak;

readonly final class ActivityCalendarStreakCalculator
{
    public function __construct(
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param list<string> $activeDays Unique 'Y-m-d' strings of days the player was active. Order-insensitive.
     */
    public function calculate(array $activeDays): ActivityCalendarStreak
    {
        if ($activeDays === []) {
            return new ActivityCalendarStreak(current: 0, longest: 0, currentStreakDates: [], longestStreakDates: []);
        }

        $uniqueDays = array_values(array_unique($activeDays));
        sort($uniqueDays);

        /** @var non-empty-list<non-empty-list<string>> $runs */
        $runs = [[$uniqueDays[0]]];
        $previousDate = new DateTimeImmutable($uniqueDays[0]);

        for ($i = 1, $n = count($uniqueDays); $i < $n; $i++) {
            $dayStr = $uniqueDays[$i];

            if ($dayStr === $previousDate->modify('+1 day')->format('Y-m-d')) {
                $runs[array_key_last($runs)][] = $dayStr;
            } else {
                $runs[] = [$dayStr];
            }

            $previousDate = new DateTimeImmutable($dayStr);
        }

        $longest = 0;
        foreach ($runs as $run) {
            $longest = max($longest, count($run));
        }

        $longestStreakDates = [];
        foreach ($runs as $run) {
            if (count($run) === $longest) {
                $longestStreakDates = array_merge($longestStreakDates, $run);
            }
        }

        $lastRun = $runs[array_key_last($runs)];
        $lastDay = $lastRun[array_key_last($lastRun)];

        $today = $this->clock->now()->format('Y-m-d');
        $yesterday = $this->clock->now()->modify('-1 day')->format('Y-m-d');

        if ($lastDay === $today || $lastDay === $yesterday) {
            return new ActivityCalendarStreak(
                current: count($lastRun),
                longest: $longest,
                currentStreakDates: $lastRun,
                longestStreakDates: $longestStreakDates,
            );
        }

        return new ActivityCalendarStreak(
            current: 0,
            longest: $longest,
            currentStreakDates: [],
            longestStreakDates: $longestStreakDates,
        );
    }
}
