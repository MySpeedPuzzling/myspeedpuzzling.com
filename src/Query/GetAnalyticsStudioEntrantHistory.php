<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\AnalyticsStudioAttempt;
use SpeedPuzzling\Web\Value\DatePrecision;

/**
 * Every earlier attempt of the studio's analysable members on the round's visible puzzles (analytics-studio.md §2.6,
 * R1, R5) - one statement: solo attempts by the player, and pair/team attempts through the group's members
 * (`puzzling_team_member`), which takes the imported results without a tracker (`player_id` NULL, a claimed spot = a
 * member) along as the group results they are.
 *
 * - "Before": before the round's history cut-off (AnalyticsStudioRound::historyCutOff()), and never this round's own
 *   logged times; a result known only to its month or year counts only when that whole period ended before it
 *   (sqlBefore() - finished_at_precision);
 * - suspicious results are left out; unfinished results (`pieces_placed`) come along as attempts, never as a time or a
 *   solve (CountsAsSolve::condition() is folded in PHP by AnalyticsStudioAttempt::countsAsSolve());
 * - only puzzle ids the caller got from RoundPuzzlesVisibleNow, only analysable (public, linked) player ids.
 */
readonly final class GetAnalyticsStudioEntrantHistory
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * SQL: the result of `$alias` lies wholly before `:cutOff` - a day/moment before it, or a month/year (the stored
     * first day of the period, DatePrecision) whose whole period ended by then.
     */
    public static function sqlBefore(string $alias): string
    {
        return <<<SQL
(CASE {$alias}.finished_at_precision
    WHEN 'month' THEN {$alias}.finished_at + INTERVAL '1 month' <= CAST(:cutOff AS timestamp)
    WHEN 'year' THEN {$alias}.finished_at + INTERVAL '1 year' <= CAST(:cutOff AS timestamp)
    ELSE COALESCE({$alias}.finished_at, {$alias}.tracked_at) < CAST(:cutOff AS timestamp)
END)
SQL;
    }

    /**
     * @param list<string> $puzzleIds visible puzzles only
     * @param list<string> $memberIds analysable players only
     * @return list<AnalyticsStudioAttempt> newest first
     */
    public function attempts(array $puzzleIds, array $memberIds, DateTimeImmutable $cutOff, string $roundId): array
    {
        if ($puzzleIds === [] || $memberIds === []) {
            return [];
        }

        $before = self::sqlBefore('pst');

        $rows = $this->database->executeQuery(
            <<<SQL
SELECT
    pst.puzzle_id,
    pst.player_id AS member_id,
    'solo' AS kind,
    NULL::uuid AS puzzling_team_id,
    pst.seconds_to_solve,
    pst.pieces_placed,
    pst.first_attempt,
    pst.unboxed,
    COALESCE(pst.finished_at, pst.tracked_at) AS solved_at,
    pst.finished_at_precision
FROM puzzle_solving_time pst
WHERE pst.puzzle_id IN (:puzzleIds)
    AND pst.player_id IN (:memberIds)
    AND pst.player_id IS NOT NULL
    AND pst.puzzling_type = 'solo'
    AND pst.suspicious = false
    AND {$before}
    AND pst.competition_round_id IS DISTINCT FROM CAST(:roundId AS uuid)
UNION ALL
SELECT
    pst.puzzle_id,
    ptm.player_id AS member_id,
    pst.puzzling_type AS kind,
    pst.puzzling_team_id,
    pst.seconds_to_solve,
    pst.pieces_placed,
    pst.first_attempt,
    pst.unboxed,
    COALESCE(pst.finished_at, pst.tracked_at) AS solved_at,
    pst.finished_at_precision
FROM puzzle_solving_time pst
INNER JOIN puzzling_team_member ptm ON ptm.team_id = pst.puzzling_team_id AND ptm.player_id IN (:memberIds)
WHERE pst.puzzle_id IN (:puzzleIds)
    AND pst.puzzling_team_id IS NOT NULL
    AND pst.puzzling_type <> 'solo'
    AND pst.suspicious = false
    AND {$before}
    AND pst.competition_round_id IS DISTINCT FROM CAST(:roundId AS uuid)
ORDER BY solved_at DESC, puzzle_id, member_id
SQL,
            [
                'puzzleIds' => $puzzleIds,
                'memberIds' => $memberIds,
                'cutOff' => $cutOff->format('Y-m-d H:i:s'),
                'roundId' => $roundId,
            ],
            [
                'puzzleIds' => ArrayParameterType::STRING,
                'memberIds' => ArrayParameterType::STRING,
            ],
        )->fetchAllAssociative();

        $attempts = [];

        foreach ($rows as $row) {
            /** @var array{puzzle_id: string, member_id: string, kind: string, puzzling_team_id: null|string, seconds_to_solve: null|int, pieces_placed: null|int, first_attempt: bool, unboxed: bool, solved_at: string, finished_at_precision: null|string} $row */
            $attempts[] = new AnalyticsStudioAttempt(
                puzzleId: strtolower($row['puzzle_id']),
                memberId: strtolower($row['member_id']),
                kind: $row['kind'],
                puzzlingTeamId: $row['puzzling_team_id'] !== null ? strtolower($row['puzzling_team_id']) : null,
                seconds: $row['seconds_to_solve'],
                piecesPlaced: $row['pieces_placed'],
                firstAttempt: $row['first_attempt'],
                unboxed: $row['unboxed'],
                solvedAt: new DateTimeImmutable($row['solved_at']),
                precision: DatePrecision::ofStored($row['finished_at_precision']),
            );
        }

        return $attempts;
    }
}
