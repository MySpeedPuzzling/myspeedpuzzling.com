<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\PlayerIdentification;

readonly final class GetConversationPartnersForListing
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return array<PlayerIdentification>
     */
    public function forListingAndSeller(string $sellSwapListItemId, string $sellerId): array
    {
        $query = <<<SQL
SELECT DISTINCT
    CASE WHEN c.initiator_id = :sellerId THEN c.recipient_id ELSE c.initiator_id END AS player_id,
    CASE WHEN c.initiator_id = :sellerId THEN rp.name ELSE ip.name END AS player_name,
    CASE WHEN c.initiator_id = :sellerId THEN rp.code ELSE ip.code END AS player_code,
    CASE WHEN c.initiator_id = :sellerId THEN rp.country ELSE ip.country END AS player_country
FROM conversation c
JOIN player ip ON c.initiator_id = ip.id
JOIN player rp ON c.recipient_id = rp.id
WHERE c.sell_swap_list_item_id = :listItemId
    AND c.status IN ('pending', 'accepted', 'ignored')
SQL;

        $data = $this->database
            ->executeQuery($query, [
                'sellerId' => $sellerId,
                'listItemId' => $sellSwapListItemId,
            ])
            ->fetchAllAssociative();

        return array_map(static function (array $row): PlayerIdentification {
            /**
             * @var array{
             *     player_id: string,
             *     player_code: string,
             *     player_name: null|string,
             *     player_country: null|string,
             * } $row
             */

            return PlayerIdentification::fromDatabaseRow($row);
        }, $data);
    }
}
