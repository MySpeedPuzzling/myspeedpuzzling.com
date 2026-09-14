<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use DateTimeImmutable;

/**
 * Lifetime membership has no column of its own - it is a `membership.granted_until` so far in the
 * future that it never runs out, which keeps every existing "is the membership active" check working.
 * Anything that shows the date to a player must ask isLifetime() first and say "Lifetime" instead.
 */
final class LifetimeMembership
{
    public const string GRANTED_UNTIL = '2199-12-31 23:59:59';

    public static function grantedUntil(): DateTimeImmutable
    {
        return new DateTimeImmutable(self::GRANTED_UNTIL);
    }

    public static function isLifetime(null|DateTimeImmutable $grantedUntil): bool
    {
        // Compared by year, so a timezone shift on the way through the database can't flip the answer
        return $grantedUntil !== null && (int) $grantedUntil->format('Y') >= 2199;
    }
}
