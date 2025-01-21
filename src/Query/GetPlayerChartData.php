<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Results\PlayerChartData;
use SpeedPuzzling\Web\Value\ChartTimePeriodType;

/**
 * @phpstan-import-type PlayerChartDataRow from PlayerChartData
 */
readonly final class GetPlayerChartData
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return array<string, string>
     */
    public function getBrandsSolvedSoloByPlayer(string $playerId): array
    {
        if (Uuid::isValid($playerId) === false) {
            throw new PlayerNotFound();
        }

        $query = <<<SQL
SELECT 
    m.id AS brand_id,
    m.name AS brand_name,
    COUNT(pst.id) AS solved_count
FROM 
    puzzle_solving_time pst
INNER JOIN puzzle p ON pst.puzzle_id = p.id
INNER JOIN manufacturer m ON p.manufacturer_id = m.id
WHERE 
    pst.player_id = :playerId
    AND p.pieces_count = 500
    AND pst.team IS NULL
GROUP BY m.id
ORDER BY solved_count DESC;
SQL;

        $data = $this->database
            ->executeQuery($query, [
                'playerId' => $playerId,
            ])
            ->fetchAllAssociative();

        $brands = [];

        foreach ($data as $row) {
            /**
             * @var array{
             *     brand_id: string,
             *     brand_name: string,
             *     solved_count: int,
             * } $row
             */

            $brands[$row['brand_id']] = sprintf('%s (%d)', $row['brand_name'], $row['solved_count']);
        }

        return $brands;
    }

    /**
     * @return array<PlayerChartData>
     */
    public function getForPlayer(
        string $playerId,
        null|string $brandId,
        ChartTimePeriodType $periodType
    ): array {
        if (Uuid::isValid($playerId) === false) {
            throw new PlayerNotFound();
        }

        $query = <<<SQL
SELECT 
    DATE_TRUNC(:period, pst.finished_at) AS period,
    AVG(pst.seconds_to_solve) AS time
FROM puzzle_solving_time pst
INNER JOIN puzzle p ON pst.puzzle_id = p.id
WHERE 
    pst.player_id = :playerId
    AND p.pieces_count = 500
SQL;

        if ($brandId !== null) {
            $query .= ' AND p.manufacturer_id = :brandId';
        }

        $query .= <<<SQL
    AND pst.team IS NULL
GROUP BY period
ORDER BY period;
SQL;

        /** @var array<PlayerChartDataRow> $data */
        $data = $this->database
            ->executeQuery($query, [
                'playerId' => $playerId,
                'brandId' => $brandId,
                'period' => $periodType === ChartTimePeriodType::Week ? 'week' : 'month',
            ])
            ->fetchAllAssociative();

        return array_map(static function(array $row): PlayerChartData {
            return PlayerChartData::fromDatabaseRow($row);
        }, $data);
    }
}