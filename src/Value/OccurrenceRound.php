<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use DateTimeImmutable;

/**
 * A round as the events page dates it (OccurrenceDates::sessions()): its start instant and the zone its day is read in
 * (RoundTimezone::resolve() of the round's zone, the event's and the series' country).
 */
readonly final class OccurrenceRound
{
    public function __construct(
        public string $id,
        public string $name,
        public DateTimeImmutable $startsAt,
        public string $zone,
    ) {
    }

    /**
     * The calendar day the round starts on in its zone, at 00:00 UTC
     */
    public function localDay(): DateTimeImmutable
    {
        return OccurrenceDates::localDay($this->startsAt, $this->zone);
    }
}
