<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

readonly final class AddCompetitionParticipant
{
    public function __construct(
        public string $competitionId,
        public string $name,
        public null|string $country,
        public null|string $externalId,
        public null|string $playerId,
    ) {
    }
}
