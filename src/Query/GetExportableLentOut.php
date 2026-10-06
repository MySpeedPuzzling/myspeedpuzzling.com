<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Results\Export\ExportableLentPuzzle;
use SpeedPuzzling\Web\Results\Export\ExportablePuzzle;

/**
 * Puzzles the player owns and lent out, with whoever holds them now - bilateral history, shown like the lend/borrow page shows it (docs/features/data-export.md).
 */
readonly final class GetExportableLentOut
{
    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
        private string $uploadedAssetsBaseUrl,
    ) {
    }

    /**
     * @return list<ExportableLentPuzzle>
     */
    public function byPlayerId(string $playerId): array
    {
        $puzzleColumns = ExportablePuzzle::SQL_COLUMNS;

        $query = <<<SQL
SELECT
    lp.id AS lent_puzzle_id,
    lp.lent_at,
    lp.notes,
    COALESCE(holder.name, '#' || UPPER(holder.code), lp.current_holder_name) AS counterparty_name,
    UPPER(holder.code) AS counterparty_code,
    holder.id IS NOT NULL AS counterparty_registered,
    {$puzzleColumns}
FROM lent_puzzle lp
INNER JOIN puzzle p ON p.id = lp.puzzle_id
LEFT JOIN manufacturer m ON m.id = p.manufacturer_id
LEFT JOIN player holder ON holder.id = lp.current_holder_player_id
WHERE lp.owner_player_id = :playerId
ORDER BY lp.lent_at DESC, lp.id
SQL;

        $rows = $this->database
            ->executeQuery($query, [
                'playerId' => $playerId,
                'now' => $this->clock->now()->format('Y-m-d H:i:s'),
            ])
            ->fetchAllAssociative();

        return array_map(
            fn (array $row): ExportableLentPuzzle => ExportableLentPuzzle::fromDatabaseRow($row, $this->uploadedAssetsBaseUrl),
            $rows,
        );
    }
}
