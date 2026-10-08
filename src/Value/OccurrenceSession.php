<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * One session of an occurrence whose rounds fall on separate days (docs/features/events-page/README.md, "Dates"):
 * the rounds of consecutive days (at most a day apart) - a monthly online competition held inside one edition is one
 * session per month. Only an occurrence with two or more sessions has them.
 */
readonly final class OccurrenceSession
{
    public function __construct(
        // 0-based, in date order
        public int $index,
        // how many sessions the occurrence has (2 or more)
        public int $count,
        // the link goes to the event page at `#round-<id>` of this round
        public string $firstRoundId,
        // the round's name when the session has a single round, else null
        public null|string $label,
    ) {
    }
}
