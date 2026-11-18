<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;
use SpeedPuzzling\Web\Value\CompetitionParticipantsLock;

readonly final class LeaveCompetition implements SerializedByLock
{
    public function __construct(
        public string $competitionId,
        public string $playerId,
        // "Not me" from the ParticipantListedYou notification (participant-linking.md §6.6): only this participant is let
        // go of, as a refused pair (`not_me`) - the import never links it to the player again. Null = leaving the event
        public null|string $notMeParticipantId = null,
    ) {
    }

    public function lockKey(): string
    {
        return CompetitionParticipantsLock::key($this->competitionId);
    }
}
