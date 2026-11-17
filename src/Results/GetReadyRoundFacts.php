<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;

/**
 * One round of an event as the "Get ready" checklist sees it (GetEventGetReadyFacts) - W2 of the event creation
 * wizard, docs/features/competitions-management/toolkit/W-creation-wizard-research.md §3.10.
 */
readonly final class GetReadyRoundFacts
{
    public function __construct(
        public string $roundId,
        public string $name,
        public DateTimeImmutable $startsAt,
        public null|int $minutesLimit,
        // The stopwatch's status at the moment of reading (RoundStopwatchStatus::at())
        public null|string $stopwatchStatus,
        public null|DateTimeImmutable $stopwatchStartedAt,
        public bool $tableNumbersOff,
        public int $puzzlesCount,
        // Puzzles hidden until the round starts (competition_round_puzzle.hide_until_round_starts)
        public int $secretPuzzlesCount,
        // Its entries: solo participants going, or teams of a pairs/teams round - like GetRoundResultsOverview
        public int $entriesTotal,
        public int $entriesWithTableNumber,
    ) {
    }
}
