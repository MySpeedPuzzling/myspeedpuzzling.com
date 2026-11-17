<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * What LinkSolvingTimesToEvent did: times linked now (the event is public), times waiting for the event to become
 * public (WaitingEventLink), times that were linked to it already.
 */
readonly final class LinkedSolvingTimes
{
    public function __construct(
        public int $linked,
        public int $waiting,
        public int $alreadyLinked,
    ) {
    }
}
