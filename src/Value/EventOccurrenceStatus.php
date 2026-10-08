<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * Where an occurrence (a one-time event or an edition) stands on a day (docs/features/events-page/README.md, "Dates").
 */
enum EventOccurrenceStatus: string
{
    // start <= today <= end (but see Ongoing)
    case Live = 'live';
    // start > today
    case Upcoming = 'upcoming';
    // end < today
    case Past = 'past';
    // a one-time in-person event without a date
    case Tba = 'tba';
    // a one-time online event without a date, or an occurrence without rounds spanning more than
    // OccurrenceDates::LONG_SPAN_DAYS while it runs - never live
    case Ongoing = 'ongoing';
    // an edition without a date and without rounds
    case DateNotSet = 'notset';

    public function isComing(): bool
    {
        return $this === self::Live || $this === self::Upcoming || $this === self::Tba;
    }
}
