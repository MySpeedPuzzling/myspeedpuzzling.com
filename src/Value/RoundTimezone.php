<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The time zone a competition round's start is typed in and shown in.
 *
 * `competition_round.starts_at` is the instant, stored as UTC. The organiser types the local start and picks the zone
 * on the round form; the zone is kept on the round (`competition_round.timezone`) so the edit form shows the same zone
 * and the same local time, and every page shows the start as the organiser typed it.
 *
 * Rounds saved before the zone was kept have none: they were typed in the zone the form pre-selected, the default of
 * the event's country - so that is the zone they are read in.
 */
final class RoundTimezone
{
    public const string FALLBACK = 'Europe/Prague';

    public static function resolve(null|string $storedTimezone, null|string $countryCode): string
    {
        if ($storedTimezone !== null && self::isValid($storedTimezone)) {
            return $storedTimezone;
        }

        return CountryCode::fromCode($countryCode)?->defaultTimezone() ?? self::FALLBACK;
    }

    public static function isValid(string $timezone): bool
    {
        return in_array($timezone, DateTimeZone::listIdentifiers(), true);
    }

    /**
     * @param string $localDateTime "Y-m-d H:i" (or with seconds) - the wall clock in $timezone
     */
    public static function toInstant(string $localDateTime, string $timezone): DateTimeImmutable
    {
        return new DateTimeImmutable($localDateTime, new DateTimeZone($timezone))
            ->setTimezone(new DateTimeZone('UTC'));
    }

    public static function toLocal(DateTimeImmutable $instant, string $timezone): DateTimeImmutable
    {
        return $instant->setTimezone(new DateTimeZone($timezone));
    }
}
