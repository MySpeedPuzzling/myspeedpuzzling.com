<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

readonly final class RoundTeamMember
{
    public function __construct(
        public string $participantRoundId,
        public string $participantName,
        public null|string $participantCountry,
        public null|string $playerId,
        public null|string $playerName,
    ) {
    }
}
