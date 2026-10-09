<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\CompetitionPick;
use SpeedPuzzling\Web\Value\CountryCode;
use SpeedPuzzling\Web\Value\RoundCategory;

/**
 * A publicly visible edition the add-time form lets a player pick explicitly (GetSeriesEditionChoices): found by typing
 * its name (S1) or listed under the series' preview (the short list, S2) - docs/features/events-page/
 * high-frequency-series.md "The form". Logo and location fall back to the series values.
 *
 * The day span is the one of the matching rule (SeriesEditionMatch: dates and round days, NULL for an edition without
 * either). Categories and puzzle names come from its rounds - REVEALED round puzzles only, never one a round still
 * keeps secret; the S1 search leaves both empty.
 *
 * @phpstan-type SeriesEditionChoiceDatabaseRow array{
 *     id: string,
 *     name: string,
 *     series_id: string,
 *     series_name: string,
 *     series_shortcut: null|string,
 *     logo: null|string,
 *     series_logo: null|string,
 *     location: null|string,
 *     location_country_code: null|string,
 *     span_from: null|string,
 *     span_to: null|string,
 *     categories: null|string,
 *     puzzle_names: null|string,
 * }
 */
readonly final class SeriesEditionChoice
{
    /**
     * @param list<RoundCategory> $categories
     * @param list<string> $puzzleNames
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $seriesId,
        public string $seriesName,
        public null|string $seriesShortcut,
        public null|string $logo,
        public null|string $seriesLogo,
        public null|string $location,
        public null|CountryCode $locationCountryCode,
        public null|DateTimeImmutable $dayFrom,
        public null|DateTimeImmutable $dayTo,
        public array $categories = [],
        public array $puzzleNames = [],
    ) {
    }

    /**
     * @param SeriesEditionChoiceDatabaseRow $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        $categories = [];

        foreach (self::jsonList($row['categories']) as $category) {
            $roundCategory = RoundCategory::tryFrom($category);

            if ($roundCategory !== null) {
                $categories[] = $roundCategory;
            }
        }

        // Solo, pairs, teams - in this order whatever the database aggregated
        usort($categories, static fn (RoundCategory $a, RoundCategory $b): int => array_search($a, RoundCategory::cases(), true) <=> array_search($b, RoundCategory::cases(), true));

        return new self(
            id: $row['id'],
            name: $row['name'],
            seriesId: $row['series_id'],
            seriesName: $row['series_name'],
            seriesShortcut: $row['series_shortcut'],
            logo: $row['logo'],
            seriesLogo: $row['series_logo'],
            location: $row['location'],
            locationCountryCode: $row['location_country_code'] !== null ? CountryCode::fromCode($row['location_country_code']) : null,
            dayFrom: $row['span_from'] !== null ? new DateTimeImmutable($row['span_from']) : null,
            dayTo: $row['span_to'] !== null ? new DateTimeImmutable($row['span_to']) : null,
            categories: $categories,
            puzzleNames: self::jsonList($row['puzzle_names']),
        );
    }

    public function pick(): CompetitionPick
    {
        return CompetitionPick::edition($this->id);
    }

    public function isLiveOn(DateTimeImmutable $today): bool
    {
        if ($this->dayFrom === null || $this->dayTo === null) {
            return false;
        }

        $day = $today->format('Y-m-d');

        return $day >= $this->dayFrom->format('Y-m-d') && $day <= $this->dayTo->format('Y-m-d');
    }

    /**
     * @return list<string>
     */
    private static function jsonList(null|string $json): array
    {
        if ($json === null) {
            return [];
        }

        $decoded = json_decode($json, true);

        if (is_array($decoded) === false) {
            return [];
        }

        return array_values(array_filter($decoded, static fn (mixed $value): bool => is_string($value) && $value !== ''));
    }
}
