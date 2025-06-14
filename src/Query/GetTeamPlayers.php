<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Value\Puzzler;

readonly final class GetTeamPlayers
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @param array<string> $solvingTimesIds
     * @return array<string, array<Puzzler>>
     */
    public function byIds(array $solvingTimesIds): array
    {
        $query = <<<SQL
SELECT
    puzzle_solving_time.id AS time_id,
    (player_elem.player ->> 'player_id') AS player_id,
    COALESCE(p.name, player_elem.player ->> 'player_name') AS player_name,
    p.country AS player_country,
    p.code AS player_code,
    p.is_private AS is_private
FROM puzzle_solving_time
LEFT JOIN LATERAL
    json_array_elements(puzzle_solving_time.team -> 'puzzlers')
    WITH ORDINALITY AS player_elem(player, ordinality) ON true
LEFT JOIN player p ON p.id = (player_elem.player ->> 'player_id')::UUID
WHERE puzzle_solving_time.id IN (:ids)
ORDER BY puzzle_solving_time.id, player_elem.ordinality;
SQL;

        $data = $this->database
            ->executeQuery($query, [
                'ids' => $solvingTimesIds,
            ], [
                'ids' => ArrayParameterType::STRING,
            ])
            ->fetchAllAssociative();

        $results = [];

        foreach ($data as $row) {
            /**
             * @var array{
             *     time_id: string,
             *     player_id: null|string,
             *     player_name: null|string,
             *     player_country: null|string,
             *     player_code: null|string,
             *     is_private: null|bool,
             * } $row
             */

            if ($row['player_name'] === null) {
                continue;
            }

            $results[$row['time_id']][] = Puzzler::fromDatabaseRow($row);
        }

        return $results;
    }
}
