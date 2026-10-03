<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\MarketplaceEvent;
use SpeedPuzzling\Web\Results\MarketplaceEventsHintState;

/**
 * The "Marketplace at events" banner's one statement (docs/features/marketplace/11-events.md): does the player have
 * a published listing, which marketplace event they go to is the nearest, how many listings they mark for it and how
 * many the other going sellers are bringing there (an aggregate - nobody's blocks apply).
 *
 * @phpstan-import-type MarketplaceEventDatabaseRow from MarketplaceEvent
 */
readonly final class GetMarketplaceEventsHintState
{
    public function __construct(
        private Connection $database,
        private GetMarketplaceEvents $getMarketplaceEvents,
    ) {
    }

    public function forPlayer(string $playerId): MarketplaceEventsHintState
    {
        $qualifies = GetMarketplaceEvents::SQL_QUALIFIES;
        $playerGoing = GetMarketplaceEvents::sqlPlayerGoing('c.id', ':playerId');
        $sellerGoing = GetMarketplaceEvents::sqlPlayerGoing('nearest.id', 'other.player_id');

        $query = <<<SQL
SELECT
    EXISTS (
        SELECT 1
        FROM sell_swap_list_item own
        WHERE own.player_id = :playerId
            AND own.published_on_marketplace = true
    ) AS has_published_listing,
    nearest.*,
    (
        SELECT COUNT(*)
        FROM sell_swap_list_item_event marked
        JOIN sell_swap_list_item own ON own.id = marked.sell_swap_list_item_id
        WHERE marked.competition_id = nearest.id
            AND own.player_id = :playerId
            AND own.published_on_marketplace = true
    ) AS marked_count,
    (
        SELECT COUNT(*)
        FROM sell_swap_list_item_event marked
        JOIN sell_swap_list_item other ON other.id = marked.sell_swap_list_item_id
        WHERE marked.competition_id = nearest.id
            AND other.player_id <> :playerId
            AND other.published_on_marketplace = true
            AND {$sellerGoing}
    ) AS brought_by_others
FROM (SELECT 1) AS one
LEFT JOIN LATERAL (
    SELECT c.id,
        c.name,
        c.shortcut,
        c.slug,
        c.date_from,
        c.date_to,
        COALESCE(c.location, cs.location) AS location,
        COALESCE(c.location_country_code, cs.location_country_code) AS location_country_code,
        cs.name AS series_name,
        cs.slug AS series_slug
    FROM competition c
    LEFT JOIN competition_series cs ON cs.id = c.series_id
    WHERE {$qualifies}
        AND {$playerGoing}
    ORDER BY c.date_from ASC, c.name ASC, c.id ASC
    LIMIT 1
) nearest ON true
SQL;

        /** @var array{has_published_listing: bool, id: null|string, marked_count: int|string, brought_by_others: int|string} $row */
        $row = $this->database
            ->executeQuery($query, [
                'playerId' => $playerId,
                ...$this->getMarketplaceEvents->todayParameter(),
            ])
            ->fetchAssociative();

        $nearestEvent = null;

        if ($row['id'] !== null) {
            $eventRow = $row;
            /** @var MarketplaceEventDatabaseRow $eventRow */
            $nearestEvent = MarketplaceEvent::fromDatabaseRow($eventRow);
        }

        return new MarketplaceEventsHintState(
            hasPublishedListing: (bool) $row['has_published_listing'],
            nearestEvent: $nearestEvent,
            markedForNearestEvent: (int) $row['marked_count'],
            broughtByOthers: (int) $row['brought_by_others'],
        );
    }
}
