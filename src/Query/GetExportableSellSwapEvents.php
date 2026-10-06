<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\Export\ExportableSellSwapEvent;

/**
 * Every "bringing it to this event" mark on the player's listings, including the ones the site keeps but no longer
 * shows; currently_shown is the marketplace label rule (GetMarketplaceListings::labels()) - docs/features/data-export.md.
 */
readonly final class GetExportableSellSwapEvents
{
    public function __construct(
        private Connection $database,
        private GetMarketplaceEvents $getMarketplaceEvents,
    ) {
    }

    /**
     * @return list<ExportableSellSwapEvent>
     */
    public function byPlayerId(string $playerId): array
    {
        $qualifies = GetMarketplaceEvents::SQL_QUALIFIES;
        $sellerGoing = GetMarketplaceEvents::sqlPlayerGoing('c.id', 'ssli.player_id');

        $query = <<<SQL
SELECT
    ssli.id AS sell_swap_item_id,
    p.id AS puzzle_id,
    p.name AS puzzle_name,
    c.id AS event_id,
    c.name AS event_name,
    cs.name AS event_series_name,
    c.date_from AS event_date_from,
    c.date_to AS event_date_to,
    marked.added_at,
    ({$qualifies} AND {$sellerGoing}) AS currently_shown
FROM sell_swap_list_item_event marked
INNER JOIN sell_swap_list_item ssli ON ssli.id = marked.sell_swap_list_item_id
INNER JOIN puzzle p ON p.id = ssli.puzzle_id
INNER JOIN competition c ON c.id = marked.competition_id
LEFT JOIN competition_series cs ON cs.id = c.series_id
WHERE ssli.player_id = :playerId
ORDER BY c.date_from DESC NULLS LAST, c.id, marked.added_at, ssli.id
SQL;

        /**
         * @var list<array{
         *     sell_swap_item_id: string,
         *     puzzle_id: string,
         *     puzzle_name: string,
         *     event_id: string,
         *     event_name: string,
         *     event_series_name: null|string,
         *     event_date_from: null|string,
         *     event_date_to: null|string,
         *     added_at: string,
         *     currently_shown: bool,
         * }> $rows
         */
        $rows = $this->database
            ->executeQuery($query, [
                'playerId' => $playerId,
                ...$this->getMarketplaceEvents->todayParameter(),
            ])
            ->fetchAllAssociative();

        return array_map(ExportableSellSwapEvent::fromDatabaseRow(...), $rows);
    }
}
