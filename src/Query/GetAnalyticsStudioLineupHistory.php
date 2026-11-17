<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\AnalyticsStudioAttempt;
use SpeedPuzzling\Web\Value\DatePrecision;

/**
 * The exact line-up's history of a studio pair/team (analytics-studio.md §2.7, D12) - one statement: the
 * `puzzling_team` whose composition key is the line-up's (TeamComposition::keyFromMemberKeys() of the members'
 * lowercase player ids - asked only when every member is analysable) and its results on the visible puzzles before the
 * round's history cut-off (GetAnalyticsStudioEntrantHistory::sqlBefore(), finished_at_precision), never this round's
 * own. Results without a tracker (`player_id` NULL - imported event results) belong to the group and count; suspicious
 * ones do not. Unfinished results come along as attempts, never as a solve or a time.
 */
readonly final class GetAnalyticsStudioLineupHistory
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @param list<string> $compositionKeys
     * @param list<string> $puzzleIds visible puzzles only
     * @return array<string, list<AnalyticsStudioAttempt>> composition key => attempts, newest first (memberId = the key)
     */
    public function of(array $compositionKeys, array $puzzleIds, DateTimeImmutable $cutOff, string $roundId): array
    {
        if ($compositionKeys === [] || $puzzleIds === []) {
            return [];
        }

        $before = GetAnalyticsStudioEntrantHistory::sqlBefore('pst');

        $rows = $this->database->executeQuery(
            <<<SQL
SELECT
    pt.composition_key,
    pst.puzzle_id,
    pst.puzzling_type,
    pst.puzzling_team_id,
    pst.seconds_to_solve,
    pst.pieces_placed,
    pst.first_attempt,
    pst.unboxed,
    COALESCE(pst.finished_at, pst.tracked_at) AS solved_at,
    pst.finished_at_precision
FROM puzzling_team pt
INNER JOIN puzzle_solving_time pst ON pst.puzzling_team_id = pt.id
WHERE pt.composition_key IN (:keys)
    AND pst.puzzle_id IN (:puzzleIds)
    AND pst.suspicious = false
    AND {$before}
    AND pst.competition_round_id IS DISTINCT FROM CAST(:roundId AS uuid)
ORDER BY solved_at DESC, pst.id DESC
SQL,
            [
                'keys' => $compositionKeys,
                'puzzleIds' => $puzzleIds,
                'cutOff' => $cutOff->format('Y-m-d H:i:s'),
                'roundId' => $roundId,
            ],
            ['keys' => ArrayParameterType::STRING, 'puzzleIds' => ArrayParameterType::STRING],
        )->fetchAllAssociative();

        $result = [];

        foreach ($rows as $row) {
            /** @var array{composition_key: string, puzzle_id: string, puzzling_type: string, puzzling_team_id: string, seconds_to_solve: null|int, pieces_placed: null|int, first_attempt: bool, unboxed: bool, solved_at: string, finished_at_precision: null|string} $row */
            $key = trim($row['composition_key']);
            $result[$key][] = new AnalyticsStudioAttempt(
                puzzleId: strtolower($row['puzzle_id']),
                memberId: $key,
                kind: $row['puzzling_type'],
                puzzlingTeamId: strtolower($row['puzzling_team_id']),
                seconds: $row['seconds_to_solve'],
                piecesPlaced: $row['pieces_placed'],
                firstAttempt: $row['first_attempt'],
                unboxed: $row['unboxed'],
                solvedAt: new DateTimeImmutable($row['solved_at']),
                precision: DatePrecision::ofStored($row['finished_at_precision']),
            );
        }

        return $result;
    }
}
