<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\ConnectedWjpcParticipant;
use SpeedPuzzling\Web\Results\NotConnectedWjpcParticipant;

readonly final class GetWjpcParticipants
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return array<string, string>
     */
    public function mappingForPairing(): array
    {
        $query = <<<SQL
SELECT name, id
FROM wjpc_participant
SQL;
        $results = [];

        $rows = $this->database
            ->executeQuery($query)
            ->fetchAllAssociative();

        foreach ($rows as $row) {
            /**
             * @var array{name: string, id: string} $row
             */

            $results[$row['name']] = $row['id'];
        }

        return $results;
    }

    /**
     * @return array<string, string>
     */
    public function mappingToPlayers(): array
    {
        $query = <<<SQL
SELECT player_id, name
FROM wjpc_participant
WHERE player_id IS NOT NULL
SQL;
        $results = [];

        $rows = $this->database
            ->executeQuery($query)
            ->fetchAllAssociative();

        foreach ($rows as $row) {
            /**
             * @var array{player_id: string, name: string} $row
             */

            $results[$row['player_id']] = $row['name'];
        }

        return $results;
    }

    /**
     * @return array<ConnectedWjpcParticipant>
     */
    public function getConnectedParticipants(): array
    {
        $query = <<<SQL
WITH Participants AS (
    SELECT 
        wjpc_participant.name AS wjpc_name, 
        wjpc_participant.year2023_rank AS rank_2023, 
        player.id AS player_id,
        player.name AS player_name,
        player.code AS player_code,
        player.country AS player_country
    FROM 
        wjpc_participant
    INNER JOIN 
        player ON player.id = wjpc_participant.player_id
    WHERE 
        wjpc_participant.player_id IS NOT NULL
),
FilteredTimes AS (
    SELECT
        puzzle_solving_time.player_id,
        puzzle_solving_time.puzzle_id,
        MIN(puzzle_solving_time.seconds_to_solve) AS min_seconds_to_solve,
        puzzle_solving_time.finished_at
    FROM
        puzzle_solving_time
    INNER JOIN
        puzzle ON puzzle.id = puzzle_solving_time.puzzle_id
    WHERE
        puzzle_solving_time.player_id IN (SELECT player_id FROM Participants)
        AND puzzle_solving_time.team IS NULL
        AND puzzle.pieces_count = 500
    GROUP BY
        puzzle_solving_time.player_id, puzzle_solving_time.puzzle_id, puzzle_solving_time.finished_at
)
SELECT
    Participants.wjpc_name,
    Participants.rank_2023,
    Participants.player_id,
    Participants.player_name,
    Participants.player_code,
    Participants.player_country,
    AVG(CASE
            WHEN FilteredTimes.finished_at >= NOW() - INTERVAL '3 months'
            THEN FilteredTimes.min_seconds_to_solve
        END) AS average_time,
    MIN(CASE
            WHEN FilteredTimes.finished_at >= NOW() - INTERVAL '3 months'
            THEN FilteredTimes.min_seconds_to_solve
        END) AS fastest_time,
    COUNT(CASE
            WHEN FilteredTimes.finished_at >= NOW() - INTERVAL '3 months'
            THEN FilteredTimes.min_seconds_to_solve
        END) AS solved_puzzle_count
FROM 
    Participants
LEFT JOIN
    FilteredTimes ON Participants.player_id = FilteredTimes.player_id
GROUP BY
    Participants.wjpc_name,
    Participants.rank_2023,
    Participants.player_id,
    Participants.player_name,
    Participants.player_code,
    Participants.player_country
ORDER BY
    average_time, rank_2023;
SQL;

        $data = $this->database
            ->executeQuery($query)
            ->fetchAllAssociative();

        return array_map(static function(array $row): ConnectedWjpcParticipant {
            /**
             * @var array{
             *     wjpc_name: string,
             *     rank_2023: null|int,
             *     player_id: string,
             *     player_name: null|string,
             *     player_code: string,
             *     player_country: null|string,
             *     average_time: null|int|float,
             *     fastest_time: null|int,
             *     solved_puzzle_count: int,
             * } $row
             */

            return ConnectedWjpcParticipant::fromDatabaseRow($row);
        }, $data);
    }

    /**
     * @return array<NotConnectedWjpcParticipant>
     */
    public function getNotConnectedParticipants(): array
    {
        $query = <<<SQL
SELECT name AS wjpc_name, year2023_rank AS rank_2023
FROM wjpc_participant
WHERE player_id IS NULL
ORDER BY rank_2023, wjpc_name
SQL;

        $data = $this->database
            ->executeQuery($query)
            ->fetchAllAssociative();

        return array_map(static function(array $row): NotConnectedWjpcParticipant {
            /**
             * @var array{
             *     wjpc_name: string,
             *     rank_2023: null|int,
             * } $row
             */

            return NotConnectedWjpcParticipant::fromDatabaseRow($row);
        }, $data);
    }

    /**
     * @return array<string>
     */
    public function getPlayerConnections(string $playerId): array
    {
        $query = <<<SQL
SELECT id
FROM wjpc_participant
WHERE player_id = :playerId
SQL;

        /** @var array<string> $rows */
        $rows = $this->database
            ->executeQuery($query, [
                'playerId' => $playerId,
            ])
            ->fetchFirstColumn();

        return $rows;
    }
}
