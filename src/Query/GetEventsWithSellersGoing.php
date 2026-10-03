<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\EventWithSellersGoing;
use SpeedPuzzling\Web\Results\MarketplaceEvent;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * The options of the marketplace's "Pick up at an event" select (docs/features/marketplace/11-events.md):
 * marketplace events (GetMarketplaceEvents::SQL_QUALIFIES) at least one seller with a published listing is going
 * to, nearest first, with how many of those listings are coming (marked for the event) and how many the sellers
 * could be asked to bring.
 *
 * The same for every visitor (aggregates, no blocks applied), so the list is cached for 10 minutes - the marketplace
 * pays one query per 10 minutes, not per page. The key carries the day, so an event leaves the list the day after
 * it ends. Stateless (the pool is the only memory), safe in worker mode.
 *
 * @phpstan-import-type MarketplaceEventDatabaseRow from MarketplaceEvent
 */
readonly final class GetEventsWithSellersGoing
{
    private const int CACHE_TTL = 600;

    public function __construct(
        private Connection $database,
        private GetMarketplaceEvents $getMarketplaceEvents,
        private CacheInterface $marketplaceEventsCache,
    ) {
    }

    /**
     * @return list<EventWithSellersGoing>
     */
    public function all(): array
    {
        $today = $this->getMarketplaceEvents->todayParameter();

        /** @var list<EventWithSellersGoing> $events */
        $events = $this->marketplaceEventsCache->get(
            'events_with_sellers_going_' . $today['marketplace_today'],
            function (ItemInterface $item): array {
                $item->expiresAfter(self::CACHE_TTL);

                return $this->load();
            },
        );

        return $events;
    }

    /**
     * Uncached - all() is what pages use.
     *
     * @return list<EventWithSellersGoing>
     */
    public function load(): array
    {
        $qualifies = GetMarketplaceEvents::SQL_QUALIFIES;

        // Sellers come from the participant list (DISTINCT: a player may hold more than one row of an event),
        // their published listings from the player_id index - never a scan of every listing per event
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
    offers.bringing_count,
    offers.ask_count
FROM competition c
LEFT JOIN competition_series cs ON cs.id = c.series_id
JOIN LATERAL (
    SELECT
        COUNT(*) FILTER (WHERE marked.sell_swap_list_item_id IS NOT NULL) AS bringing_count,
        COUNT(*) FILTER (WHERE marked.sell_swap_list_item_id IS NULL) AS ask_count
    FROM (
        SELECT DISTINCT going.player_id
        FROM competition_participant going
        WHERE going.competition_id = c.id
            AND going.player_id IS NOT NULL
            AND going.deleted_at IS NULL
    ) sellers
    JOIN sell_swap_list_item ssli ON ssli.player_id = sellers.player_id
        AND ssli.published_on_marketplace = true
    LEFT JOIN sell_swap_list_item_event marked ON marked.sell_swap_list_item_id = ssli.id
        AND marked.competition_id = c.id
) offers ON offers.bringing_count + offers.ask_count > 0
WHERE {$qualifies}
ORDER BY c.date_from ASC, c.name ASC, c.id ASC
SQL;

        $rows = $this->database
            ->executeQuery($query, $this->getMarketplaceEvents->todayParameter())
            ->fetchAllAssociative();

        return array_map(static function (array $row): EventWithSellersGoing {
            /** @var MarketplaceEventDatabaseRow $eventRow */
            $eventRow = $row;
            /** @var array{bringing_count: int|string, ask_count: int|string} $counts */
            $counts = $row;

            return new EventWithSellersGoing(
                event: MarketplaceEvent::fromDatabaseRow($eventRow),
                bringingCount: (int) $counts['bringing_count'],
                askCount: (int) $counts['ask_count'],
            );
        }, $rows);
    }
}
