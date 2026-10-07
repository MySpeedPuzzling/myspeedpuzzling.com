<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use DateTimeImmutable;

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

    public static function ofWindow(DateTimeImmutable $now, null|DateTimeImmutable $opensAt, null|DateTimeImmutable $closesAt): self
    {
        if ($opensAt !== null && $now < $opensAt) {
            return self::NotYetOpen;
        }

        if ($closesAt !== null && $now >= $closesAt) {
            return self::Closed;
        }

        return self::Open;
    }
}
