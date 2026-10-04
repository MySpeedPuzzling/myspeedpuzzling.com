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
     * Label => tag, sorted by label in the page language - the form's `choices`.
     *
     * @param list<null|string> $extraTags Tags to offer besides the list (what a name already has) - anything that is
     *                                     no valid tag is left out
     *
     * @return array<string, string>
     */
    public static function choices(string $locale, array $extraTags = []): array
    {
        $tags = self::LANGUAGES;

        foreach ($extraTags as $extraTag) {
            if ($extraTag !== null && LanguageTag::normalize($extraTag) === $extraTag && in_array($extraTag, $tags, true) === false) {
                $tags[] = $extraTag;
            }
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
}
