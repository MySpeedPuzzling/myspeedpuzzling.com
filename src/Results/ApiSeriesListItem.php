<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\CountryCode;

/**
 * A publicly visible series as API v1 lists it (GET /api/v1/series, GetApiSeriesList) - what a client needs to link a
 * solving time to it (`series_id`) and to show it.
 */
readonly final class ApiSeriesListItem
{
    public null|string $link;

    public function __construct(
        public string $id,
        public string $name,
        public null|string $shortcut,
        public null|string $slug,
        public null|string $logo,
        public bool $isOnline,
        public null|string $location,
        public null|CountryCode $countryCode,
        null|string $link,
        // Only a publicly visible organization is named
        public null|string $organizationName,
        // Its publicly visible editions, undated ones included
        public int $editionsCount,
        // The first day of the soonest edition starting after today / of the latest edition that is over (`Y-m-d`) -
        // an edition held today is in neither
        public null|string $nextDate,
        public null|string $lastDate,
    ) {
        $this->link = $link !== null
            ? $link . (str_contains($link, '?') ? '&' : '?') . 'utm_source=myspeedpuzzling'
            : null;
    }

    /**
     * @param array{
     *     id: string,
     *     name: string,
     *     shortcut: null|string,
     *     slug: null|string,
     *     logo: null|string,
     *     is_online: bool,
     *     location: null|string,
     *     location_country_code: null|string,
     *     link: null|string,
     *     organization_name: null|string,
     *     editions_count: int,
     *     next_date: null|string,
     *     last_date: null|string,
     * } $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            id: $row['id'],
            name: $row['name'],
            shortcut: $row['shortcut'],
            slug: $row['slug'],
            logo: $row['logo'],
            isOnline: $row['is_online'],
            location: $row['location'],
            countryCode: CountryCode::fromCode($row['location_country_code']),
            link: $row['link'],
            organizationName: $row['organization_name'],
            editionsCount: $row['editions_count'],
            nextDate: $row['next_date'],
            lastDate: $row['last_date'],
        );
    }
}
