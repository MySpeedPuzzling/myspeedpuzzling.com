<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * The language a player's country reads its puzzle boxes in - the second name line a signed-in player gets on English
 * pages (PuzzleNameLanguage, docs/features/puzzle-names/README.md "Display").
 *
 * Only countries where one language clearly dominates. English-speaking countries have none (the main title is the
 * English one already), and neither have countries of several languages (Switzerland, Belgium, Luxembourg, Canada...):
 * guessing would show a name the player may not read.
 */
readonly final class CountryLanguage
{
    /**
     * ISO 3166-1 alpha-2 in lower case, as `player.country` stores it => base language (BCP 47)
     */
    private const array LANGUAGES = [
        'cz' => 'cs',
        'sk' => 'sk',
        'de' => 'de',
        'at' => 'de',
        'li' => 'de',
        'fr' => 'fr',
        'mc' => 'fr',
        'es' => 'es',
        'mx' => 'es',
        'ar' => 'es',
        'co' => 'es',
        'cl' => 'es',
        'pe' => 'es',
        'uy' => 'es',
        've' => 'es',
        'ec' => 'es',
        'bo' => 'es',
        'cr' => 'es',
        'gt' => 'es',
        'hn' => 'es',
        'ni' => 'es',
        'pa' => 'es',
        'sv' => 'es',
        'do' => 'es',
        'cu' => 'es',
        'it' => 'it',
        'sm' => 'it',
        'va' => 'it',
        'pl' => 'pl',
        'nl' => 'nl',
        'pt' => 'pt',
        'br' => 'pt',
        'ao' => 'pt',
        'mz' => 'pt',
        'jp' => 'ja',
        'hu' => 'hu',
        'ro' => 'ro',
        'md' => 'ro',
        'se' => 'sv',
        'no' => 'nb',
        'dk' => 'da',
        'fi' => 'fi',
        'ee' => 'et',
        'lv' => 'lv',
        'lt' => 'lt',
        'si' => 'sl',
        'hr' => 'hr',
        'rs' => 'sr',
        'ba' => 'bs',
        'mk' => 'mk',
        'al' => 'sq',
        'bg' => 'bg',
        'gr' => 'el',
        'cy' => 'el',
        'tr' => 'tr',
        'ru' => 'ru',
        'ua' => 'uk',
        'by' => 'be',
        'kr' => 'ko',
        'cn' => 'zh',
        'tw' => 'zh',
        'hk' => 'zh',
        'mo' => 'zh',
        'il' => 'he',
        'ir' => 'fa',
        'sa' => 'ar',
        'eg' => 'ar',
        'ae' => 'ar',
        'jo' => 'ar',
        'kw' => 'ar',
        'qa' => 'ar',
        'bh' => 'ar',
        'om' => 'ar',
        'th' => 'th',
        'vn' => 'vi',
        'id' => 'id',
        'my' => 'ms',
        'is' => 'is',
    ];

    public static function of(null|string $countryCode): null|string
    {
        if ($countryCode === null) {
            return null;
        }

        return self::LANGUAGES[strtolower(trim($countryCode))] ?? null;
    }
}
