<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use Ramsey\Uuid\UuidInterface;

readonly final class ConvertCompetitionToSeries
{
    public function __construct(
        public string $competitionId,
        public UuidInterface $seriesId,
    ) {
    }
}
