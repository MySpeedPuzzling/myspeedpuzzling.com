<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\PartnerKind;
use SpeedPuzzling\Web\Value\PartnerStrength;
use SpeedPuzzling\Web\Value\SearchText;

/**
 * The partner finder page of one event for a signed-in viewer (GetPartnerListings::forEvent()): what the event offers and
 * the open listings the viewer may see - the own one first, then the newest, at most GetPartnerListings::LIMIT. The
 * filter bar's counts and chips are computed here from the loaded rows - no statement of their own.
 */
readonly final class PartnerListings
{
    // A custom "Good at" chip joins the filter row once this many listings use it (D3)
    public const int CUSTOM_FILTER_MIN_LISTINGS = 2;

    /**
     * @param list<PartnerListingRow> $rows
     */
    public function __construct(
        public PartnerFinderOffer $offer,
        public bool $registrationManaged,
        public null|int $viewerMedian500Seconds,
        public array $rows,
        public int $totalOpen,
        // Listings closed with "Found my partner" at the event (D7) - social proof on the page
        public int $foundCount = 0,
    ) {
    }

    public function isCapped(): bool
    {
        return $this->totalOpen > count($this->rows);
    }

    public function ownRow(): null|PartnerListingRow
    {
        foreach ($this->rows as $row) {
            if ($row->own) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Everybody else's listings - what the filters work on (the own listing stands above them).
     *
     * @return list<PartnerListingRow>
     */
    public function others(): array
    {
        return array_values(array_filter($this->rows, static fn (PartnerListingRow $row): bool => $row->own === false));
    }

    /**
     * @return array{all: int, pair: int, team: int, group: int}
     */
    public function kindCounts(): array
    {
        $others = $this->others();
        $counts = ['all' => count($others), 'pair' => 0, 'team' => 0, 'group' => 0];

        foreach ($others as $row) {
            foreach ($row->kindKeys() as $key) {
                if (isset($counts[$key]) && $key !== 'all') {
                    $counts[$key]++;
                }
            }
        }

        return $counts;
    }

    /**
     * Country code => everybody else's listings, most first (ties by code).
     *
     * @return array<string, int>
     */
    public function countryCounts(): array
    {
        $counts = [];

        foreach ($this->others() as $row) {
            if ($row->countryCode !== null && $row->countryCode !== '') {
                $code = strtolower($row->countryCode);
                $counts[$code] = ($counts[$code] ?? 0) + 1;
            }
        }

        uksort($counts, static fn (string $a, string $b): int => [$counts[$b], $a] <=> [$counts[$a], $b]);

        return $counts;
    }

    /**
     * The "Good at" filter chips: every preset at least one listing has (in PartnerStrength order), then the custom chips
     * CUSTOM_FILTER_MIN_LISTINGS listings or more use, compared folded (the first spelling shown, never translated).
     *
     * @return list<array{key: string, preset: null|PartnerStrength, label: null|string, count: int}>
     */
    public function strengthFilters(): array
    {
        $presetCounts = [];
        /** @var array<string, array{label: string, count: int}> $custom */
        $custom = [];

        foreach ($this->others() as $row) {
            foreach ($row->strengths as $strength) {
                $presetCounts[$strength->value] = ($presetCounts[$strength->value] ?? 0) + 1;
            }

            $seen = [];

            foreach ($row->customStrengths as $chip) {
                $folded = SearchText::fold($chip);

                if ($folded === '' || isset($seen[$folded])) {
                    continue;
                }

                $seen[$folded] = true;
                $custom[$folded] ??= ['label' => $chip, 'count' => 0];
                $custom[$folded]['count']++;
            }
        }

        $filters = [];

        foreach (PartnerStrength::cases() as $strength) {
            if (isset($presetCounts[$strength->value])) {
                $filters[] = ['key' => $strength->value, 'preset' => $strength, 'label' => null, 'count' => $presetCounts[$strength->value]];
            }
        }

        foreach ($custom as $chip) {
            if ($chip['count'] >= self::CUSTOM_FILTER_MIN_LISTINGS) {
                $filters[] = ['key' => PartnerListingRow::customKey($chip['label']), 'preset' => null, 'label' => $chip['label'], 'count' => $chip['count']];
            }
        }

        return $filters;
    }

    /**
     * The custom chips used at the event (any count) - suggestions for the form's "your own" input.
     *
     * @return list<string>
     */
    public function customChipSuggestions(): array
    {
        $chips = [];

        foreach ($this->rows as $row) {
            foreach ($row->customStrengths as $chip) {
                $chips[SearchText::fold($chip)] ??= $chip;
            }
        }

        return array_values($chips);
    }

    public function offers(PartnerKind $kind): bool
    {
        return $this->offer->offers($kind);
    }
}
