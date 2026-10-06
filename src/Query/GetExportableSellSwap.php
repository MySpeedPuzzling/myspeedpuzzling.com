<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Results\Export\ExportableSellSwapItem;
use SpeedPuzzling\Web\Results\Export\ExportablePuzzle;

/**
 * The player's sell/swap list, unpublished and reserved items included (docs/features/data-export.md).
 */
readonly final class GetExportableSellSwap
{
    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
        private string $uploadedAssetsBaseUrl,
    ) {
    }

    /**
     * @return list<ExportableSellSwapItem>
     */
    public function byPlayerId(string $playerId): array
    {
        $puzzleColumns = ExportablePuzzle::SQL_COLUMNS;

        $query = <<<SQL
SELECT
    ssli.id AS sell_swap_item_id,
    ssli.added_at,
    ssli.listing_type,
    ssli.price,
    ssli.condition,
    ssli.comment,
    ssli.published_on_marketplace,
    ssli.reserved,
    ssli.reserved_at,
    COALESCE(reserved_for.name, '#' || UPPER(reserved_for.code)) AS reserved_for_name,
    {$puzzleColumns}
FROM sell_swap_list_item ssli
INNER JOIN puzzle p ON p.id = ssli.puzzle_id
LEFT JOIN manufacturer m ON m.id = p.manufacturer_id
LEFT JOIN player reserved_for ON reserved_for.id = ssli.reserved_for_player_id
WHERE ssli.player_id = :playerId
ORDER BY ssli.added_at DESC, ssli.id
SQL;

        $rows = $this->database
            ->executeQuery($query, [
                'playerId' => $playerId,
                'now' => $this->clock->now()->format('Y-m-d H:i:s'),
            ])
            ->fetchAllAssociative();

        return array_map(
            fn (array $row): ExportableSellSwapItem => ExportableSellSwapItem::fromDatabaseRow($row, $this->uploadedAssetsBaseUrl),
            $rows,
        );
    }
}
