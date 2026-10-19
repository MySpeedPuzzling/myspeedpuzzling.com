<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\Insights;

/**
 * A point of the typical newcomer's curve, by first tries (no date: it is everybody's).
 */
readonly final class TypicalPacePoint
{
    public function __construct(
        public int $firstTries,
        public int $paceSeconds,
    ) {
    }
}
