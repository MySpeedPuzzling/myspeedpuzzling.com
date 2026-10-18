<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use IntlTimeZone;

/**
 * The one rule for what `player.timezone` may hold (docs/features/player-timezone.md): an IANA identifier PHP lists in
 * `\DateTimeZone::listIdentifiers()` - nothing else is ever stored.
 *
 * Browsers do not always report the current tzdata name: Chrome answers `Asia/Calcutta` or `Europe/Kiev` (the CLDR
 * names ICU keeps), while PHP lists `Asia/Kolkata` and `Europe/Kyiv`. Such an alias is turned into the listed zone it
 * links to (same rules, same clock); anything that is not a zone at all is refused.
 */
final class TimezoneIdentifier
{
    /**
     * @var null|array<string, true>
     */
    private static null|array $listed = null;

    /**
     * @var null|array<string, string>
     */
    private static null|array $aliases = null;

    /**
     * @return null|string the listed identifier, or null for an empty or unknown value
     */
    public static function normalize(mixed $value): null|string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '' || strlen($value) > 64) {
            return null;
        }

        if (self::isListed($value)) {
            return $value;
        }

        return self::aliases()[$value] ?? null;
    }

    public static function isListed(string $value): bool
    {
        return isset(self::listed()[$value]);
    }

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return array_keys(self::listed());
    }

    /**
     * Alias → listed identifier, for the settings field to preselect what a browser reports under an old name.
     *
     * @return array<string, string>
     */
    public static function aliases(): array
    {
        if (self::$aliases !== null) {
            return self::$aliases;
        }

        $aliases = [];

        foreach (self::all() as $identifier) {
            $count = IntlTimeZone::countEquivalentIDs($identifier);

            for ($index = 0; $index < $count; $index++) {
                $equivalent = IntlTimeZone::getEquivalentID($identifier, $index);

                // The first listed zone of a group wins, so the answer never depends on iteration luck
                if ($equivalent !== '' && !self::isListed($equivalent) && !isset($aliases[$equivalent])) {
                    $aliases[$equivalent] = $identifier;
                }
            }
        }

        return self::$aliases = $aliases;
    }

    /**
     * @return array<string, true>
     */
    private static function listed(): array
    {
        return self::$listed ??= array_fill_keys(\DateTimeZone::listIdentifiers(), true);
    }
}
