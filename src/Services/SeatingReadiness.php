<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use DateTimeImmutable;
use SpeedPuzzling\Web\Results\RoundResultsOverview;

/**
 * When a round's management pages show the seating step "Tables: 180 / 200 assigned - recommended before the round
 * starts" (docs/features/competitions-management/seating.md): an in-person round that uses table numbers, has entrants
 * and has not started yet - its stopwatch never ran and it was due to start in the last 12 hours or later. A past
 * round (every existing event) shows nothing, so pages of rounds that are over stay as they were.
 */
final readonly class SeatingReadiness
{
    public const string RECENT_START = '-12 hours';

    public static function isShown(bool $competitionIsOnline, RoundResultsOverview $round, DateTimeImmutable $now): bool
    {
        return $competitionIsOnline === false
            && $round->tableNumbersOff === false
            && $round->entriesTotal > 0
            && $round->stopwatchStatus === null
            && $round->startsAt >= $now->modify(self::RECENT_START);
    }
}
