<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use DateTimeImmutable;

/**
 * How exactly a date is known: a day, only its month, only its year (docs/features/data-import/README.md D13). The
 * one precision enum: the import's readers use it, the spreadsheet reader hands its cells over in it, and it is what
 * `puzzle_solving_time.finished_at_precision` stores (month / year; a day is NULL) - never rename a value.
 */
enum DatePrecision: string
{
    case Day = 'day';
    case Month = 'month';
    case Year = 'year';

    /**
     * Stored on a result (`puzzle_solving_time.finished_at_precision`): nothing for a day - NULL is "to the day".
     */
    public static function ofStored(null|string $stored): self
    {
        return $stored === null ? self::Day : self::tryFrom($stored) ?? self::Day;
    }

    /**
     * The day a date known only this exactly is stored on: the first day of its month or year, at midnight
     * (docs/features/data-import/date-precision.md "The anchor").
     */
    public function anchorOf(DateTimeImmutable $date): DateTimeImmutable
    {
        return match ($this) {
            self::Day => $date->setTime(0, 0),
            self::Month => $date->setDate((int) $date->format('Y'), (int) $date->format('n'), 1)->setTime(0, 0),
            self::Year => $date->setDate((int) $date->format('Y'), 1, 1)->setTime(0, 0),
        };
    }

    /**
     * Whether two days are the same at this precision: the same day, the same month, the same year.
     */
    public function holds(DateTimeImmutable $anchor, DateTimeImmutable $day): bool
    {
        $format = match ($this) {
            self::Day => 'Y-m-d',
            self::Month => 'Y-m',
            self::Year => 'Y',
        };

        return $anchor->format($format) === $day->format($format);
    }

    /**
     * SQL: the result's solve day is a real day - what every reader that needs a day (calendar, streaks, weekdays,
     * "the same day", matching an event by its date) asks before it reads `finished_at`.
     */
    public static function sqlExactDay(string $alias): string
    {
        $column = $alias === '' ? 'finished_at_precision' : $alias . '.finished_at_precision';

        return $column . ' IS NULL';
    }

    /**
     * SQL (text): what "the same day" compares for two results - the solve day (else the day it was logged) as
     * `2021-03-15`, and for a result known to its month or year that period itself: `2021-03`, `2021`. Two results
     * are of "the same day" when both know the same day - or both know only the same month, or the same year. The
     * stored first day of a period never equals a real day.
     */
    public static function sqlSolvedPeriod(string $alias): string
    {
        return <<<SQL
CASE {$alias}.finished_at_precision
    WHEN 'month' THEN TO_CHAR({$alias}.finished_at, 'YYYY-MM')
    WHEN 'year' THEN TO_CHAR({$alias}.finished_at, 'YYYY')
    ELSE CAST(CAST(COALESCE({$alias}.finished_at, {$alias}.tracked_at) AS DATE) AS TEXT)
END
SQL;
    }
}
