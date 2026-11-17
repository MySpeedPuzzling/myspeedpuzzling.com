<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use DateTimeImmutable;
use DateTimeZone;
use SpeedPuzzling\Web\Exceptions\InvalidLocalTime;

/**
 * The time zone a competition round's start is typed in and shown in.
 *
 * `competition_round.starts_at` is the instant, stored as UTC. The organiser types the local start and picks the zone
 * on the round form; the zone is kept on the round (`competition_round.timezone`) so the edit form shows the same zone
 * and the same local time, and every page shows the start as the organiser typed it.
 *
 * Rounds saved before the zone was kept (2026-10) have none. The form then pre-selected the default of the event's own
 * country, else Europe/Prague, and organisers outside that zone picked theirs - the zone is not known. They are read
 * in the default of the event's country, else of its series' country (an edition often has no country of its own -
 * the Canadian Speed Puzzlers editions were typed in Toronto, not in the Prague the form offered), else Prague. For
 * an event outside its country's default zone (US Central, Mountain, Pacific) the time reads in the default zone -
 * the instant is right, the organiser can set the zone (docs/TODO.md).
 *
 * A round with none of them - no zone of its own, no country on the event nor on its series (an online series) - is
 * read in FALLBACK only because something must be: isAssumed(). Its zone is then named neutrally ("Central European
 * Time", ZonedDateTimeFormatter::timezoneName()) - "Czechia Time" would say the event is in Czechia.
 */
final class RoundTimezone
{
    public const string FALLBACK = 'Europe/Prague';

    public static function resolve(null|string $storedTimezone, null|string ...$countryCodes): string
    {
        if ($storedTimezone !== null && self::isValid($storedTimezone)) {
            return $storedTimezone;
        }

        foreach ($countryCodes as $countryCode) {
            $country = CountryCode::fromCode($countryCode);

            if ($country !== null) {
                return $country->defaultTimezone();
            }
        }

        return self::FALLBACK;
    }

    /**
     * Whether the zone resolve() gives names no place the event is known to be in: no country on the event nor on its
     * series, and the zone is FALLBACK - not saved (the round predates kept zones) or saved as FALLBACK, which is what
     * the round form pre-selects for an event without a country, so it was most likely never chosen. Such a zone is
     * named without a place ("Central European Time") - still right if Prague was picked on purpose.
     */
    public static function isAssumed(null|string $storedTimezone, null|string ...$countryCodes): bool
    {
        foreach ($countryCodes as $countryCode) {
            if (CountryCode::fromCode($countryCode) !== null) {
                return false;
            }
        }

        return self::resolve($storedTimezone) === self::FALLBACK;
    }

    /**
     * resolve() as an SQL expression (the IANA zone name) over SQL expressions of the stored zone and the country codes,
     * in the same order - exact, so SQL reads a round's local day the way PHP does (SolvingTimeRoundMatch). Built from
     * the PHP zone list and CountryCode::defaultTimezone(), once per process: neither changes at runtime, so sharing it
     * in FrankenPHP worker mode is safe.
     */
    public static function sqlResolve(string $storedTimezone, string ...$countryCodes): string
    {
        /** @var null|array{zones: string, countries: list<array{codes: string, zone: string}>} $parts */
        static $parts = null;

        if ($parts === null) {
            $quote = static fn (string $value): string => "'" . str_replace("'", "''", $value) . "'";
            $byZone = [];

            foreach (CountryCode::cases() as $country) {
                $byZone[$country->defaultTimezone()][] = $quote($country->name);
            }

            $countries = [];

            foreach ($byZone as $zone => $codes) {
                $countries[] = ['codes' => implode(', ', $codes), 'zone' => $quote($zone)];
            }

            $parts = [
                'zones' => implode(', ', array_map($quote, DateTimeZone::listIdentifiers())),
                'countries' => $countries,
            ];
        }

        $branches = ["WHEN {$storedTimezone} IN ({$parts['zones']}) THEN {$storedTimezone}"];

        foreach ($countryCodes as $countryCode) {
            foreach ($parts['countries'] as $country) {
                $branches[] = "WHEN LOWER({$countryCode}) IN ({$country['codes']}) THEN {$country['zone']}";
            }
        }

        return 'CASE ' . implode(' ', $branches) . " ELSE '" . self::FALLBACK . "' END";
    }

    public static function isValid(string $timezone): bool
    {
        return in_array($timezone, DateTimeZone::listIdentifiers(), true);
    }

    /**
     * The instant a typed local time means - refused when it does not exist exactly as typed: overflowing input
     * ("2026-02-31 25:70"), a wall time skipped by a daylight-saving change, or one that happens twice.
     *
     * @param string $localDateTime "Y-m-d H:i"
     *
     * @throws InvalidLocalTime
     */
    public static function toInstant(string $localDateTime, string $timezone): DateTimeImmutable
    {
        return self::parseLocal($localDateTime, 'Y-m-d H:i', $timezone);
    }

    /**
     * @throws InvalidLocalTime
     */
    public static function parseLocal(string $value, string $format, string $timezone): DateTimeImmutable
    {
        $zone = new DateTimeZone($timezone);
        $local = DateTimeImmutable::createFromFormat('!' . $format, $value, $zone);
        $errors = DateTimeImmutable::getLastErrors();

        if ($local === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new InvalidLocalTime();
        }

        // Skipped by a daylight-saving change (PHP moves it) or overflowing: it does not read back as typed
        if ($local->format($format) !== $value) {
            throw new InvalidLocalTime();
        }

        // Happens twice (the hour repeated when the clocks go back) - which one is meant cannot be told
        foreach (['-1 hour', '+1 hour'] as $shift) {
            $other = $local->setTimezone(new DateTimeZone('UTC'))->modify($shift)->setTimezone($zone);

            if ($other->format($format) === $value) {
                throw new InvalidLocalTime();
            }
        }

        return $local->setTimezone(new DateTimeZone('UTC'));
    }

    public static function toLocal(DateTimeImmutable $instant, string $timezone): DateTimeImmutable
    {
        return $instant->setTimezone(new DateTimeZone($timezone));
    }
}
