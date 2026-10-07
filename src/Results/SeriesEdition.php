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
        // The edition's own logo - null when it has none (the series logo stands for it then)
        public null|string $logo = null,
        // The edition's own dates - shown when it has no round yet (startsAt null)
        public null|DateTimeImmutable $dateFrom = null,
        public null|DateTimeImmutable $dateTo = null,
    ) {
        $this->registrationLink = $registrationLink !== null
            ? $registrationLink . (str_contains($registrationLink, '?') ? '&' : '?') . 'utm_source=myspeedpuzzling'
            : null;
        $this->resultsLink = $resultsLink !== null
            ? $resultsLink . (str_contains($resultsLink, '?') ? '&' : '?') . 'utm_source=myspeedpuzzling'
            : null;
    }

    /**
     * Neither a round nor a date of its own - "Date not set" wherever the date would show.
     */
    public function isUndated(): bool
    {
        return $this->startsAt === null && $this->dateFrom === null;
    }
}
