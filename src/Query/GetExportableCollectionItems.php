<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Results\Export\ExportableCollectionItem;
use SpeedPuzzling\Web\Results\Export\ExportablePuzzle;

/**
 * Every collection item of the player, system collection (collection_id NULL) and custom ones, private included - own data (docs/features/data-export.md).
 */
readonly final class GetExportableCollectionItems
{
    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
        private string $uploadedAssetsBaseUrl,
    ) {
    }

    /**
     * @return list<ExportableCollectionItem>
     */
    public function byPlayerId(string $playerId): array
    {
        $puzzleColumns = ExportablePuzzle::SQL_COLUMNS;

        $query = <<<SQL
SELECT
    ci.id AS collection_item_id,
    ci.collection_id,
    c.name AS collection_name,
    ci.added_at,
    ci.comment,
    {$puzzleColumns}
FROM collection_item ci
INNER JOIN puzzle p ON p.id = ci.puzzle_id
LEFT JOIN manufacturer m ON m.id = p.manufacturer_id
LEFT JOIN collection c ON c.id = ci.collection_id
WHERE ci.player_id = :playerId
ORDER BY ci.collection_id NULLS FIRST, ci.added_at DESC, ci.id
SQL;

        $rows = $this->database
            ->executeQuery($query, [
                'playerId' => $playerId,
                'now' => $this->clock->now()->format('Y-m-d H:i:s'),
            ])
            ->fetchAllAssociative();

        return array_map(
            fn (array $row): ExportableCollectionItem => ExportableCollectionItem::fromDatabaseRow($row, $this->uploadedAssetsBaseUrl),
            $rows,
        );
    }
}
