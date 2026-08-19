<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\CountryCode;

/**
 * One entry of the "Competition / event" picker on the add-time and edit-time forms: a standalone
 * competition or an edition of a series (then the series columns are filled). Logo and location
 * already fall back to the series values when the edition has none of its own.
 *
 * @phpstan-type SelectableCompetitionDatabaseRow array{
 *     id: string,
 *     name: string,
 *     shortcut: null|string,
 *     logo: null|string,
 *     series_logo: null|string,
 *     location: null|string,
 *     location_country_code: null|string,
 *     date_from: null|string,
 *     date_to: null|string,
 *     is_online: bool|string,
 *     series_id: null|string,
 *     series_name: null|string,
 *     series_shortcut: null|string,
 *     event_status: string,
 * }
 */
readonly final class SelectableCompetition
{
    public function __construct(
        public string $id,
        public string $name,
        public null|string $shortcut,
        public null|string $logo,
        public null|string $location,
        public null|CountryCode $locationCountryCode,
        public null|DateTimeImmutable $dateFrom,
        public null|DateTimeImmutable $dateTo,
        public bool $isOnline,
        public null|string $seriesId,
        public null|string $seriesName,
        public null|string $seriesShortcut,
        public null|string $seriesLogo,
        /** One of 'live', 'undated', 'past', 'upcoming' */
        public string $eventStatus,
    ) {
    }

    /**
     * @param SelectableCompetitionDatabaseRow $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        $isOnline = $row['is_online'];
        if (is_string($isOnline)) {
            $isOnline = $isOnline === 't' || $isOnline === '1' || $isOnline === 'true';
        }

        return new self(
            id: $row['id'],
            name: $row['name'],
            shortcut: $row['shortcut'],
            logo: $row['logo'],
            location: $row['location'],
            locationCountryCode: $row['location_country_code'] !== null ? CountryCode::fromCode($row['location_country_code']) : null,
            dateFrom: $row['date_from'] !== null ? new DateTimeImmutable($row['date_from']) : null,
            dateTo: $row['date_to'] !== null ? new DateTimeImmutable($row['date_to']) : null,
            isOnline: $isOnline,
            seriesId: $row['series_id'],
            seriesName: $row['series_name'],
            seriesShortcut: $row['series_shortcut'],
            seriesLogo: $row['series_logo'],
            eventStatus: $row['event_status'],
        );
    }
}
