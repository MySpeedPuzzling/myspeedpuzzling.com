<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use DateInterval;
use DateTimeImmutable;

/**
 * The free trial of membership (docs/features/free-trial/README.md): full membership for a few days,
 * once per player, without Stripe. A trial is nothing but a `membership.granted_until` grant, so every
 * membership gate already understands it - only its length lives here.
 */
final class FreeTrial
{
    public const int DAYS = 10;

    public static function endsAt(DateTimeImmutable $startedAt): DateTimeImmutable
    {
        return $startedAt->add(new DateInterval('P' . self::DAYS . 'D'));
    }
}
