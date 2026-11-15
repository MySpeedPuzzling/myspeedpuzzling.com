<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\SpreadsheetReader;

/**
 * The reader's clocks (docs/features/data-import/reader.md "Why 60 s of CPU and 300 s on the wall").
 *
 * A file's budget of seconds is counted in the **process's own CPU time** (user + system, `getrusage`): what the
 * reader itself burns, whatever else the server is busy with - the same file costs the same on a quiet and on a
 * loaded host, where wall-clock seconds were measured to stretch seven times. The wall clock stays as a last resort
 * (SpreadsheetLimits::$maxWallSeconds): time that is no CPU time (a disk that does not answer) must end too.
 *
 * `getrusage` counts the whole process: in a process of several threads the other threads' work is counted as well -
 * the reader runs in the Messenger consumer, which has one.
 */
final class CpuClock
{
    /**
     * CPU time of this process so far, in nanoseconds.
     */
    public static function nanoseconds(): int
    {
        $usage = getrusage();

        if ($usage === false) {
            // No accounting on this system: the wall clock is the only clock there is
            return self::wallNanoseconds();
        }

        $seconds = 0;
        $microseconds = 0;

        foreach (['ru_utime', 'ru_stime'] as $kind) {
            $seconds += is_int($usage[$kind . '.tv_sec'] ?? null) ? $usage[$kind . '.tv_sec'] : 0;
            $microseconds += is_int($usage[$kind . '.tv_usec'] ?? null) ? $usage[$kind . '.tv_usec'] : 0;
        }

        return $seconds * 1_000_000_000 + $microseconds * 1_000;
    }

    public static function wallNanoseconds(): int
    {
        return (int) hrtime(true);
    }
}
