<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;

readonly final class GetClaimableResultsForPlayer
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * Results in a competition the player can add to their profile:
     * their connected participant's solo results, and results of teams they are a
     * connected member of. Only published rounds with a puzzle count — a solving
     * time needs a puzzle, and draft standings stay private.
     *
     * @return array<array{resultId: string, roundId: string, roundName: string, category: string, entrantName: null|string, secondsToSolve: null|int, missingPieces: null|int}>
     */
    public function inCompetition(string $competitionId, string $playerId): array
    {
        $query = <<<SQL
SELECT rr.id AS result_id, r.id AS round_id, r.name AS round_name, r.category,
    COALESCE(cp.name, ct.name) AS entrant_name,
    rr.seconds_to_solve, rr.missing_pieces
FROM round_result rr
INNER JOIN competition_round r ON r.id = rr.round_id
LEFT JOIN competition_participant cp ON cp.id = rr.participant_id
LEFT JOIN competition_team ct ON ct.id = rr.team_id
LEFT JOIN puzzle_solving_time pst ON pst.id = rr.solving_time_id
WHERE r.competition_id = :competitionId
AND r.results_published_at IS NOT NULL
AND EXISTS (SELECT 1 FROM competition_round_puzzle crp WHERE crp.round_id = r.id)
AND (
    -- Solo: my participant's result, not yet materialized
    (
        rr.participant_id IS NOT NULL
        AND cp.player_id = :playerId
        AND cp.deleted_at IS NULL
        AND rr.solving_time_id IS NULL
    )
    OR
    -- Team: I'm a connected member and my entry is not yet a real player in the group
    (
        rr.team_id IS NOT NULL
        AND EXISTS (
            SELECT 1
            FROM competition_participant_round cpr
            INNER JOIN competition_participant member ON member.id = cpr.participant_id AND member.deleted_at IS NULL
            WHERE cpr.team_id = rr.team_id
            AND member.player_id = :playerId
        )
        AND (
            rr.solving_time_id IS NULL
            OR (
                pst.player_id != :playerId
                AND NOT ((pst.team::jsonb -> 'puzzlers') @> jsonb_build_array(jsonb_build_object('player_id', CAST(:playerId AS UUID))))
            )
        )
    )
)
ORDER BY r.starts_at ASC
SQL;

        /** @var array<array{result_id: string, round_id: string, round_name: string, category: string, entrant_name: null|string, seconds_to_solve: null|int|string, missing_pieces: null|int|string}> $rows */
        $rows = $this->database->executeQuery($query, [
            'competitionId' => $competitionId,
            'playerId' => $playerId,
        ])->fetchAllAssociative();

        return array_map(static fn (array $row): array => [
            'resultId' => $row['result_id'],
            'roundId' => $row['round_id'],
            'roundName' => $row['round_name'],
            'category' => $row['category'],
            'entrantName' => $row['entrant_name'],
            'secondsToSolve' => $row['seconds_to_solve'] !== null ? (int) $row['seconds_to_solve'] : null,
            'missingPieces' => $row['missing_pieces'] !== null ? (int) $row['missing_pieces'] : null,
        ], $rows);
    }
}
