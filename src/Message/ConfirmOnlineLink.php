<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;
use SpeedPuzzling\Web\Value\CompetitionParticipantsLock;

/**
 * The player connected to a participant opened its personal link signed in - the link is theirs
 * (docs/features/online-competitions/README.md §5.4): from now on the event page hands it to them ("Open my
 * competition page"). From compete_connect, after the connect or when the participant was theirs already. Serialised
 * with every other participant write of the event.
 */
readonly final class ConfirmOnlineLink implements SerializedByLock
{
    public function __construct(
        // The competition of the link the player opened
        public string $competitionId,
        public string $participantId,
        public string $playerId,
    ) {
    }

    public function lockKey(): string
    {
        return CompetitionParticipantsLock::key($this->competitionId);
    }
}
