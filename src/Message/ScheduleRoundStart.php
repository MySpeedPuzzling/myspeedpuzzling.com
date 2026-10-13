<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use DateTimeImmutable;

/**
 * Starts the round's stopwatch at a moment ahead - "Start in 10 s" or a chosen time (docs/features/online-competitions/
 * README.md §5.1): the stopwatch status is `scheduled` until then, the phones count down to it.
 */
readonly final class ScheduleRoundStart
{
    public function __construct(
        public string $roundId,
        // A whole second, not in the past
        public DateTimeImmutable $startsAt,
        public string $actingPlayerId,
    ) {
    }
}
