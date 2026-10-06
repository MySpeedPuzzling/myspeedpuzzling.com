<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Results\Export\ExportableLendBorrowTransfer;
use SpeedPuzzling\Web\Results\Export\ExportablePuzzle;

/**
 * Every transfer the player took part in - the condition of GetLendBorrowHistory::byPlayerId(). The puzzle may be gone, hence the LEFT JOIN (docs/features/data-export.md).
 */
readonly final class GetExportableLendBorrowHistory
{
    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
        private string $uploadedAssetsBaseUrl,
    ) {
    }

    /**
     * @return list<ExportableLendBorrowTransfer>
     */
    public function byPlayerId(string $playerId): array
    {
        $puzzleColumns = ExportablePuzzle::SQL_COLUMNS;

        $query = <<<SQL
SELECT
    lpt.id AS transfer_id,
    lpt.lent_puzzle_id,
    lpt.transferred_at,
    lpt.transfer_type,
    COALESCE(from_player.name, '#' || UPPER(from_player.code), lpt.from_player_name) AS from_name,
    COALESCE(to_player.name, '#' || UPPER(to_player.code), lpt.to_player_name) AS to_name,
    COALESCE(owner_player.name, '#' || UPPER(owner_player.code), lpt.owner_name) AS owner_name,
    CONCAT_WS(',',
        CASE WHEN lpt.owner_player_id = :playerId THEN 'owner' END,
        CASE WHEN lpt.from_player_id = :playerId THEN 'from' END,
        CASE WHEN lpt.to_player_id = :playerId THEN 'to' END
    ) AS my_role,
    {$puzzleColumns}
FROM lent_puzzle_transfer lpt
LEFT JOIN puzzle p ON p.id = lpt.puzzle_id
LEFT JOIN manufacturer m ON m.id = p.manufacturer_id
LEFT JOIN player from_player ON from_player.id = lpt.from_player_id
LEFT JOIN player to_player ON to_player.id = lpt.to_player_id
LEFT JOIN player owner_player ON owner_player.id = lpt.owner_player_id
WHERE lpt.from_player_id = :playerId
    OR lpt.to_player_id = :playerId
    OR lpt.owner_player_id = :playerId
ORDER BY lpt.transferred_at DESC, lpt.id
SQL;

        $rows = $this->database
            ->executeQuery($query, [
                'playerId' => $playerId,
                'now' => $this->clock->now()->format('Y-m-d H:i:s'),
            ])
            ->fetchAllAssociative();

        return array_map(
            fn (array $row): ExportableLendBorrowTransfer => ExportableLendBorrowTransfer::fromDatabaseRow($row, $this->uploadedAssetsBaseUrl),
            $rows,
        );
    }
}
