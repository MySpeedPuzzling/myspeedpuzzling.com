<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\CursorRows;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\StatisticalInputsSql;
use SpeedPuzzling\Web\Value\PuzzleImageFingerprint;

/**
 * Every pair/team result the pair/team model replays (docs/features/puzzle-intelligence/README.md "Pair and team
 * predictions"), in the order of the solves - (COALESCE(finished_at, tracked_at), tracked_at, id), the as-of order of
 * the whole insights system: a time with seconds, not suspicious, of a puzzling_team; its members' player ids (null =
 * a guest), whether the puzzle is secret now, and the picture estimate of the puzzle's current picture (none for a
 * secret puzzle). Through a cursor.
 */
readonly final class GetGroupPredictionResults
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @return iterable<array{id: string, team_id: string, puzzle_id: string, pieces_count: int|string, seconds_to_solve: int|string, first_attempt: bool|int|string, unboxed: bool|int|string, secret: bool|int|string, competition: bool|int|string, solved_ts: float|int|string, tracked_ts: float|int|string, members: string, image_log: null|float|string}>
     */
    public function rows(DateTimeImmutable $now): iterable
    {
        $notSecret = StatisticalInputsSql::NOT_SECRET;
        $fingerprint = PuzzleImageFingerprint::sql('p.image');

        /** @var iterable<array{id: string, team_id: string, puzzle_id: string, pieces_count: int|string, seconds_to_solve: int|string, first_attempt: bool|int|string, unboxed: bool|int|string, secret: bool|int|string, competition: bool|int|string, solved_ts: float|int|string, tracked_ts: float|int|string, members: string, image_log: null|float|string}> $rows */
        $rows = CursorRows::iterate($this->connection, "
            WITH team_members AS MATERIALIZED (
                SELECT m.team_id, json_agg(m.player_id ORDER BY m.member_key) AS members
                FROM puzzling_team_member m
                GROUP BY m.team_id
            )
            SELECT
                pst.id,
                pst.puzzling_team_id AS team_id,
                pst.puzzle_id,
                p.pieces_count,
                pst.seconds_to_solve,
                pst.first_attempt,
                pst.unboxed,
                NOT ({$notSecret}) AS secret,
                (pst.competition_id IS NOT NULL OR pst.competition_series_id IS NOT NULL) AS competition,
                EXTRACT(EPOCH FROM COALESCE(pst.finished_at, pst.tracked_at)) AS solved_ts,
                EXTRACT(EPOCH FROM pst.tracked_at) AS tracked_ts,
                tm.members,
                img.log_difficulty AS image_log
            FROM puzzle_solving_time pst
            JOIN team_members tm ON tm.team_id = pst.puzzling_team_id
            JOIN puzzle p ON p.id = pst.puzzle_id
            LEFT JOIN puzzle_difficulty_estimate img ON img.puzzle_id = p.id AND img.image_fingerprint = {$fingerprint} AND {$notSecret}
            WHERE pst.puzzling_type IN ('duo', 'team')
                AND pst.suspicious = false
                AND pst.seconds_to_solve > 0
            ORDER BY COALESCE(pst.finished_at, pst.tracked_at), pst.tracked_at, pst.id
        ", ['now' => $now->format('Y-m-d H:i:s')]);

        return $rows;
    }
}
