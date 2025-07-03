<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\CountryCode;

readonly final class ConnectedCompetitionParticipant
{
    public function __construct(
        public string $participantId,
        public string $participantName,
        public string $playerId,
        public string $playerName,
        public null|CountryCode $playerCountry,
        public null|int $fastestTime,
        public null|int $averageTime,
        public int $solvedPuzzleCount,
        /** @var array<string> */
        public array $rounds = [],
    ) {
    }
}
