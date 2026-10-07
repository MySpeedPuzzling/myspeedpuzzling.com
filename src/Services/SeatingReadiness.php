<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use DateTimeImmutable;

/**
 * THE rule for the seating step "Tables: 180 / 200 assigned - recommended before the round starts"
 * (docs/features/competitions-management/seating.md) - one rule for the round list, the results desk, the results
 * overview, the stopwatch control page, the seating page and the live entry (GetRoundResultsOverview computes it into
 * RoundResultsOverview::$showsTablesReadiness, the JSON's `tablesReadiness`).
 *
 * Shown for an in-person round that uses table numbers, has entrants and has not started yet: its stopwatch never ran
 * and it was due to start in the last 12 hours or later (a late start keeps it). A round under way, a finished one and a
 * past one never nag - every existing event's pages stay as they were. A recommendation, never a block.
 */
final readonly class SeatingReadiness
{
    public const string RECENT_START = '-12 hours';

    public static function isShown(
        bool $competitionIsOnline,
        bool $tableNumbersOff,
        int $entriesTotal,
        null|string $stopwatchStatus,
        DateTimeImmutable $startsAt,
        DateTimeImmutable $now,
    ): bool {
        return $competitionIsOnline === false
            && $tableNumbersOff === false
            && $entriesTotal > 0
            && $stopwatchStatus === null
            && $startsAt >= $now->modify(self::RECENT_START);
    }
}
