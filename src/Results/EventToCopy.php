<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;

/**
 * One of the organiser's events offered by the wizard's entry screen to copy (GetEventsToCopy)
 */
readonly final class EventToCopy
{
    public function __construct(
        public string $competitionId,
        public string $name,
        public null|string $seriesName,
        public null|DateTimeImmutable $dateFrom,
        public bool $isDraft,
    ) {
    }
}
