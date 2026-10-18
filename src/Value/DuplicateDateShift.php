<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use DateTimeImmutable;
use DateTimeZone;

/**
 * How a duplicated event's dates move when the copy gets a new first day (DuplicateCompetition): by whole calendar
 * days, each moment keeping its wall-clock time in its own zone.
 */
readonly final class DuplicateDateShift
{
    private function __construct(
        public int $days,
    ) {
    }

    /**
     * Calendar days from the source's first day to the new one - none when either is missing
     */
    public static function between(null|DateTimeImmutable $sourceDateFrom, null|DateTimeImmutable $newDateFrom): self
    {
        if ($sourceDateFrom === null || $newDateFrom === null) {
            return new self(0);
        }

        $utc = new DateTimeZone('UTC');
        $from = new DateTimeImmutable($sourceDateFrom->format('Y-m-d'), $utc);
        $to = new DateTimeImmutable($newDateFrom->format('Y-m-d'), $utc);

        return new self((int) $from->diff($to)->format('%r%a'));
    }

    /**
     * The same wall-clock time the shift's days later in `$timezone` (a round starting at 19:00 keeps starting at 19:00
     * across a daylight saving change), back in the zone the moment came in - Doctrine writes an object's own wall time
     */
    public function apply(null|DateTimeImmutable $moment, string $timezone = 'UTC'): null|DateTimeImmutable
    {
        if ($moment === null || $this->days === 0) {
            return $moment;
        }

        return $moment
            ->setTimezone(new DateTimeZone($timezone))
            ->modify(sprintf('%+d days', $this->days))
            ->setTimezone($moment->getTimezone());
    }
}
