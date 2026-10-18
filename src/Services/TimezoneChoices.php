<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use Collator;
use IntlTimeZone;
use SpeedPuzzling\Web\Value\TimezoneIdentifier;
use Symfony\Component\Intl\Exception\MissingResourceException;
use Symfony\Component\Intl\Timezones;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The choices of the "Time zone" settings field (docs/features/player-timezone.md): every listed zone, grouped by
 * region, named by ICU in the page's language ("Central European Time (Prague)", "středoevropský čas (Praha)") and
 * sorted by the city in brackets, so a region reads like an atlas index.
 *
 * Built per call - only the edit-profile pages ask, and nothing is kept between requests (worker mode).
 */
readonly final class TimezoneChoices
{
    /**
     * Region → translation key, in the order the groups are shown (the most used first)
     */
    private const array REGIONS = [
        'Europe' => 'player_timezone.region.europe',
        'America' => 'player_timezone.region.america',
        'Asia' => 'player_timezone.region.asia',
        'Africa' => 'player_timezone.region.africa',
        'Australia' => 'player_timezone.region.australia',
        'Pacific' => 'player_timezone.region.pacific',
        'Atlantic' => 'player_timezone.region.atlantic',
        'Indian' => 'player_timezone.region.indian',
        'Antarctica' => 'player_timezone.region.antarctica',
        'Arctic' => 'player_timezone.region.arctic',
    ];

    private const string OTHER_REGION = 'player_timezone.region.other';

    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @return array<string, array<string, string>> group label → (option label → identifier)
     */
    public function grouped(string $locale): array
    {
        /** @var array<string, list<array{label: string, sortKey: string, identifier: string}>> $regions */
        $regions = [];

        foreach (TimezoneIdentifier::all() as $identifier) {
            $region = explode('/', $identifier, 2)[0];
            $groupKey = isset(self::REGIONS[$region]) ? $region : 'other';
            $label = $this->label($identifier, $locale);

            $regions[$groupKey][] = [
                'label' => $label,
                'sortKey' => self::city($label) ?? $label,
                'identifier' => $identifier,
            ];
        }

        $collator = new Collator($locale);
        $choices = [];

        foreach ([...array_keys(self::REGIONS), 'other'] as $groupKey) {
            if (!isset($regions[$groupKey])) {
                continue;
            }

            $zones = $regions[$groupKey];
            usort($zones, static fn (array $a, array $b): int => (int) $collator->compare($a['sortKey'], $b['sortKey']));

            $groupLabel = $this->translator->trans(self::REGIONS[$groupKey] ?? self::OTHER_REGION, locale: $locale);
            $group = [];

            foreach ($zones as $zone) {
                $label = $zone['label'];

                // Two zones ICU names alike stay apart
                if (isset($group[$label])) {
                    $label .= ' - ' . $zone['identifier'];
                }

                $group[$label] = $zone['identifier'];
            }

            $choices[$groupLabel] = $group;
        }

        return $choices;
    }

    public function label(string $identifier, string $locale): string
    {
        try {
            return Timezones::getName($identifier, $locale);
        } catch (MissingResourceException) {
            // UTC has no entry in the Intl data
            $zone = IntlTimeZone::createTimeZone($identifier);
            $name = $zone->getDisplayName(false, IntlTimeZone::DISPLAY_LONG, $locale);

            return $name !== '' ? $name : $identifier;
        }
    }

    /**
     * "Central European Time (Prague)" → "Prague"; Japanese names use full-width brackets
     */
    private static function city(string $label): null|string
    {
        if (preg_match('/[(（]([^()（）]+)[)）]\s*$/u', $label, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }
}
