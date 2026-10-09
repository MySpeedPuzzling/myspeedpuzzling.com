<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\CompetitionPick;
use SpeedPuzzling\Web\Value\CountryCode;

/**
 * One entry of the "Competition / event" picker on the add-time and edit-time forms
 * (docs/features/events-page/high-frequency-series.md "The default list"):
 *
 * - kind 'event': a one-time event (series columns empty);
 * - kind 'series': a series - MySpeedPuzzling finds the edition (id = series id, seriesId = the same, nextDay /
 *   lastDay / editionCount from its publicly visible editions, organization names for the search);
 * - kind 'edition': an edition offered only as the current pick (edit form, deep link) or a refused submit's own
 *   choice - never part of the default list. Logo and location already fall back to the series values.
 *
 * @phpstan-type SelectableCompetitionDatabaseRow array{
 *     kind: string,
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
 *     next_day: null|string,
 *     last_day: null|string,
 *     edition_count: null|int|string,
 *     organization_name: null|string,
 *     organization_short_name: null|string,
 * }
 */
readonly final class SelectableCompetition
{
    public const string KIND_EVENT = 'event';
    public const string KIND_SERIES = 'series';
    public const string KIND_EDITION = 'edition';

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
        /** One of 'live', 'undated', 'past', 'upcoming' - a series: live while an edition is, else by its editions */
        public string $eventStatus,
        /** One of the KIND_ constants */
        public string $kind = self::KIND_EVENT,
        /** A series: the first day of its soonest edition after today */
        public null|DateTimeImmutable $nextDay = null,
        /** A series: the first day of its latest edition that is over */
        public null|DateTimeImmutable $lastDay = null,
        /** A series: its publicly visible editions, undated ones too */
        public int $editionCount = 0,
        /** A series: its organization's names, only when the organization is publicly visible */
        public null|string $organizationName = null,
        public null|string $organizationShortName = null,
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
            kind: $row['kind'],
            nextDay: $row['next_day'] !== null ? new DateTimeImmutable($row['next_day']) : null,
            lastDay: $row['last_day'] !== null ? new DateTimeImmutable($row['last_day']) : null,
            editionCount: (int) ($row['edition_count'] ?? 0),
            organizationName: $row['organization_name'],
            organizationShortName: $row['organization_short_name'],
        );
    }

    /**
     * The field value this entry stands for (CompetitionPick)
     */
    public function pick(): CompetitionPick
    {
        return match ($this->kind) {
            self::KIND_SERIES => CompetitionPick::series($this->id),
            self::KIND_EDITION => CompetitionPick::edition($this->id),
            default => CompetitionPick::event($this->id),
        };
    }
}
