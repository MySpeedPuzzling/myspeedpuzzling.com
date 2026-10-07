<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Whether a new registration to an event with managed registration can be made now
 * (docs/features/competitions-management/registration.md). Applies to new registrations only: picking your name from the
 * organiser's list, "Change" and leaving always work.
 */
enum RegistrationAvailability: string
{
    case Open = 'open';
    case NotYetOpen = 'not_yet_open';
    case Closed = 'closed';
    // The event is not publicly visible (not approved yet, or rejected) - nobody registers to it
    case NotPublic = 'not_public';

    /**
     * Without a closing time of its own the window closes when the event is over ($eventEndsAt, eventEndsAt()) - a
     * past event never takes a registration nor sends its payment instructions.
     */
    public static function ofWindow(
        DateTimeImmutable $now,
        null|DateTimeImmutable $opensAt,
        null|DateTimeImmutable $closesAt,
        null|DateTimeImmutable $eventEndsAt = null,
    ): self {
        $closesAt ??= $eventEndsAt;

        if ($opensAt !== null && $now < $opensAt) {
            return self::NotYetOpen;
        }

        if ($closesAt !== null && $now >= $closesAt) {
            return self::Closed;
        }

        return self::Open;
    }

    /**
     * The end of the event's last day (date_to, else date_from) in the event's zone, as an instant (UTC). Null for an
     * event without dates - its window stays open until the organiser closes it.
     */
    public static function eventEndsAt(null|DateTimeImmutable $dateFrom, null|DateTimeImmutable $dateTo, string $timezone): null|DateTimeImmutable
    {
        $lastDay = $dateTo ?? $dateFrom;

        if ($lastDay === null) {
            return null;
        }

        return new DateTimeImmutable($lastDay->format('Y-m-d') . ' 00:00:00', new DateTimeZone($timezone))
            ->modify('+1 day')
            ->setTimezone(new DateTimeZone('UTC'));
    }
}
