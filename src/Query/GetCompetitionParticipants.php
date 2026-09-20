<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Results\ConnectedCompetitionParticipant;
use SpeedPuzzling\Web\Results\NotConnectedCompetitionParticipant;
use SpeedPuzzling\Web\Services\HiddenPlayers;
use SpeedPuzzling\Web\Services\PrivateProfileAccess;
use SpeedPuzzling\Web\Value\CountryCode;

readonly final class GetCompetitionParticipants
{
    public function __construct(
        private Connection $database,
        private PrivateProfileAccess $privateProfileAccess,
        private ClockInterface $clock,
        private HiddenPlayers $hiddenPlayers,
    ) {
    }

    /**
     * @return array<ConnectedCompetitionParticipant>
     * @param array<string> $roundsFilter
     */
    public function getConnectedParticipants(string $competitionId, array $roundsFilter = [], bool $firstTryOnly = false): array
    {
        $query1 = <<<SQL
SELECT DISTINCT
    competition_participant.id AS participant_id,
    competition_participant.name AS participant_name,  
    player.id AS player_id,
    player.name AS player_name,
    player.code AS player_code,
    player.country AS player_country,
    {$this->privateProfileAccess->sqlIsPrivate('player')} AS is_private
FROM
    competition_participant
INNER JOIN
    player ON player.id = competition_participant.player_id
SQL;

        if (count($roundsFilter) > 0) {
            $query1 .= <<<SQL

INNER JOIN 
    competition_participant_round ON competition_participant_round.participant_id = competition_participant.id
SQL;
        }

        $query1 .= <<<SQL

WHERE
    competition_participant.player_id IS NOT NULL
    AND competition_participant.competition_id = :competitionId
    AND competition_participant.deleted_at IS NULL
SQL;

        if (count($roundsFilter) > 0) {
            $query1 .= ' AND competition_participant_round.round_id IN (:rounds)';
        }

        $query1 .= $this->hiddenPlayers->sqlExclude('player.id');

        $queryParams = ['competitionId' => $competitionId];
        $paramTypes = [];

        if (count($roundsFilter) > 0) {
            $queryParams['rounds'] = $roundsFilter;
            $paramTypes['rounds'] = ArrayParameterType::STRING;
        }

        $participants = $this->database
            ->executeQuery($query1, $queryParams, $paramTypes)
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
            WHEN puzzle_solving_time.finished_at >= :now::timestamp - INTERVAL '3 months'
            THEN puzzle_solving_time.seconds_to_solve
        END) AS average_time,
    MIN(CASE
            WHEN puzzle_solving_time.finished_at >= :now::timestamp - INTERVAL '3 months'
            THEN puzzle_solving_time.seconds_to_solve
        END) AS fastest_time,
    COUNT(CASE
            WHEN puzzle_solving_time.finished_at >= :now::timestamp - INTERVAL '3 months'
            THEN puzzle_solving_time.seconds_to_solve
        END) AS solved_puzzle_count
FROM 
    puzzle_solving_time
INNER JOIN
    puzzle ON puzzle.id = puzzle_solving_time.puzzle_id
WHERE
    puzzle_solving_time.player_id IN (:playerIds)
    AND puzzle_solving_time.puzzling_type = 'solo'
    AND puzzle.pieces_count = 500
SQL;

        if ($firstTryOnly) {
            $query2 .= ' AND puzzle_solving_time.first_attempt = true';
        }

        $query2 .= <<<SQL

GROUP BY
    puzzle_solving_time.player_id
SQL;

        $times = $this->database
            ->executeQuery($query2, [
                'playerIds' => $playerIds,
                'now' => $this->clock->now()->format('Y-m-d H:i:s'),
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
            /** @var array{player_id: string, average_time: null|int|float, fastest_time: null|int, solved_puzzle_count: int} $time */
            $timesByPlayerId[$time['player_id']] = $time;
        }

        /** @var array<ConnectedCompetitionParticipant> $results */
        $results = array_map(static function (array $participant) use ($timesByPlayerId): ConnectedCompetitionParticipant {
            /**
             * @var array{
             *     participant_id: string,
             *     participant_name: string,
             *     player_id: string,
             *     player_name: null|string,
             *     player_code: string,
             *     player_country: null|string,
             *     is_private: bool,
             * } $participant
             */

            $playerId = $participant['player_id'];

            /**
             * @var array{average_time: null|int|float, fastest_time: null|int|float, solved_puzzle_count: int} $timeData
             */
            $timeData = $timesByPlayerId[$playerId] ?? [
                'average_time' => null,
                'fastest_time' => null,
                'solved_puzzle_count' => 0,
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
                isPrivate: (bool) $participant['is_private'],
            );
        }, $participants);

        usort($results, function (ConnectedCompetitionParticipant $a, ConnectedCompetitionParticipant $b): int {
            if ($a->averageTime === null) {
                return 1;
            }
            if ($b->averageTime === null) {
                return -1;
            }

            return $a->averageTime <=> $b->averageTime;
        });

        return $results;
    }

    /**
     * @return array<NotConnectedCompetitionParticipant>
     * @param array<string> $roundsFilter
     */
    public function getNotConnectedParticipants(string $competitionId, array $roundsFilter = []): array
    {
        $query = <<<SQL
SELECT DISTINCT competition_participant.id, competition_participant.name, competition_participant.country
FROM competition_participant
SQL;

        if (count($roundsFilter) > 0) {
            $query .= <<<SQL

INNER JOIN 
    competition_participant_round ON competition_participant_round.participant_id = competition_participant.id
SQL;
        }

        $query .= <<<SQL

WHERE competition_participant.player_id IS NULL AND competition_participant.competition_id = :competitionId
AND competition_participant.deleted_at IS NULL
SQL;

        if (count($roundsFilter) > 0) {
            $query .= ' AND competition_participant_round.round_id IN (:rounds)';
        }

        $query .= ' ORDER BY competition_participant.name';

        $queryParams = ['competitionId' => $competitionId];
        $paramTypes = [];

        if (count($roundsFilter) > 0) {
            $queryParams['rounds'] = $roundsFilter;
            $paramTypes['rounds'] = ArrayParameterType::STRING;
        }

        $data = $this->database
            ->executeQuery($query, $queryParams, $paramTypes)
            ->fetchAllAssociative();

        return array_map(static function (array $row): NotConnectedCompetitionParticipant {
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
AND deleted_at IS NULL
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

    public function isPlayerSelfJoined(string $competitionId, string $playerId): bool
    {
        $query = <<<SQL
SELECT EXISTS (
    SELECT 1
    FROM competition_participant
    WHERE player_id = :playerId AND competition_id = :competitionId
    AND deleted_at IS NULL
    AND source = 'self_joined'
)
SQL;

        return (bool) $this->database
            ->executeQuery($query, [
                'playerId' => $playerId,
                'competitionId' => $competitionId,
            ])
            ->fetchOne();
    }

    public function hasNotConnectedParticipants(string $competitionId): bool
    {
        $query = <<<SQL
SELECT EXISTS (
    SELECT 1
    FROM competition_participant
    WHERE competition_id = :competitionId
    AND player_id IS NULL
    AND deleted_at IS NULL
)
SQL;

        return (bool) $this->database
            ->executeQuery($query, ['competitionId' => $competitionId])
            ->fetchOne();
    }

    /**
     * The one not-connected participant whose name equals the given name, ignoring case, accents
     * and extra whitespace. A participant with a different country does not match, and neither
     * does an ambiguous name — then the player picks from the list themselves.
     */
    public function findNotConnectedParticipantMatchingName(string $competitionId, string $name, null|string $country): null|string
    {
        if (trim($name) === '') {
            return null;
        }

        $query = <<<SQL
SELECT id
FROM competition_participant
WHERE competition_id = :competitionId
AND player_id IS NULL
AND deleted_at IS NULL
AND lower(regexp_replace(trim(immutable_unaccent(name)), '\s+', ' ', 'g'))
    = lower(regexp_replace(trim(immutable_unaccent(:name)), '\s+', ' ', 'g'))
AND (country IS NULL OR CAST(:country AS TEXT) IS NULL OR country = :country)
LIMIT 2
SQL;

        /** @var array<string> $ids */
        $ids = $this->database
            ->executeQuery($query, [
                'competitionId' => $competitionId,
                'name' => $name,
                'country' => $country,
            ])
            ->fetchFirstColumn();

        return count($ids) === 1 ? $ids[0] : null;
    }
}
