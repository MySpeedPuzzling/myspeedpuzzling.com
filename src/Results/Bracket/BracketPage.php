<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\Bracket;

use SpeedPuzzling\Web\Results\CompetitionEvent;
use SpeedPuzzling\Web\Value\EventTitle;

/**
 * Everything the public bracket page shows (competition toolkit A9, BracketPageBuilder).
 */
readonly final class BracketPage
{
    /**
     * @param list<Bracket> $brackets one per category; empty while every declared step is broken
     */
    public function __construct(
        public CompetitionEvent $event,
        public EventTitle $eventTitle,
        public array $brackets,
    ) {
    }

    /**
     * Some round of the bracket has published results - otherwise the page has nothing to index yet.
     */
    public function hasPublishedRound(): bool
    {
        foreach ($this->brackets as $bracket) {
            foreach ($bracket->rounds() as $round) {
                if ($round->isPublished()) {
                    return true;
                }
            }
        }

        return false;
    }
}
