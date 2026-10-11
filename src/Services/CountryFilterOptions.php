<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use SpeedPuzzling\Web\Value\CountryCode;

/**
 * The choices of a country filter select: countries by their name in the page's language (never the ISO code),
 * sorted by that name.
 */
final class CountryFilterOptions
{
    /**
     * @param list<string> $codes lower-case ISO codes as stored on player.country
     * @return list<array{code: string, name: string}>
     */
    public static function fromCodes(array $codes, string $locale): array
    {
        $options = [];

        foreach ($codes as $code) {
            $country = CountryCode::fromCode($code);

            if ($country === null) {
                continue;
            }

            $options[] = ['code' => $country->name, 'name' => $country->localizedName($locale)];
        }

        $collator = class_exists(\Collator::class) ? new \Collator($locale) : null;
        usort($options, static fn (array $a, array $b): int => $collator !== null
            ? (int) $collator->compare($a['name'], $b['name'])
            : strcmp($a['name'], $b['name']));

        return $options;
    }
}
