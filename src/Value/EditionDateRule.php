<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use SpeedPuzzling\Web\Message\AddEditions;

/**
 * The dates of "Add several dates" (docs/features/organizations/README.md, D15, P15): the first `count` days on or
 * after `starting` that match the rule - every week on a weekday, the Nth weekday of every month (1st-4th, which every
 * month has), or the last weekday of every month. Days are date-only values at 00:00 UTC (OccurrenceDates), so they
 * never move a day in any zone. Nothing is stored: each date becomes an edition of its own.
 */
readonly final class EditionDateRule
{
    public const int MAX_COUNT = AddEditions::MAX;

    /**
     * @param int $weekday ISO-8601: 1 = Monday … 7 = Sunday
     * @param int $nth 1-4, the week of the month for EditionDateRuleKind::NthWeekday
     */
    public function __construct(
        public EditionDateRuleKind $kind,
        public int $weekday,
        public DateTimeImmutable $starting,
        public int $count,
        public int $nth = 1,
    ) {
        if ($weekday < 1 || $weekday > 7) {
            throw new InvalidArgumentException('The weekday is 1 (Monday) to 7 (Sunday).');
        }

        if ($count < 1 || $count > self::MAX_COUNT) {
            throw new InvalidArgumentException(sprintf('The count is 1 to %d.', self::MAX_COUNT));
        }

        if ($nth < 1 || $nth > 4) {
            throw new InvalidArgumentException('The week of the month is 1 to 4.');
        }
    }

    /**
     * @return list<DateTimeImmutable>
     */
    public function dates(): array
    {
        $start = self::day($this->starting);

        if ($this->kind === EditionDateRuleKind::Weekly) {
            $first = $start->modify(sprintf('+%d days', ($this->weekday - (int) $start->format('N') + 7) % 7));
            $dates = [];

            for ($i = 0; $i < $this->count; $i++) {
                $dates[] = $first->modify(sprintf('+%d days', 7 * $i));
            }

            return $dates;
        }

        $dates = [];
        $month = $start->modify('first day of this month');

        // The starting month may have its day before `starting` already - at most one month more than the count
        for ($i = 0; count($dates) < $this->count && $i <= $this->count; $i++) {
            $day = $this->kind === EditionDateRuleKind::NthWeekday ? $this->nthIn($month) : $this->lastIn($month);

            if ($day >= $start) {
                $dates[] = $day;
            }

            $month = $month->modify('first day of next month');
        }

        return $dates;
    }

    private function nthIn(DateTimeImmutable $firstOfMonth): DateTimeImmutable
    {
        $offset = ($this->weekday - (int) $firstOfMonth->format('N') + 7) % 7;

        return $firstOfMonth->modify(sprintf('+%d days', $offset + 7 * ($this->nth - 1)));
    }

    private function lastIn(DateTimeImmutable $firstOfMonth): DateTimeImmutable
    {
        $last = $firstOfMonth->modify('last day of this month');

        return $last->modify(sprintf('-%d days', ((int) $last->format('N') - $this->weekday + 7) % 7));
    }

    private static function day(DateTimeImmutable $date): DateTimeImmutable
    {
        return new DateTimeImmutable($date->format('Y-m-d'), new DateTimeZone('UTC'));
    }
}
