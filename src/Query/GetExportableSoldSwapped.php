<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Results\Export\ExportableSoldSwappedItem;
use SpeedPuzzling\Web\Results\Export\ExportablePuzzle;

/**
 * Everything the player sold or swapped away, with the buyer as the sold history shows them (docs/features/data-export.md).
 */
readonly final class GetExportableSoldSwapped
{
    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
        private string $uploadedAssetsBaseUrl,
    ) {
    }

    /**
     * @return list<ExportableSoldSwappedItem>
     */
    public function byPlayerId(string $playerId): array
    {
        $puzzleColumns = ExportablePuzzle::SQL_COLUMNS;

        $query = <<<SQL
SELECT
    ssi.id AS sold_swapped_item_id,
    ssi.sold_at,
    ssi.listing_type,
    ssi.price,
    COALESCE(buyer.name, '#' || UPPER(buyer.code), ssi.buyer_name) AS buyer_name,
    UPPER(buyer.code) AS buyer_code,
    buyer.id IS NOT NULL AS buyer_registered,
    {$puzzleColumns}
FROM sold_swapped_item ssi
INNER JOIN puzzle p ON p.id = ssi.puzzle_id
LEFT JOIN manufacturer m ON m.id = p.manufacturer_id
LEFT JOIN player buyer ON buyer.id = ssi.buyer_player_id
WHERE ssi.seller_id = :playerId
ORDER BY ssi.sold_at DESC, ssi.id
SQL;

        $rows = $this->database
            ->executeQuery($query, [
                'playerId' => $playerId,
                'now' => $this->clock->now()->format('Y-m-d H:i:s'),
            ])
            ->fetchAllAssociative();

        return array_map(
            fn (array $row): ExportableSoldSwappedItem => ExportableSoldSwappedItem::fromDatabaseRow($row, $this->uploadedAssetsBaseUrl),
            $rows,
        );
    }
}
