<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Results\PlayerChartData;
use SpeedPuzzling\Web\Results\PlayerSoloCounts;
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
     * The player's counted solo times (not suspicious) per piece count and brand, in one statement - both filters of
     * the profile chart read it (PlayerSoloCounts: the piece counts of a brand, the brands of a piece count).
     */
    public function soloCounts(string $playerId, bool $onlyFirstTries): PlayerSoloCounts
    {
        if (Uuid::isValid($playerId) === false) {
            throw new PlayerNotFound();
        }

        $firstTries = $onlyFirstTries === true ? 'AND pst.first_attempt = true' : '';

        $query = <<<SQL
SELECT
    p.pieces_count,
    m.id AS brand_id,
    m.name AS brand_name,
    COUNT(pst.id) AS solved_count
FROM puzzle_solving_time pst
INNER JOIN puzzle p ON pst.puzzle_id = p.id
INNER JOIN manufacturer m ON p.manufacturer_id = m.id
WHERE
    pst.player_id = :playerId
    AND pst.puzzling_type = 'solo'
    AND pst.suspicious = false
    {$firstTries}
GROUP BY p.pieces_count, m.id
SQL;

        /** @var list<array{pieces_count: int|string, brand_id: string, brand_name: string, solved_count: int|string}> $rows */
        $rows = $this->database
            ->executeQuery($query, [
                'playerId' => $playerId,
            ])
            ->fetchAllAssociative();

        return PlayerSoloCounts::fromDatabaseRows($rows);
    }

    /**
     * @return array<PlayerChartData>
     */
    public function getForPlayer(
        string $playerId,
        null|string $brandId,
        ChartTimePeriodType $periodType,
        int $pieces,
        bool $onlyFirstTries,
    ): array {
        if (Uuid::isValid($playerId) === false) {
            throw new PlayerNotFound();
        }

        $query = <<<SQL
SELECT 
    DATE_TRUNC(:period, COALESCE(pst.finished_at, pst.tracked_at)) AS period,
    AVG(pst.seconds_to_solve) AS time
FROM puzzle_solving_time pst
INNER JOIN puzzle p ON pst.puzzle_id = p.id
WHERE 
    pst.player_id = :playerId
    AND p.pieces_count = :pieces
    AND pst.seconds_to_solve IS NOT NULL
    AND pst.suspicious = false
SQL;

        if ($brandId !== null) {
            $query .= ' AND p.manufacturer_id = :brandId';
        }

        if ($onlyFirstTries === true) {
            $query .= ' AND pst.first_attempt = true';
        }

        $query .= <<<SQL
    AND pst.puzzling_type = 'solo'
GROUP BY period
ORDER BY period;
SQL;

        /** @var array<PlayerChartDataRow> $data */
        $data = $this->database
            ->executeQuery($query, [
                'playerId' => $playerId,
                'brandId' => $brandId,
                'period' => $periodType === ChartTimePeriodType::Week ? 'week' : 'month',
                'pieces' => $pieces,
            ])
            ->fetchAllAssociative();

        return array_map(static function (array $row): PlayerChartData {
            return PlayerChartData::fromDatabaseRow($row);
        }, $data);
    }
}
