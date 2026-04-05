<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

readonly final class JoinCompetition
{
    public function __construct(
        public string $competitionId,
        public string $playerId,
        public null|string $participantId = null,
    ) {
    }
}
