<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;
use SpeedPuzzling\Web\Value\CompetitionParticipantsLock;

/**
 * The event creation wizard's Basics saved again (W-creation-wizard-research.md §3.3): the event's details and its
 * single round's start following the date and start time - in one transaction. The handler dispatches EditCompetition,
 * then EditCompetitionRound (the round's start and zone, every other field kept as the round has it) or, for a draft
 * without a round yet, AddCompetitionRound.
 *
 * Takes the event's participants lock (EditCompetitionRound does) for the whole transaction - the nested
 * EditCompetitionRound passes through it (LockUntilCommittedMiddleware).
 */
readonly final class SaveEventWizardBasics implements SerializedByLock
{
    public function __construct(
        public string $competitionId,
        public EditCompetition $competition,
        public null|EditCompetitionRound $roundEdit = null,
        public null|AddCompetitionRound $newRound = null,
    ) {
        if ($competition->competitionId !== $competitionId) {
            throw new \InvalidArgumentException('The event edited must be the one locked.');
        }

        if ($roundEdit !== null && $roundEdit->competitionId !== $competitionId) {
            throw new \InvalidArgumentException('The round edited must belong to the event.');
        }

        if ($newRound !== null && $newRound->competitionId !== $competitionId) {
            throw new \InvalidArgumentException('The new round must belong to the event.');
        }
    }

    public function lockKey(): string
    {
        return CompetitionParticipantsLock::key($this->competitionId);
    }
}
