<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;

/**
 * A puzzle the player holds more than one first try of.
 */
readonly final class FirstTryConflict
{
    /**
     * @param list<FirstTryTime> $times every result of the puzzle the player took part in and that is marked as a first try, oldest first
     */
    public function __construct(
        public FirstTryPuzzle $puzzle,
        public array $times,
        // The player's own solve without the tag from a day before all of them - maybe the real first try
        public null|DateTimeImmutable $earlierUnmarkedSolvedAt,
    ) {
    }

    /**
     * Preselected on the page: the oldest of the results the player marked.
     */
    public function suggestedTimeId(): string
    {
        return $this->times[0]->timeId;
    }
}
