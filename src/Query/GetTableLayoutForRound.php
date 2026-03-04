<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\TableLayoutRow;
use SpeedPuzzling\Web\Results\TableLayoutSpot;
use SpeedPuzzling\Web\Results\TableLayoutTable;
use SpeedPuzzling\Web\Value\CountryCode;

readonly final class GetTableLayoutForRound
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return array<TableLayoutRow>
     */
    public function byRoundId(string $roundId): array
    {
        $query = <<<SQL
SELECT
    tr.id AS row_id,
    tr.position AS row_position,
    tr.label AS row_label,
    rt.id AS table_id,
    rt.position AS table_position,
    rt.label AS table_label,
    ts.id AS spot_id,
    ts.position AS spot_position,
    ts.player_name AS spot_player_name,
    p.id AS player_id,
    p.name AS player_name,
    p.code AS player_code,
    p.country AS player_country
FROM table_row tr
LEFT JOIN round_table rt ON rt.row_id = tr.id
LEFT JOIN table_spot ts ON ts.table_id = rt.id
LEFT JOIN player p ON p.id = ts.player_id
WHERE tr.round_id = :roundId
ORDER BY tr.position, rt.position, ts.position
SQL;

        $data = $this->database
            ->executeQuery($query, [
                'roundId' => $roundId,
            ])
            ->fetchAllAssociative();

        /**
         * @var array<string, array{
         *     row_id: string,
         *     row_position: int|string,
         *     row_label: null|string,
         *     tables: array<string, array{
         *         table_id: string,
         *         table_position: int|string,
         *         table_label: string,
         *         spots: array<TableLayoutSpot>,
         *     }>,
         * }> $rows
         */
        $rows = [];

        foreach ($data as $row) {
            /** @var array{
             *     row_id: string,
             *     row_position: int|string,
             *     row_label: null|string,
             *     table_id: null|string,
             *     table_position: null|int|string,
             *     table_label: null|string,
             *     spot_id: null|string,
             *     spot_position: null|int|string,
             *     spot_player_name: null|string,
             *     player_id: null|string,
             *     player_name: null|string,
             *     player_code: null|string,
             *     player_country: null|string,
             * } $row
             */
            $rowId = $row['row_id'];

            if (!isset($rows[$rowId])) {
                $rows[$rowId] = [
                    'row_id' => $rowId,
                    'row_position' => $row['row_position'],
                    'row_label' => $row['row_label'],
                    'tables' => [],
                ];
            }

            $tableId = $row['table_id'];
            if ($tableId === null) {
                continue;
            }

            if (!isset($rows[$rowId]['tables'][$tableId])) {
                $rows[$rowId]['tables'][$tableId] = [
                    'table_id' => $tableId,
                    'table_position' => $row['table_position'],
                    'table_label' => $row['table_label'] ?? '',
                    'spots' => [],
                ];
            }

            $spotId = $row['spot_id'];
            if ($spotId === null) {
                continue;
            }

            $playerName = $row['player_name'] ?? $row['spot_player_name'];

            $rows[$rowId]['tables'][$tableId]['spots'][] = new TableLayoutSpot(
                id: $spotId,
                position: (int) $row['spot_position'],
                playerId: $row['player_id'],
                playerName: $playerName,
                playerCode: $row['player_code'],
                playerCountry: $row['player_country'] !== null ? CountryCode::fromCode($row['player_country']) : null,
            );
        }

        return array_values(array_map(
            static fn (array $rowData): TableLayoutRow => new TableLayoutRow(
                id: $rowData['row_id'],
                position: (int) $rowData['row_position'],
                label: $rowData['row_label'],
                tables: array_values(array_map(
                    static fn (array $tableData): TableLayoutTable => new TableLayoutTable(
                        id: $tableData['table_id'],
                        position: (int) $tableData['table_position'],
                        label: $tableData['table_label'],
                        spots: $tableData['spots'],
                    ),
                    $rowData['tables'],
                )),
            ),
            $rows,
        ));
    }
}
