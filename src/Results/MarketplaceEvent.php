<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\CountryCode;

/**
 * An event the marketplace works with: in person, publicly visible, dated and not over
 * (GetMarketplaceEvents::SQL_QUALIFIES). A standalone event or an edition of a series - link it through
 * `reference` (routeName()/routeParameters()), name it in prose with `reference->displayName()`, and use
 * `shortName` where space is tight (labels, selects).
 *
 * @phpstan-type MarketplaceEventDatabaseRow array{
 *     id: string,
 *     name: string,
 *     shortcut: null|string,
 *     slug: null|string,
 *     date_from: string,
 *     date_to: null|string,
 *     location: null|string,
 *     location_country_code: null|string,
 *     series_name: null|string,
 *     series_slug: null|string,
 * }
 */
readonly final class MarketplaceEvent
{
    public function __construct(
        public string $competitionId,
        public string $name,
        public string $shortName,
        public DateTimeImmutable $dateFrom,
        public null|DateTimeImmutable $dateTo,
        public null|string $location,
        public null|CountryCode $countryCode,
        public CompetitionReference $reference,
    ) {
    }

    /**
     * @param MarketplaceEventDatabaseRow $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            competitionId: $row['id'],
            name: $row['name'],
            shortName: $row['shortcut'] !== null && $row['shortcut'] !== '' ? $row['shortcut'] : $row['name'],
            dateFrom: new DateTimeImmutable($row['date_from']),
            dateTo: $row['date_to'] !== null ? new DateTimeImmutable($row['date_to']) : null,
            location: $row['location'],
            countryCode: CountryCode::fromCode($row['location_country_code']),
            reference: new CompetitionReference(
                name: $row['name'],
                slug: $row['slug'],
                seriesName: $row['series_name'],
                seriesSlug: $row['series_slug'],
            ),
        );
    }
}
