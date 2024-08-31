<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\CountryCode;

readonly final class ConnectedWjpcParticipant
{
    public function __construct(
        public string $participantId,
        public string $playerId,
        public string $playerName,
        public null|CountryCode $playerCountry,
        public null|int $fastestTime,
        public null|int $averageTime,
        public int $solvedPuzzleCount,
        public string $wjpcName,
        public null|int $rank2023,
        /** @var array<string> */
        public array $rounds,
    ) {
    }
}
