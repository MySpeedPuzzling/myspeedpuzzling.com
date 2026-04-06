<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use DateTimeImmutable;
use Ramsey\Uuid\UuidInterface;

readonly final class AddEdition
{
    public function __construct(
        public UuidInterface $competitionId,
        public UuidInterface $roundId,
        public string $seriesId,
        public string $name,
        public DateTimeImmutable $startsAt,
        public int $minutesLimit,
        public null|string $registrationLink,
        public null|string $resultsLink,
    ) {
    }
}
