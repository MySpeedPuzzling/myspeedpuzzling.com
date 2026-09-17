<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

readonly final class RoundResultsPage
{
    /**
     * @param list<EditionRoundDetail> $rounds rounds of the competition that have a result page, by start
     * @param array<string, list<RoundResult>> $results keyed by puzzle id
     */
    public function __construct(
        public CompetitionEvent $event,
        public EditionRoundDetail $round,
        public array $rounds,
        public null|EditionRoundDetail $previousRound,
        public null|EditionRoundDetail $nextRound,
        public array $results,
        public bool $hasStarted,
        public bool $canAddTime,
        public null|string $officialResultsLink,
    ) {
    }

    public function resultsCount(): int
    {
        return array_sum(array_map('count', $this->results));
    }
}
