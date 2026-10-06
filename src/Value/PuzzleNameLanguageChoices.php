<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use Collator;
use Symfony\Component\Intl\Languages;
use Symfony\Component\Intl\Locales;

/**
 * The languages a puzzle name can be tagged with in the forms (docs/features/puzzle-names/README.md) - the languages
 * puzzle boxes are printed in, named in the page language and sorted the way it sorts. A stored tag outside the list
 * (`pt-BR` from the API or the migration) is offered for the name that has it, so saving a form never drops it.
 */
readonly final class PuzzleNameLanguageChoices
{
    /**
     * Base languages, BCP 47 - the order does not matter, the choices are sorted by their names
     */
    public const array LANGUAGES = [
        'cs', 'sk', 'de', 'fr', 'es', 'it', 'nl', 'pl', 'pt', 'hu', 'ro', 'sv', 'nb', 'da', 'fi', 'et', 'lv', 'lt',
        'sl', 'hr', 'sr', 'bg', 'el', 'tr', 'ru', 'uk', 'ja', 'zh', 'ko', 'he', 'ar', 'en',
    ];

    /**
     * Base language => flag-icons code, where the two differ or the language is spoken in many countries
     */
    private const array FLAGS = [
        'cs' => 'cz', 'sk' => 'sk', 'de' => 'de', 'fr' => 'fr', 'es' => 'es', 'it' => 'it', 'nl' => 'nl', 'pl' => 'pl',
        'pt' => 'pt', 'hu' => 'hu', 'ro' => 'ro', 'sv' => 'se', 'nb' => 'no', 'nn' => 'no', 'da' => 'dk', 'fi' => 'fi',
        'et' => 'ee', 'lv' => 'lv', 'lt' => 'lt', 'sl' => 'si', 'hr' => 'hr', 'sr' => 'rs', 'bg' => 'bg', 'el' => 'gr',
        'tr' => 'tr', 'ru' => 'ru', 'uk' => 'ua', 'ja' => 'jp', 'zh' => 'cn', 'ko' => 'kr', 'he' => 'il', 'ar' => 'arab',
        'en' => 'gb',
    ];

    /**
     * Label => tag, sorted by label in the page language - the form's `choices`.
     *
     * @param list<null|string> $extraTags Tags to offer besides the list (what a name already has) - anything that is
     *                                     no valid tag is left out
     * @param bool $english False for the main title's language: null stands for English there, never a tag
     *
     * @return array<string, string>
     */
    public static function choices(string $locale, array $extraTags = [], bool $english = true): array
    {
        $tags = self::LANGUAGES;

        foreach ($extraTags as $extraTag) {
            if ($extraTag !== null && LanguageTag::normalize($extraTag) === $extraTag && in_array($extraTag, $tags, true) === false) {
                $tags[] = $extraTag;
            }
        }

        if ($english === false) {
            $tags = array_filter($tags, static fn (string $tag): bool => LanguageTag::base($tag) !== 'en');
        }

        $choices = [];

        foreach ($tags as $tag) {
            $label = self::label($tag, $locale);
            // Two tags named alike must not swallow each other as label keys
            $choices[array_key_exists($label, $choices) ? $label . ' (' . $tag . ')' : $label] = $tag;
        }

        $collator = new Collator($locale);
        uksort($choices, static fn (string $a, string $b): int => (int) $collator->compare($a, $b));

        return $choices;
    }

    /**
     * The language a tag stands for, in the page language and starting with a capital letter: "Czech", "Čeština",
     * "Portuguese (Brazil)". A tag Symfony Intl does not know is shown as it is.
     */
    public static function label(string $tag, string $locale): string
    {
        $locale = LanguageTag::base($locale);
        $intlTag = str_replace('-', '_', $tag);

        if (Languages::exists($intlTag)) {
            $label = Languages::getName($intlTag, $locale);
        } elseif (Locales::exists($intlTag)) {
            $label = Locales::getName($intlTag, $locale);
        } else {
            $base = LanguageTag::base($tag);
            $label = Languages::exists($base) ? Languages::getName($base, $locale) . ' (' . $tag . ')' : $tag;
        }

        return mb_strtoupper(mb_substr($label, 0, 1)) . mb_substr($label, 1);
    }

    /**
     * The flag shown next to a language in the pickers - a flag-icons code: the tag's region when it has one
     * (`pt-BR` → br), else the country the language's boxes come from. Null for a language without one.
     */
    public static function flag(string $tag): null|string
    {
        foreach (array_slice(explode('-', $tag), 1) as $subtag) {
            if (preg_match('/^[A-Za-z]{2}$/', $subtag) === 1) {
                return strtolower($subtag);
            }
        }

        return self::FLAGS[LanguageTag::base($tag)] ?? null;
    }

    /**
     * The attributes of the language's <option> read by language_select_controller.js: its flag, and its English name
     * and tag to search by ("German" or "de" finds "Němčina" on a Czech page).
     *
     * @return array<string, string>
     */
    public static function optionAttributes(string $tag): array
    {
        $flag = self::flag($tag);

        return ($flag !== null ? ['data-flag' => $flag] : []) + ['data-alias' => self::label($tag, 'en') . ' ' . $tag];
    }

    /**
     * The language a name added in a form starts in: the page language, none on English pages (nor for a page
     * language outside the list).
     */
    public static function forNewName(string $locale): null|string
    {
        $language = LanguageTag::base($locale);

        return $language !== 'en' && in_array($language, self::LANGUAGES, true) ? $language : null;
    }
}
