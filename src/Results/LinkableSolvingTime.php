<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\PuzzlingType;

/**
 * One of the player's own results on "Link my results" (GetLinkableSolvingTimes)
 */
readonly final class LinkableSolvingTime
{
    public function __construct(
        public string $timeId,
        public string $puzzleId,
        public string $puzzleName,
        public null|string $brandName,
        public int $piecesCount,
        public null|int $seconds,
        public DateTimeImmutable $solvedAt,
        public PuzzlingType $puzzlingType,
        // The event or series the time is linked to now (the player's own data - shown as it is)
        public null|string $currentEventName,
        public bool $isLinkedHere,
        // A puzzle of the event's rounds (an existing event's visible ones only)
        public bool $isEventPuzzle,
        // Solved within the event's days
        public bool $isOnEventDay,
        public bool $suspicious,
    ) {
    }

    /**
     * Pre-ticked: a result of that day (and of one of its puzzles when the event has puzzles) that is linked to nothing
     * yet - smart forms: what is surely it, and nothing that would move a link the player made
     */
    public function preTicked(bool $eventHasPuzzles): bool
    {
        return $this->isOnEventDay
            && ($eventHasPuzzles === false || $this->isEventPuzzle)
            && $this->currentEventName === null;
    }
}
