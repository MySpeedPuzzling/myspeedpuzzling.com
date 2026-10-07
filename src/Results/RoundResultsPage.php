<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\DifficultyTier;
use SpeedPuzzling\Web\Value\EventTitle;

readonly final class RoundResultsPage
{
    /**
     * @param list<EditionRoundDetail> $rounds rounds of the competition that have a result page, by start
     * @param array<string, list<RoundResult>> $results keyed by puzzle id
     * @param list<string> $stillSecret
     * @param null|array<string, DifficultyTier> $difficultyTiers of the round's revealed puzzles, members only (null
     *                                                         for everyone else)
     */
    public function __construct(
        public CompetitionEvent $event,
        // How the page title names the event - full name, with the series of an edition and the year
        public EventTitle $eventTitle,
        public EditionRoundDetail $round,
        public array $rounds,
        public null|EditionRoundDetail $previousRound,
        public null|EditionRoundDetail $nextRound,
        public array $results,
        public bool $hasStarted,
        public bool $canAddTime,
        public null|string $officialResultsLink,
        // Puzzles of the round the site still keeps secret (another round holds them longer): left out, no times yet -
        // one line each saying when they open
        public array $stillSecret = [],
        public null|array $difficultyTiers = null,
        // The organiser's published official results - null keeps the page exactly as without them
        public null|PublishedRoundResults $officialResults = null,
    ) {
    }

    public function resultsCount(): int
    {
        return array_sum(array_map('count', $this->results));
    }
}
