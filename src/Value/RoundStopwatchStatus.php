<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use DateTimeImmutable;

/**
 * THE rule for what a round's stopwatch is doing at a moment (docs/features/competitions-management/README.md "Round
 * Stopwatch"). The stored `competition_round.stopwatch_status` is null (never started) | `scheduled` | `running` |
 * `stopped`. A scheduled start is a start in the future - the countdown - and it counts as running once its moment has
 * passed: nothing is written at that moment, so every reader asks at() instead of comparing the column. A reader that
 * does not (an old page, a query nobody updated) sees `scheduled`, i.e. "not running" - never a negative time.
 *
 * The browser twin is assets/round_stopwatch_clock.js (stopwatchStatusAt()).
 */
final class RoundStopwatchStatus
{
    public const string SCHEDULED = 'scheduled';
    public const string RUNNING = 'running';
    public const string STOPPED = 'stopped';

    /**
     * The status at $now: a scheduled start whose moment has come is running.
     */
    public static function at(null|string $status, null|DateTimeImmutable $startedAt, DateTimeImmutable $now): null|string
    {
        if ($status === self::SCHEDULED && $startedAt !== null && $startedAt <= $now) {
            return self::RUNNING;
        }

        return $status;
    }

    /**
     * The round's clock has started by $now - running, stopped, or a scheduled start whose moment has passed.
     */
    public static function hasStarted(null|string $status, null|DateTimeImmutable $startedAt, DateTimeImmutable $now): bool
    {
        return match (self::at($status, $startedAt, $now)) {
            self::RUNNING, self::STOPPED => true,
            self::SCHEDULED => false,
            // A start without a status is never written by the app - it counts once its moment has passed
            default => $startedAt !== null && $startedAt <= $now,
        };
    }

    /**
     * A start is scheduled and its moment has not come yet - the countdown, which a cancel may still call off.
     */
    public static function isCountingDown(null|string $status, null|DateTimeImmutable $startedAt, DateTimeImmutable $now): bool
    {
        return self::at($status, $startedAt, $now) === self::SCHEDULED;
    }
}
