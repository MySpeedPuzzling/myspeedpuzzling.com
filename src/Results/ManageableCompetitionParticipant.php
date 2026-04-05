<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\CountryCode;
use SpeedPuzzling\Web\Value\ParticipantSource;

readonly final class ManageableCompetitionParticipant
{
    public function __construct(
        public string $participantId,
        public string $participantName,
        public null|CountryCode $participantCountry,
        public null|string $externalId,
        public ParticipantSource $source,
        public null|DateTimeImmutable $deletedAt,
        public null|string $playerId,
        public null|string $playerName,
        public null|string $playerCode,
        public null|CountryCode $playerCountry,
        /** @var array<string> */
        public array $roundIds = [],
    ) {
    }

    public function isDeleted(): bool
    {
        return $this->deletedAt !== null;
    }
}
