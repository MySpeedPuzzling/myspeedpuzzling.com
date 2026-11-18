<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * The language of a claim invitation e-mail (docs/features/competitions-management/participant-linking.md §9.1): the
 * organiser gives the person's country, not their language - the site locale that fits the country, else English.
 * Czechia and Slovakia → cs, the German-speaking countries → de, Spain and Spanish-speaking America → es, France and its
 * French-speaking neighbours → fr, Japan → ja.
 */
final class ClaimInvitationLocale
{
    private const array BY_COUNTRY = [
        'cz' => 'cs', 'sk' => 'cs',
        'de' => 'de', 'at' => 'de', 'li' => 'de',
        'es' => 'es', 'mx' => 'es', 'ar' => 'es', 'co' => 'es', 'cl' => 'es', 'pe' => 'es', 've' => 'es', 'ec' => 'es',
        'gt' => 'es', 'cu' => 'es', 'bo' => 'es', 'do' => 'es', 'hn' => 'es', 'py' => 'es', 'sv' => 'es', 'ni' => 'es',
        'cr' => 'es', 'pa' => 'es', 'uy' => 'es', 'pr' => 'es',
        'fr' => 'fr', 'be' => 'fr', 'lu' => 'fr', 'mc' => 'fr',
        'jp' => 'ja',
    ];

    public static function forCountry(null|string $country): string
    {
        return self::BY_COUNTRY[strtolower((string) $country)] ?? 'en';
    }
}
