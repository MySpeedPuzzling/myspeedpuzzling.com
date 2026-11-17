<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;
use SpeedPuzzling\Web\Value\CompetitionParticipantsLock;

/**
 * "I'm going" - or, on an event that manages registration, a registration (reserved or waitlisted by the free spots).
 * Takes turns with every other write to the event's participants, so two registrations never take the last spot both.
 *
 * With `participantId` it is a claim of a name from the organiser's list (ParticipantClaims, participant-linking.md
 * §5.1) - the handler returns its ClaimOutcome.
 */
readonly final class JoinCompetition implements SerializedByLock
{
    public function __construct(
        public string $competitionId,
        public string $playerId,
        public null|string $participantId = null,
        // A pick that lets go of the player's own paid registration: their explicit yes (`release_paid`) - kept on the
        // request under the approval policy and used at approval (participant-linking.md R1)
        public bool $releasesOwnRegistration = false,
    ) {
    }

    public function lockKey(): string
    {
        return CompetitionParticipantsLock::key($this->competitionId);
    }
}
