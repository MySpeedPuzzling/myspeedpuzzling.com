<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;

/**
 * How much the player puzzles (the forecast's milestones): every result of theirs - solo, and pairs/teams they are in,
 * whoever tracked it - in total and in the last 182 days, and their solo boxed times at one piece count in the last 91
 * days. One statement; suspicious results are left out like everywhere.
 */
readonly final class GetInsightsActivity
{
    public const int RECENT_DAYS = 182;
    public const int RECENT_TIMES_DAYS = 91;

    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return array{total: int, recent: int, recentTimes: list<int>}
     */
    public function forPlayer(string $playerId, int $piecesCount, DateTimeImmutable $now): array
    {
        /** @var array{total: int|string, recent: int|string, recent_times: null|string}|false $row */
        $row = $this->database->fetchAssociative(
            "WITH mine AS (
                SELECT COALESCE(pst.finished_at, pst.tracked_at) AS solved_at
                FROM puzzle_solving_time pst
                WHERE pst.player_id = :playerId
                    AND pst.puzzling_team_id IS NULL
                    AND pst.suspicious = false
                UNION ALL
                SELECT COALESCE(pst.finished_at, pst.tracked_at)
                FROM puzzling_team_member me
                JOIN puzzle_solving_time pst ON pst.puzzling_team_id = me.team_id
                WHERE me.player_id = :playerId
                    AND pst.suspicious = false
            )
            SELECT
                COUNT(*) AS total,
                COUNT(*) FILTER (WHERE solved_at >= CAST(:recentSince AS timestamp)) AS recent,
                (
                    SELECT json_agg(pst.seconds_to_solve ORDER BY pst.seconds_to_solve)
                    FROM puzzle_solving_time pst
                    JOIN puzzle p ON p.id = pst.puzzle_id
                    WHERE pst.player_id = :playerId
                        AND pst.puzzling_type = 'solo'
                        AND pst.suspicious = false
                        AND pst.unboxed = false
                        AND pst.seconds_to_solve > 0
                        AND p.pieces_count = :piecesCount
                        AND COALESCE(pst.finished_at, pst.tracked_at) >= CAST(:timesSince AS timestamp)
                ) AS recent_times
            FROM mine",
            [
                'playerId' => $playerId,
                'piecesCount' => $piecesCount,
                'recentSince' => $now->modify('-' . self::RECENT_DAYS . ' days')->format('Y-m-d H:i:s'),
                'timesSince' => $now->modify('-' . self::RECENT_TIMES_DAYS . ' days')->format('Y-m-d H:i:s'),
            ],
        );

        if ($row === false) {
            return ['total' => 0, 'recent' => 0, 'recentTimes' => []];
        }

        /** @var list<int> $recentTimes */
        $recentTimes = $row['recent_times'] !== null ? json_decode($row['recent_times'], true, flags: JSON_THROW_ON_ERROR) : [];

        return ['total' => (int) $row['total'], 'recent' => (int) $row['recent'], 'recentTimes' => $recentTimes];
    }
}
