<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

readonly final class AssignParticipantToTeam
{
    public function __construct(
        public string $participantRoundId,
        public null|string $teamId,
    ) {
    }
}
