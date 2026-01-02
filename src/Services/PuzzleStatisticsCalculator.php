<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Value\PuzzleStatisticsData;

readonly final class PuzzleStatisticsCalculator
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function calculateForPuzzle(UuidInterface $puzzleId): PuzzleStatisticsData
    {
        $result = $this->connection->executeQuery("
            SELECT
                -- Total
                COUNT(*) AS total_count,
                MIN(seconds_to_solve) AS fastest_time,
                AVG(seconds_to_solve)::int AS average_time,
                MAX(seconds_to_solve) AS slowest_time,

                -- Solo
                COUNT(*) FILTER (WHERE puzzling_type = 'solo') AS solo_count,
                MIN(seconds_to_solve) FILTER (WHERE puzzling_type = 'solo') AS fastest_time_solo,
                (AVG(seconds_to_solve) FILTER (WHERE puzzling_type = 'solo'))::int AS average_time_solo,
                MAX(seconds_to_solve) FILTER (WHERE puzzling_type = 'solo') AS slowest_time_solo,

                -- Duo
                COUNT(*) FILTER (WHERE puzzling_type = 'duo') AS duo_count,
                MIN(seconds_to_solve) FILTER (WHERE puzzling_type = 'duo') AS fastest_time_duo,
                (AVG(seconds_to_solve) FILTER (WHERE puzzling_type = 'duo'))::int AS average_time_duo,
                MAX(seconds_to_solve) FILTER (WHERE puzzling_type = 'duo') AS slowest_time_duo,

                -- Team
                COUNT(*) FILTER (WHERE puzzling_type = 'team') AS team_count,
                MIN(seconds_to_solve) FILTER (WHERE puzzling_type = 'team') AS fastest_time_team,
                (AVG(seconds_to_solve) FILTER (WHERE puzzling_type = 'team'))::int AS average_time_team,
                MAX(seconds_to_solve) FILTER (WHERE puzzling_type = 'team') AS slowest_time_team
            FROM puzzle_solving_time
            WHERE puzzle_id = :puzzleId
        ", ['puzzleId' => $puzzleId->toString()])->fetchAssociative();

        /** @var array{total_count: int|string, fastest_time: int|string|null, average_time: int|string|null, slowest_time: int|string|null, solo_count: int|string, fastest_time_solo: int|string|null, average_time_solo: int|string|null, slowest_time_solo: int|string|null, duo_count: int|string, fastest_time_duo: int|string|null, average_time_duo: int|string|null, slowest_time_duo: int|string|null, team_count: int|string, fastest_time_team: int|string|null, average_time_team: int|string|null, slowest_time_team: int|string|null}|false $result */

        if ($result === false || (int) $result['total_count'] === 0) {
            return PuzzleStatisticsData::empty();
        }

        return new PuzzleStatisticsData(
            totalCount: (int) $result['total_count'],
            fastestTime: $this->toNullableInt($result['fastest_time']),
            averageTime: $this->toNullableInt($result['average_time']),
            slowestTime: $this->toNullableInt($result['slowest_time']),
            soloCount: (int) $result['solo_count'],
            fastestTimeSolo: $this->toNullableInt($result['fastest_time_solo']),
            averageTimeSolo: $this->toNullableInt($result['average_time_solo']),
            slowestTimeSolo: $this->toNullableInt($result['slowest_time_solo']),
            duoCount: (int) $result['duo_count'],
            fastestTimeDuo: $this->toNullableInt($result['fastest_time_duo']),
            averageTimeDuo: $this->toNullableInt($result['average_time_duo']),
            slowestTimeDuo: $this->toNullableInt($result['slowest_time_duo']),
            teamCount: (int) $result['team_count'],
            fastestTimeTeam: $this->toNullableInt($result['fastest_time_team']),
            averageTimeTeam: $this->toNullableInt($result['average_time_team']),
            slowestTimeTeam: $this->toNullableInt($result['slowest_time_team']),
        );
    }

    private function toNullableInt(null|int|string $value): null|int
    {
        if ($value === null) {
            return null;
        }

        return (int) $value;
    }
}
