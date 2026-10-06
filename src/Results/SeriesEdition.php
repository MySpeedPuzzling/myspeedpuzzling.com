<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\RoundTimezone;

readonly final class SeriesEdition
{
    public null|string $registrationLink;
    public null|string $resultsLink;

    public function __construct(
        public string $competitionId,
        public string $name,
        public null|string $editionSlug,
        public null|DateTimeImmutable $startsAt,
        public null|int $minutesLimit,
        public int $roundCount,
        public int $puzzleCount,
        public int $participantCount,
        null|string $registrationLink,
        null|string $resultsLink,
        // The zone startsAt is shown in - see RoundTimezone
        public string $timezone = RoundTimezone::FALLBACK,
    ) {
        $this->registrationLink = $registrationLink !== null
            ? $registrationLink . (str_contains($registrationLink, '?') ? '&' : '?') . 'utm_source=myspeedpuzzling'
            : null;
        $this->resultsLink = $resultsLink !== null
            ? $resultsLink . (str_contains($resultsLink, '?') ? '&' : '?') . 'utm_source=myspeedpuzzling'
            : null;
    }
}
