<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use DateTimeImmutable;
use DateTimeZone;
use SpeedPuzzling\Web\Results\EditionRoundDetail;

/**
 * A start time as the detail pages show it (docs/features/events-page/detail-pages.md, "Times and time zones"): the
 * instant, the zone it is read in (RoundTimezone::resolve()) and whether that zone is only assumed. Twig event_time()
 * writes it in the zone with the zone named; online events add the visitor's own time in the browser.
 */
readonly final class EventTime
{
    public DateTimeImmutable $instant;

    public function __construct(
        DateTimeImmutable $instant,
        public string $zone,
        public bool $zoneAssumed = false,
    ) {
        $this->instant = $instant->setTimezone(new DateTimeZone('UTC'));
    }

    public static function fromRound(EditionRoundDetail $round): self
    {
        return new self($round->startsAt, $round->timezone, $round->timezoneAssumed);
    }

    public static function fromOccurrenceRound(OccurrenceRound $round): self
    {
        return new self($round->startsAt, $round->zone, $round->zoneAssumed);
    }

    /**
     * The `datetime` attribute and the browser's input: "2026-06-17T02:00:00Z"
     */
    public function isoInstant(): string
    {
        return $this->instant->format('Y-m-d\TH:i:s\Z');
    }
}
