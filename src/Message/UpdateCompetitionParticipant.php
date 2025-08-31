<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Value\CountryCode;

readonly final class UpdateCompetitionParticipant
{
    public function __construct(
        public UuidInterface $competitionId,
        public UuidInterface $groupId,
        public string $name,
        public CountryCode $country,
    ) {
    }
}
