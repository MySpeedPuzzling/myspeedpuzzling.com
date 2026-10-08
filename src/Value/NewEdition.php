<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use DateTimeImmutable;
use Ramsey\Uuid\UuidInterface;

/**
 * One edition of AddEditions ("Add several dates"): dated one day (dateFrom = dateTo = $date).
 */
readonly final class NewEdition
{
    public function __construct(
        public UuidInterface $competitionId,
        public string $name,
        public DateTimeImmutable $date,
    ) {
    }
}
