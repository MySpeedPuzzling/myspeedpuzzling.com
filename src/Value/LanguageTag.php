<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use Symfony\Component\Intl\Languages;

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
     * The base language of a tag, lower case: `pt` for `pt-BR`.
     */
    public static function base(string $tag): string
    {
        return strtolower(explode('-', str_replace('_', '-', trim($tag)))[0]);
    }
}
