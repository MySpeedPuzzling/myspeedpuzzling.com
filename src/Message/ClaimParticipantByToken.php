<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;
use SpeedPuzzling\Web\Value\CompetitionParticipantsLock;

/**
 * "Yes, link my profile" on a personal claim link's page (docs/features/competitions-management/participant-linking.md
 * §5.2, §6.5): the organiser handed the link to one person - it links at once under every claim policy. `$switch` = the
 * player, on the event's list as somebody else, said "Switch". The competition is the one the page read for the token;
 * the handler reads the token again under the event's lock and returns a ClaimLinkOutcome.
 */
readonly final class ClaimParticipantByToken implements SerializedByLock
{
    public function __construct(
        public string $competitionId,
        public string $token,
        public string $playerId,
        public bool $switch = false,
    ) {
    }

    public function lockKey(): string
    {
        return CompetitionParticipantsLock::key($this->competitionId);
    }
}
