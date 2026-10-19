<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Results\Insights\GroupResultMember;
use SpeedPuzzling\Web\Results\Insights\InsightsGroupResult;
use SpeedPuzzling\Web\Results\Insights\InsightsGroupResults;
use SpeedPuzzling\Web\Results\Insights\InsightsPuzzleRef;
use SpeedPuzzling\Web\Services\HiddenPlayers;
use SpeedPuzzling\Web\Services\PrivateProfileAccess;

/**
 * The player's pair/team results for Insights (docs/features/insights/computation.md §4c.1): every result of a
 * puzzling_team the player is a member of - found through puzzling_team_member, so a result someone else tracked is
 * there too - not suspicious and timed, with every member of the team and the puzzle's difficulty. One statement.
 *
 * Self-only: the player's own history stays whole (HiddenPlayers keeps group times the viewer took part in), so a
 * member the viewer blocks is marked `blocked` instead of dropping the result; a private member the viewer may not
 * see is `hidden` (PrivateProfileAccess - never named).
 */
readonly final class GetInsightsGroupResults
{
    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
        private HiddenPlayers $hiddenPlayers,
        private PrivateProfileAccess $privateProfileAccess,
    ) {
    }

    public function forPlayer(string $playerId): InsightsGroupResults
    {
        $isPrivate = $this->privateProfileAccess->sqlIsPrivate('pl');
        $puzzleColumns = InsightsPuzzleRef::sqlColumns();

        $query = <<<SQL
WITH my_teams AS MATERIALIZED (
    SELECT DISTINCT me.team_id
    FROM puzzling_team_member me
    WHERE me.player_id = :playerId
),
team_members AS MATERIALIZED (
    SELECT
        ptm.team_id,
        COUNT(*) AS size,
        json_agg(json_build_array(ptm.player_id, ptm.guest_name, pl.name, pl.code, {$isPrivate}) ORDER BY ptm.position, ptm.member_key) AS members
    FROM puzzling_team_member ptm
    JOIN my_teams mt ON mt.team_id = ptm.team_id
    LEFT JOIN player pl ON pl.id = ptm.player_id
    GROUP BY ptm.team_id
)
SELECT
    pst.id AS time_id,
    pst.seconds_to_solve,
    pst.first_attempt,
    pst.unboxed,
    COALESCE(pst.finished_at, pst.tracked_at) AS solved_at,
    pst.tracked_at,
    EXTRACT(EPOCH FROM COALESCE(pst.finished_at, pst.tracked_at)) AS solved_ts,
    EXTRACT(EPOCH FROM pst.tracked_at) AS tracked_ts,
    pst.puzzling_team_id AS team_id,
    pt.name AS team_name,
    tm.size,
    tm.members,
    CASE WHEN pd.confidence <> 'insufficient' THEN pd.difficulty_score END AS difficulty_score,
    {$puzzleColumns}
FROM team_members tm
JOIN puzzle_solving_time pst ON pst.puzzling_team_id = tm.team_id
JOIN puzzling_team pt ON pt.id = tm.team_id
JOIN puzzle p ON p.id = pst.puzzle_id
LEFT JOIN manufacturer m ON m.id = p.manufacturer_id
LEFT JOIN puzzle_difficulty pd ON pd.puzzle_id = p.id
WHERE pst.puzzling_type IN ('duo', 'team')
    AND pst.suspicious = false
    AND pst.seconds_to_solve > 0
ORDER BY COALESCE(pst.finished_at, pst.tracked_at), pst.tracked_at, pst.id
SQL;

        /** @var list<array{time_id: string, seconds_to_solve: int|string, first_attempt: bool|int|string, unboxed: bool|int|string, solved_at: string, tracked_at: string, solved_ts: float|int|string, tracked_ts: float|int|string, team_id: string, team_name: null|string, size: int|string, members: string, difficulty_score: null|float|string, puzzle_id: string, puzzle_name: string, puzzle_image: null|string, brand_name: null|string, pieces_count: int|string, puzzle_secret: bool|int|string}> $rows */
        $rows = $this->database->fetchAllAssociative($query, [
            'playerId' => $playerId,
            'now' => $this->clock->now()->format('Y-m-d H:i:s'),
        ]);

        $results = [];
        $memberIds = [];
        $memberIdsByTime = [];

        foreach ($rows as $row) {
            /** @var list<array{0: null|string, 1: null|string, 2: null|string, 3: null|string, 4: null|bool}> $memberRows */
            $memberRows = json_decode($row['members'], true, flags: JSON_THROW_ON_ERROR);
            $members = [];
            $ids = [];

            foreach ($memberRows as [$memberId, $guestName, $name, $code, $private]) {
                $hidden = $memberId !== null && $private === true && strtolower($memberId) !== strtolower($playerId);
                $ids[] = $memberId;

                $members[] = new GroupResultMember(
                    // A private member the viewer may not see is nobody in what the page gets - not even an id
                    playerId: $hidden ? null : $memberId,
                    guestName: $memberId === null ? $guestName : null,
                    name: $hidden ? null : $name,
                    code: $hidden ? null : $code,
                    hidden: $hidden,
                    blocked: $this->hiddenPlayers->isHidden($memberId),
                );

                if ($memberId !== null) {
                    $memberIds[$memberId] = true;
                }
            }

            $memberIdsByTime[$row['time_id']] = $ids;
            $results[] = new InsightsGroupResult(
                timeId: $row['time_id'],
                puzzleId: $row['puzzle_id'],
                puzzle: InsightsPuzzleRef::fromRow($row),
                piecesCount: (int) $row['pieces_count'],
                seconds: (int) $row['seconds_to_solve'],
                solvedAt: new DateTimeImmutable($row['solved_at']),
                trackedAt: new DateTimeImmutable($row['tracked_at']),
                firstAttempt: self::bool($row['first_attempt']),
                unboxed: self::bool($row['unboxed']),
                teamId: $row['team_id'],
                teamName: $row['team_name'],
                size: (int) $row['size'],
                difficulty: $row['difficulty_score'] !== null ? (float) $row['difficulty_score'] : null,
                members: $members,
                solvedEpoch: (float) $row['solved_ts'],
                trackedEpoch: (float) $row['tracked_ts'],
            );
        }

        return new InsightsGroupResults($results, array_map(strval(...), array_keys($memberIds)), $memberIdsByTime);
    }

    private static function bool(bool|int|string $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't';
    }
}
