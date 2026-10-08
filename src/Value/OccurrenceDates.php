<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The days an occurrence (a one-time event or an edition) takes place on (docs/features/events-page/README.md,
 * "Dates"). Days are date-only values at 00:00 UTC.
 *
 * An edition is dated by its first round's start, else its date_from (else date_to) - the rule of the series page. A
 * round start is converted to the event's own zone before taking its day: an evening round in Toronto is that
 * evening's date, not the next UTC day. Its end is the later of date_to and the day of its last round, kept only when
 * after the start. A one-time event is dated by date_from/date_to.
 */
readonly final class OccurrenceDates
{
    public function __construct(
        public null|DateTimeImmutable $start,
        public null|DateTimeImmutable $end,
    ) {
    }

    public static function ofEvent(null|DateTimeImmutable $dateFrom, null|DateTimeImmutable $dateTo): self
    {
        $start = self::dayOf($dateFrom ?? $dateTo);
        $end = self::dayOf($dateTo ?? $dateFrom);

        return new self($start, $start !== null && $end !== null && $end > $start ? $end : null);
    }

    /**
     * @param string $zone RoundTimezone::resolve() of the first round's zone, the edition's and the series' country
     */
    public static function ofEdition(
        null|DateTimeImmutable $firstRoundStartsAt,
        null|DateTimeImmutable $lastRoundStartsAt,
        string $zone,
        null|DateTimeImmutable $dateFrom,
        null|DateTimeImmutable $dateTo,
    ): self {
        $start = $firstRoundStartsAt !== null
            ? self::localDay($firstRoundStartsAt, $zone)
            : self::dayOf($dateFrom ?? $dateTo);

        if ($start === null) {
            return new self(null, null);
        }

        $end = self::dayOf($dateTo);

        if ($lastRoundStartsAt !== null) {
            $lastRoundDay = self::localDay($lastRoundStartsAt, $zone);
            $end = $end === null || $lastRoundDay > $end ? $lastRoundDay : $end;
        }

        return new self($start, $end !== null && $end > $start ? $end : null);
    }

    /**
     * The calendar day of a stored date (its date part as stored), at 00:00 UTC.
     */
    public static function dayOf(null|DateTimeImmutable $value): null|DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }

        return new DateTimeImmutable($value->format('Y-m-d'), new DateTimeZone('UTC'));
    }

    /**
     * The calendar day an instant falls on in $zone, at 00:00 UTC.
     */
    public static function localDay(DateTimeImmutable $instant, string $zone): DateTimeImmutable
    {
        return new DateTimeImmutable($instant->setTimezone(new DateTimeZone($zone))->format('Y-m-d'), new DateTimeZone('UTC'));
    }

    /**
     * Today's date (UTC), at 00:00 UTC - "today" of the events page is the server's UTC date.
     */
    public static function today(DateTimeImmutable $now): DateTimeImmutable
    {
        return new DateTimeImmutable($now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d'), new DateTimeZone('UTC'));
    }

    public function status(DateTimeImmutable $today, bool $isEdition, bool $isOnline): EventOccurrenceStatus
    {
        if ($this->start === null) {
            if ($isEdition) {
                return EventOccurrenceStatus::DateNotSet;
            }

            return $isOnline ? EventOccurrenceStatus::Ongoing : EventOccurrenceStatus::Tba;
        }

        $day = self::today($today);

        if (($this->end ?? $this->start) < $day) {
            return EventOccurrenceStatus::Past;
        }

        return $this->start <= $day ? EventOccurrenceStatus::Live : EventOccurrenceStatus::Upcoming;
    }
}
