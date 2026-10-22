<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The one "now" of the test suite (docs/features/time-and-clock/README.md, R4).
 *
 * config/services_test.php freezes the `clock` service at this instant, the fixtures are built from that clock, and a
 * test derives every date from here (`TestClock::now()->modify('-3 days')`) or uses an absolute literal - nothing in the
 * suite reads the real date, so the result is the same on any day and the cached test database never goes stale.
 *
 * The instant must stay in the past of every run: Symfony mints a few values with `time()` that the app then checks on
 * its clock (sign-in link expiry), so never move it into the future. Moving it is a deliberate change - the database
 * cache hash in tests/bootstrap.php includes it, so the fixtures are rebuilt.
 */
final class TestClock
{
    /**
     * A Wednesday, mid-month, midday UTC: no day/week/month boundary in any zone ±12 h, no DST switch within ±10 days
     */
    public const string FROZEN_NOW = '2026-10-14 12:00:00';

    /**
     * The instant as an SQL literal, for test SQL that used NOW() - Postgres keeps the real time
     */
    public const string SQL_NOW = "TIMESTAMP '" . self::FROZEN_NOW . "'";

    /**
     * The instant's day as an SQL literal, for test SQL that used CURRENT_DATE (TestClockTest keeps it on FROZEN_NOW's day)
     */
    public const string SQL_TODAY = "DATE '2026-10-14'";

    public static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable(self::FROZEN_NOW, new DateTimeZone('UTC'));
    }

    /**
     * For a `:now` parameter bound like the application binds it (`CAST(:now AS timestamp)`)
     */
    public static function sqlParameter(): string
    {
        return self::now()->format('Y-m-d H:i:s');
    }

    /**
     * Instead of time()
     */
    public static function timestamp(): int
    {
        return self::now()->getTimestamp();
    }

    /**
     * Instead of (int) (microtime(true) * 1000)
     */
    public static function milliseconds(): int
    {
        return self::timestamp() * 1000;
    }
}
