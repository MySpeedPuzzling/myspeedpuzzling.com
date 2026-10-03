<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use DateTimeImmutable;

/**
 * The calendar month the Country Cup shows (docs/features/players-page/README.md): this month so far, or last month's
 * final standings.
 */
enum CountryCupPeriod: string
{
    case ThisMonth = 'this_month';
    case LastMonth = 'last_month';

    // In a month's first days "this month" has barely started, so the Cup opens on last month's final standings
    public const int LAST_MONTH_FIRST_DAYS = 7;

    /**
     * Uses the date's own timezone - the clock's, the same one the community stats cron cuts its months in.
     */
    public static function defaultAt(DateTimeImmutable $now): self
    {
        return (int) $now->format('j') <= self::LAST_MONTH_FIRST_DAYS ? self::LastMonth : self::ThisMonth;
    }
}
