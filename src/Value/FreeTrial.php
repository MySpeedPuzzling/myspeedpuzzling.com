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

    /**
     * How old an account must be before the trial can be started - and before the one-time offer modal
     * may open. Together with the logged puzzles it keeps out accounts made only to collect a trial, and
     * leaves a newcomer's first days to the site itself.
     */
    public const int MINIMUM_ACCOUNT_AGE_DAYS = 7;

    /**
     * Logged puzzles a player needs before the trial can be started. An account made only to collect
     * a trial has none - and without a few times of their own, a player has nothing for the insights,
     * statistics and predictions to show anyway.
     */
    public const int MINIMUM_LOGGED_PUZZLES = 5;

    /**
     * The moment an account registered at `$registeredAt` is old enough.
     */
    public static function unlocksAt(DateTimeImmutable $registeredAt): DateTimeImmutable
    {
        return $registeredAt->add(new DateInterval('P' . self::MINIMUM_ACCOUNT_AGE_DAYS . 'D'));
    }

    public static function endsAt(DateTimeImmutable $startedAt): DateTimeImmutable
    {
        return $startedAt->add(new DateInterval('P' . self::DAYS . 'D'));
    }
}
