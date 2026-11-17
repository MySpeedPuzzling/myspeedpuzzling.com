<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;
use SpeedPuzzling\Web\Value\CompetitionParticipantsLock;

/**
 * The backfill path's "What did you solve there?" (docs/features/competitions-management/creation-wizard.md
 * "Backfill"): one round per (category, puzzle) row - AddCompetitionRound (the event's day, start time not known, no
 * limit) and its puzzle (AddPuzzleToCompetitionRound, a catalogue puzzle or a new one) - all rows in one transaction
 * under the event's lock (the nested messages pass through it, LockUntilCommittedMiddleware).
 */
readonly final class SetUpPastEventRounds implements SerializedByLock
{
    /**
     * @param list<array{round: AddCompetitionRound, puzzle: AddPuzzleToCompetitionRound}> $rounds
     */
    public function __construct(
        public string $competitionId,
        public array $rounds,
    ) {
        foreach ($rounds as $row) {
            if (strtolower($row['round']->competitionId) !== strtolower($competitionId) || $row['puzzle']->roundId !== $row['round']->roundId->toString()) {
                throw new \InvalidArgumentException('Every round must belong to the event locked and carry its own puzzle.');
            }
        }
    }

    public function lockKey(): string
    {
        return CompetitionParticipantsLock::key($this->competitionId);
    }
}
