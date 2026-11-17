<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;
use SpeedPuzzling\Web\Value\CompetitionParticipantsLock;

/**
 * "This is me" on a participant (docs/features/competitions-management/participant-linking.md §5.1) - the competitor
 * page and a scanned tag (package L3's surfaces). The same decision as JoinCompetition's pick (ParticipantClaims), kept
 * out of the managed-registration flow of JoinCompetition. The handler returns the ClaimOutcome.
 */
readonly final class ClaimParticipant implements SerializedByLock
{
    public function __construct(
        public string $competitionId,
        public string $playerId,
        public string $participantId,
        public bool $releasesOwnRegistration = false,
    ) {
    }

    public function lockKey(): string
    {
        return CompetitionParticipantsLock::key($this->competitionId);
    }
}
