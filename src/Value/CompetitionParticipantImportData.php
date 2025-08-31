<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use Ramsey\Uuid\UuidInterface;

readonly final class CompetitionParticipantImportData
{
    public function __construct(
        public string $name,
        public CountryCode $country,
        public UuidInterface $groupId,
    ) {
    }
}
