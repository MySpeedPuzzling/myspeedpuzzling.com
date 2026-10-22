<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\MarketplaceEvent;
use SpeedPuzzling\Web\Results\PartnerFinderEvent;
use SpeedPuzzling\Web\Results\PartnerFinderGate;

/**
 * Every event that has a partner finder right now (PartnerFinderEligibility::SQL_QUALIFIES: one-time events and series
 * editions, in person, publicly visible - drafts never -, dated, not over, offering a pair or a team) with its open
 * listings counted, nearest first - ONE statement for the whole gate page (docs/features/partner-finder.md "Part D as
 * built"). Only events with a page: a one-time event needs its slug, an edition its own and its series' slug.
 * The counts are aggregates over open listings (`custom_partner_listing_open_by_event`), nobody's identity.
 *
 * @phpstan-import-type MarketplaceEventDatabaseRow from MarketplaceEvent
 */
readonly final class GetPartnerFinderEvents
{
    public function __construct(
        private Connection $database,
        private PartnerFinderEligibility $eligibility,
    ) {
    }

    public function all(): PartnerFinderGate
    {
        $qualifies = PartnerFinderEligibility::SQL_QUALIFIES;

        $query = <<<SQL
SELECT c.id,
    c.name,
    c.shortcut,
    c.slug,
    c.date_from,
    c.date_to,
    COALESCE(c.location, cs.location) AS location,
    COALESCE(c.location_country_code, cs.location_country_code) AS location_country_code,
    cs.name AS series_name,
    cs.slug AS series_slug,
    (
        SELECT COUNT(*)
        FROM partner_listing pl
        WHERE pl.competition_id = c.id
            AND pl.closed_at IS NULL
    ) AS listing_count
FROM competition c
LEFT JOIN competition_series cs ON cs.id = c.series_id
WHERE {$qualifies}
    AND c.slug IS NOT NULL
    AND (c.series_id IS NULL OR cs.slug IS NOT NULL)
ORDER BY c.date_from ASC, c.name ASC, c.id ASC
SQL;

        $rows = $this->database
            ->executeQuery($query, $this->eligibility->todayParameter())
            ->fetchAllAssociative();

        return new PartnerFinderGate(array_map(static function (array $row): PartnerFinderEvent {
            /** @var int|string $count */
            $count = $row['listing_count'];
            unset($row['listing_count']);

            /** @var MarketplaceEventDatabaseRow $row */
            return new PartnerFinderEvent(
                event: MarketplaceEvent::fromDatabaseRow($row),
                listingCount: (int) $count,
            );
        }, $rows));
    }
}
