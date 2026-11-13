<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Events;

use Ramsey\Uuid\UuidInterface;

/**
 * A player claimed a spot of an imported event result (docs/features/event-results-import/README.md "Claiming a spot") -
 * the other claimers are told who joined, so a wrong claim is seen by the people who know.
 */
readonly final class EventResultSlotClaimed
{
    public function __construct(
        public UuidInterface $puzzleSolvingTimeId,
        public UuidInterface $claimedByPlayerId,
    ) {
    }
}
