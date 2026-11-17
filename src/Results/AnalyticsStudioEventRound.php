<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\RoundCategory;

/**
 * One slugged round of the studio round's event (AnalyticsStudioRound::$eventRounds): the round switcher and an
 * entrant's earlier published rounds at this event (analytics-studio.md R19).
 */
readonly final class AnalyticsStudioEventRound
{
    public function __construct(
        public string $id,
        public string $name,
        public string $slug,
        public RoundCategory $category,
        public DateTimeImmutable $startsAt,
        public bool $published,
        public bool $isPick,
        public null|int $puzzlesToSolve,
    ) {
    }
}
