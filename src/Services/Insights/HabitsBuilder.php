<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Insights;

use SpeedPuzzling\Web\Results\Insights\InsightsHabitBucket;
use SpeedPuzzling\Web\Results\Insights\InsightsHabitsSection;
use SpeedPuzzling\Web\Value\InsightsTimeOfDay;

/**
 * "When do I puzzle best?" - per weekday (the solve day: the date you gave, else the logging day in your zone), after
 * a break (days since your previous solo result) and by time of day (only with your time zone, "when you logged",
 * only results logged on their solve day), each with how many results and your pace there (SegmentEffect).
 */
readonly final class HabitsBuilder
{
    /**
     * @param list<InsightsRow> $rows the selection
     */
    public function build(array $rows, bool $hasTimezone): InsightsHabitsSection
    {
        $byWeekday = array_fill(1, 7, []);
        $byBreak = array_fill_keys(InsightsHabitsSection::BREAK_BUCKETS, []);
        $byTime = [];

        foreach (InsightsTimeOfDay::cases() as $timeOfDay) {
            $byTime[$timeOfDay->value] = [];
        }

        foreach ($rows as $row) {
            $byWeekday[$row->weekday][] = $row;

            if ($row->daysSincePrevious !== null) {
                $byBreak[self::breakBucket($row->daysSincePrevious)][] = $row;
            }

            if ($row->timeOfDay !== null) {
                $byTime[$row->timeOfDay->value][] = $row;
            }
        }

        $weekdays = [];
        $busiest = null;
        $busiestCount = 0;

        foreach ($byWeekday as $weekday => $weekdayRows) {
            $weekdays[] = self::bucket((string) $weekday, $weekdayRows);

            if (count($weekdayRows) > $busiestCount) {
                $busiest = $weekday;
                $busiestCount = count($weekdayRows);
            }
        }

        $breaks = [];

        foreach ($byBreak as $key => $breakRows) {
            $breaks[] = self::bucket((string) $key, $breakRows);
        }

        $timesOfDay = null;

        if ($hasTimezone) {
            $timesOfDay = [];

            foreach ($byTime as $key => $timeRows) {
                $timesOfDay[] = self::bucket($key, $timeRows);
            }
        }

        return new InsightsHabitsSection(
            weekdays: $weekdays,
            busiestWeekday: $busiest,
            breaks: $breaks,
            timesOfDay: $timesOfDay,
            sampleSize: count($rows),
            notSureYet: $rows === [],
        );
    }

    public static function breakBucket(int $days): string
    {
        return match (true) {
            $days === 0 => '0',
            $days === 1 => '1',
            $days <= 7 => '2-7',
            $days <= 14 => '8-14',
            default => '15+',
        };
    }

    /**
     * @param list<InsightsRow> $rows
     */
    private static function bucket(string $key, array $rows): InsightsHabitBucket
    {
        return new InsightsHabitBucket($key, count($rows), SegmentEffect::ofRows($rows));
    }
}
