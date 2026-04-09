<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use DateTimeImmutable;
use Ramsey\Uuid\UuidInterface;

readonly final class AddEdition
{
    public function __construct(
        public UuidInterface $competitionId,
        public string $seriesId,
        public string $name,
        public null|DateTimeImmutable $dateFrom,
        public null|DateTimeImmutable $dateTo,
        public null|string $registrationLink,
        public null|string $resultsLink,
    ) {
    }
}
