<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use DateTimeImmutable;

/**
 * Starts the round's stopwatch at a moment ahead - "Start in 10 s" or a chosen time (docs/features/online-competitions/
 * README.md §5.1): the stopwatch status is `scheduled` until then, the phones count down to it. With $revealPuzzles the
 * round's puzzles waiting for the stopwatch start are revealed at that moment (still hidden until then) - see
 * StartRoundStopwatch. A reveal still ahead from an earlier countdown is always withdrawn first: a moved countdown never
 * reveals at its old moment.
 */
readonly final class ScheduleRoundStart
{
    public function __construct(
        public string $roundId,
        // A whole second, not in the past
        public DateTimeImmutable $startsAt,
        public string $actingPlayerId,
        public bool $revealPuzzles = false,
        public null|string $revealListHash = null,
    ) {
    }
}
