<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\ConnectedWjpcParticipant;
use SpeedPuzzling\Web\Results\NotConnectedWjpcParticipant;
use SpeedPuzzling\Web\Value\CountryCode;

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
        $query1 = <<<SQL
SELECT 
    wjpc_participant.name AS wjpc_name, 
    wjpc_participant.year2023_rank AS rank_2023, 
    wjpc_participant.rounds,
    player.id AS player_id,
    player.name AS player_name,
    player.code AS player_code,
    player.country AS player_country
FROM 
    wjpc_participant
INNER JOIN 
    player ON player.id = wjpc_participant.player_id
WHERE 
    wjpc_participant.player_id IS NOT NULL;
SQL;

        $participants = $this->database
            ->executeQuery($query1)
            ->fetchAllAssociative();

        /** @var array<string> $playerIds */
        $playerIds = array_column($participants, 'player_id');

        if (empty($playerIds)) {
            return [];
        }

        $query2 = <<<SQL
SELECT
    puzzle_solving_time.player_id,
    AVG(CASE
            WHEN puzzle_solving_time.finished_at >= NOW() - INTERVAL '3 months'
            THEN puzzle_solving_time.seconds_to_solve
        END) AS average_time,
    MIN(CASE
            WHEN puzzle_solving_time.finished_at >= NOW() - INTERVAL '3 months'
            THEN puzzle_solving_time.seconds_to_solve
        END) AS fastest_time,
    COUNT(CASE
            WHEN puzzle_solving_time.finished_at >= NOW() - INTERVAL '3 months'
            THEN puzzle_solving_time.seconds_to_solve
        END) AS solved_puzzle_count
FROM 
    puzzle_solving_time
INNER JOIN
    puzzle ON puzzle.id = puzzle_solving_time.puzzle_id
WHERE
    puzzle_solving_time.player_id IN (:playerIds)
    AND puzzle_solving_time.team IS NULL
    AND puzzle.pieces_count = 500
GROUP BY
    puzzle_solving_time.player_id
SQL;

        $times = $this->database
            ->executeQuery($query2, [
                'playerIds' => $playerIds,
            ], [
                'playerIds' => ArrayParameterType::STRING,
            ])
            ->fetchAllAssociative();


        /**
         * @var array<string, array{
         *      player_id: string,
         *      average_time: null|int|float,
         *      fastest_time: null|int,
         *      solved_puzzle_count: int,
         * }> $timesByPlayerId
         */
        $timesByPlayerId = [];

        foreach ($times as $time) {
            $timesByPlayerId[$time['player_id']] = $time;
        }

        /** @var array<ConnectedWjpcParticipant> $results */
        $results = array_map(static function(array $participant) use ($timesByPlayerId): ConnectedWjpcParticipant {
            /**
             * @var array{
             *     wjpc_name: string,
             *     rank_2023: null|int,
             *     player_id: string,
             *     player_name: null|string,
             *     player_code: string,
             *     player_country: null|string,
             *     rounds: string,
             * } $participant
             */

            $playerId = $participant['player_id'];

            /**
             * @var array{average_time: null|int|float, fastest_time: null|int|float, solved_puzzle_count: int} $timeData
             */
            $timeData = $timesByPlayerId[$playerId] ?? [
                'average_time' => null,
                'fastest_time' => null,
                'solved_puzzle_count' => 0
            ];

            $rounds = [];
            if (json_validate($participant['rounds'])) {
                /** @var array<string> $rounds */
                $rounds = json_decode($participant['rounds'], true);
            }

            return new ConnectedWjpcParticipant(
                playerId: $playerId,
                playerName: $participant['player_name'] ?? $participant['player_code'],
                playerCountry: CountryCode::fromCode($participant['player_country']),
                fastestTime: is_numeric($timeData['fastest_time']) ? (int) $timeData['fastest_time'] : null,
                averageTime: is_numeric($timeData['average_time']) ? (int) $timeData['average_time'] : null,
                solvedPuzzleCount: $timeData['solved_puzzle_count'],
                wjpcName: $participant['wjpc_name'],
                rank2023: $participant['rank_2023'],
                rounds: $rounds,
            );
        }, $participants);

        usort($results, function(ConnectedWjpcParticipant $a, ConnectedWjpcParticipant $b): int {
            if ($a->averageTime === null) return 1;
            if ($b->averageTime === null) return -1;

            return $a->averageTime <=> $b->averageTime;
        });

        return $results;
    }

    /**
     * @return array<NotConnectedWjpcParticipant>
     */
    public function getNotConnectedParticipants(): array
    {
        $query = <<<SQL
SELECT name AS wjpc_name, year2023_rank AS rank_2023, rounds
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
             *     rounds: string,
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
