<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use Symfony\Component\Intl\Countries;
use Symfony\Component\Intl\Languages;
use Symfony\Component\Intl\Locales;
use Symfony\Component\Intl\Scripts;

/**
 * The language of a puzzle name as a BCP 47 tag: `cs`, `de`, `pt-BR`, `zh-Hant`.
 */
readonly final class LanguageTag
{
    // puzzle.name_language is varchar(16)
    private const int MAX_LENGTH = 16;

    /**
     * The tag in its canonical casing (lower base language, Title script, upper region, lower variants) - null when
     * it is no tag or its base language is unknown to Symfony Intl.
     */
    public static function normalize(string $tag): null|string
    {
        $tag = str_replace('_', '-', trim($tag));

        if (preg_match('/^[a-z]{2,3}(-[a-z\d]{1,8})*$/i', $tag) !== 1 || strlen($tag) > self::MAX_LENGTH) {
            return null;
        }

        $subtags = explode('-', $tag);
        $base = strtolower(array_shift($subtags));

        if (Languages::exists($base) === false) {
            return null;
        }

        $normalized = [$base];

        foreach ($subtags as $subtag) {
            $normalized[] = match (true) {
                preg_match('/^[a-z]{4}$/i', $subtag) === 1 => ucfirst(strtolower($subtag)),
                preg_match('/^([a-z]{2}|\d{3})$/i', $subtag) === 1 => strtoupper($subtag),
                default => strtolower($subtag),
            };
        }

        return implode('-', $normalized);
    }

    /**
     * The base language of a tag, lower case: `pt` for `pt-BR`. Norwegian `no` (the macrolanguage) is Bokmål `nb` -
     * what Norway's boxes print and the language of a player from Norway (CountryLanguage), so a name tagged either
     * way is shown to them. The tag itself stays as it was given.
     */
    public static function base(string $tag): string
    {
        $base = strtolower(explode('-', str_replace('_', '-', trim($tag)))[0]);

        return $base === 'no' ? 'nb' : $base;
    }

    /**
     * The tag's language named in another language by Symfony Intl: "Czech", "čeština" in Czech, "Portuguese (Brazil)"
     * for `pt-BR`. A tag Intl knows no name for as a whole gets its base language and the rest in brackets.
     */
    public static function displayName(string $tag, string $displayLocale): string
    {
        $subtags = explode('-', str_replace('_', '-', trim($tag)));
        $base = strtolower(array_shift($subtags));
        $locale = implode('_', [$base, ...$subtags]);

        if ($subtags !== [] && Locales::exists($locale)) {
            return Locales::getName($locale, $displayLocale);
        }

        $name = Languages::exists($base) ? Languages::getName($base, $displayLocale) : $base;
        $details = [];

        foreach ($subtags as $subtag) {
            $details[] = match (true) {
                strlen($subtag) === 4 && Scripts::exists(ucfirst(strtolower($subtag))) => Scripts::getName(ucfirst(strtolower($subtag)), $displayLocale),
                strlen($subtag) === 2 && Countries::exists(strtoupper($subtag)) => Countries::getName(strtoupper($subtag), $displayLocale),
                default => $subtag,
            };
        }

        return $details === [] ? $name : sprintf('%s (%s)', $name, implode(', ', $details));
    }
}
