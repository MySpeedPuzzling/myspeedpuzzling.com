<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use DateTimeImmutable;

/**
 * Sandbox demo events only (docs/features/competitions-management/spws-demo-ux-leftovers.md §5): the stopwatch of a
 * demo round that is "running" with seeded results runs from the round's scheduled start - started at that moment when
 * it has passed (an organiser's Start can only start now), scheduled for it while it lies ahead. A stopwatch that has a
 * status (a rehearsal started, stopped or scheduled it, or an earlier run did) is left as it is.
 */
readonly final class StartSandboxDemoRoundStopwatch
{
    public function __construct(
        public string $roundId,
        // The round's scheduled start
        public DateTimeImmutable $startsAt,
    ) {
    }
}
