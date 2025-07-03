<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\ConnectedCompetitionParticipant;
use SpeedPuzzling\Web\Results\NotConnectedCompetitionParticipant;
use SpeedPuzzling\Web\Results\CompetitionParticipantInfo;
use SpeedPuzzling\Web\Value\CountryCode;

readonly final class GetCompetitionParticipants
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return array<string, string>
     */
    public function mappingForPairing(string $competitionId): array
    {
        $query = <<<SQL
SELECT name, id
FROM competition_participant
WHERE competition_id = :competitionId
SQL;
        $results = [];

        $rows = $this->database
            ->executeQuery($query, [
                'competitionId' => $competitionId,
            ])
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
    public function mappingToPlayers(string $competitionId): array
    {
        $query = <<<SQL
SELECT player_id, name
FROM competition_participant
WHERE player_id IS NOT NULL AND competition_id = :competitionId
SQL;
        $results = [];

        $rows = $this->database
            ->executeQuery($query, [
                'competitionId' => $competitionId,
            ])
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
     * @return array<ConnectedCompetitionParticipant>
     */
    public function getConnectedParticipants(string $competitionId): array
    {
        $query1 = <<<SQL
SELECT 
    competition_participant.id AS participant_id,
    competition_participant.name AS participant_name,  
    player.id AS player_id,
    player.name AS player_name,
    player.code AS player_code,
    player.country AS player_country
FROM 
    competition_participant
INNER JOIN 
    player ON player.id = competition_participant.player_id
WHERE 
    competition_participant.player_id IS NOT NULL
    AND competition_participant.competition_id = :competitionId
SQL;

        $participants = $this->database
            ->executeQuery($query1, [
                'competitionId' => $competitionId,
            ])
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

        /** @var array<ConnectedCompetitionParticipant> $results */
        $results = array_map(static function(array $participant) use ($timesByPlayerId): ConnectedCompetitionParticipant {
            /**
             * @var array{
             *     participant_id: string,
             *     participant_name: string,
             *     player_id: string,
             *     player_name: null|string,
             *     player_code: string,
             *     player_country: null|string,
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

            return new ConnectedCompetitionParticipant(
                participantId: $participant['participant_id'],
                participantName: $participant['participant_name'],
                playerId: $playerId,
                playerName: $participant['player_name'] ?? $participant['player_code'],
                playerCountry: CountryCode::fromCode($participant['player_country']),
                fastestTime: is_numeric($timeData['fastest_time']) ? (int) $timeData['fastest_time'] : null,
                averageTime: is_numeric($timeData['average_time']) ? (int) $timeData['average_time'] : null,
                solvedPuzzleCount: $timeData['solved_puzzle_count'],
                rounds: $rounds,
            );
        }, $participants);

        usort($results, function(ConnectedCompetitionParticipant $a, ConnectedCompetitionParticipant $b): int {
            if ($a->averageTime === null) return 1;
            if ($b->averageTime === null) return -1;

            return $a->averageTime <=> $b->averageTime;
        });

        return $results;
    }

    /**
     * @return array<NotConnectedCompetitionParticipant>
     */
    public function getNotConnectedParticipants(string $competitionId): array
    {
        $query = <<<SQL
SELECT id, name, country
FROM competition_participant
WHERE player_id IS NULL AND competition_id = :competitionId
ORDER BY name
SQL;

        $data = $this->database
            ->executeQuery($query, [
                'competitionId' => $competitionId,
            ])
            ->fetchAllAssociative();

        return array_map(static function(array $row): NotConnectedCompetitionParticipant {
            /**
             * @var array{
             *     id: string,
             *     name: string,
             *     country: null|string,
             * } $row
             */

            return NotConnectedCompetitionParticipant::fromDatabaseRow($row);
        }, $data);
    }

    /**
     * @return array<string>
     */
    public function getPlayerConnections(string $competitionId, string $playerId): array
    {
        $query = <<<SQL
SELECT id
FROM competition_participant
WHERE player_id = :playerId AND competition_id = :competitionId
SQL;

        /** @var array<string> $rows */
        $rows = $this->database
            ->executeQuery($query, [
                'playerId' => $playerId,
                'competitionId' => $competitionId,
            ])
            ->fetchFirstColumn();

        return $rows;
    }

    public function forPlayer(string $playerId): null|CompetitionParticipantInfo
    {
        $query = <<<SQL
SELECT 
    competition_participant.id AS participant_id,
    competition_participant.name AS participant_name, 
    competition_participant.rounds
FROM 
    competition_participant
WHERE 
    competition_participant.player_id = :playerId
SQL;

        /**
         * @var false|array{
         *     participant_id: string,
         *     participant_name: string,
         * } $row
         */
        $row = $this->database
            ->executeQuery($query, ['playerId' => $playerId])
            ->fetchAssociative();

        if (is_array($row) === false) {
            return null;
        }

        return new CompetitionParticipantInfo(
            participantId: $row['participant_id'],
            participantName: $row['participant_name'],
            rounds: [],
        );
    }
}
