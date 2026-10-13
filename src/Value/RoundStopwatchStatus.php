<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use DateTimeImmutable;

/**
 * THE rule for what a round's stopwatch is doing at a moment (docs/features/competitions-management/README.md "Round
 * Stopwatch") - "counting down or running" is decided here and nowhere else: the stopwatch readers ask at(), and
 * RoundClock (the online rounds' reading, which adds "ended" at the time limit) derives its status from it.
 *
 * The stored `competition_round.stopwatch_status` is null (never started) | `scheduled` | `running` | `stopped`. A start
 * in the future is the countdown and a start that has come runs - whatever was stored (`scheduled`, or `running` written
 * ahead of time): nothing is written at the start moment, so every reader asks at() instead of comparing the column. A
 * reader that does not (an old page, a query nobody updated) sees `scheduled`, i.e. "not running" - never a negative
 * time. Moments compare in whole seconds, like the TIMESTAMP(0) columns keep them (and RoundClock's milliseconds).
 *
 * The browser twin is assets/round_stopwatch_clock.js (stopwatchStatusAt()).
 */
final class RoundStopwatchStatus
{
    public const string SCHEDULED = 'scheduled';
    public const string RUNNING = 'running';
    public const string STOPPED = 'stopped';

    /**
     * The status at $now: a scheduled or running stopwatch counts down while its start lies ahead and runs from it.
     * Stopped, never started and a status without a start stay as stored.
     */
    public static function at(null|string $status, null|DateTimeImmutable $startedAt, DateTimeImmutable $now): null|string
    {
        if (($status === self::SCHEDULED || $status === self::RUNNING) && $startedAt !== null) {
            return $startedAt->getTimestamp() > $now->getTimestamp() ? self::SCHEDULED : self::RUNNING;
        }

        return $status;
    }

    /**
     * The round's clock has started by $now - running, stopped, or a scheduled start whose moment has passed. A start
     * without a status (never written by the app) counts as nothing, as it does for RoundClock.
     */
    public static function hasStarted(null|string $status, null|DateTimeImmutable $startedAt, DateTimeImmutable $now): bool
    {
        $statusNow = self::at($status, $startedAt, $now);

        return $statusNow === self::RUNNING || $statusNow === self::STOPPED;
    }
}
