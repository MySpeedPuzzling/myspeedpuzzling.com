<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Results\Export\ExportableWishListItem;
use SpeedPuzzling\Web\Results\Export\ExportablePuzzle;

/**
 * The player's wishlist (docs/features/data-export.md).
 */
readonly final class GetExportableWishList
{
    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
        private string $uploadedAssetsBaseUrl,
    ) {
    }

    /**
     * @return list<ExportableWishListItem>
     */
    public function byPlayerId(string $playerId): array
    {
        $puzzleColumns = ExportablePuzzle::SQL_COLUMNS;

        $query = <<<SQL
SELECT
    wli.id AS wish_list_item_id,
    wli.added_at,
    wli.remove_on_collection_add,
    {$puzzleColumns}
FROM wish_list_item wli
INNER JOIN puzzle p ON p.id = wli.puzzle_id
LEFT JOIN manufacturer m ON m.id = p.manufacturer_id
WHERE wli.player_id = :playerId
ORDER BY wli.added_at DESC, wli.id
SQL;

        $rows = $this->database
            ->executeQuery($query, [
                'playerId' => $playerId,
                'now' => $this->clock->now()->format('Y-m-d H:i:s'),
            ])
            ->fetchAllAssociative();

        return array_map(
            fn (array $row): ExportableWishListItem => ExportableWishListItem::fromDatabaseRow($row, $this->uploadedAssetsBaseUrl),
            $rows,
        );
    }
}
