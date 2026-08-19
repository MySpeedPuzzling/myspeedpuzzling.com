<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\PlayerPuzzleSolves;
use SpeedPuzzling\Web\Results\PlayerPuzzleSolvesGroup;
use SpeedPuzzling\Web\Value\PuzzlingType;

/**
 * One player's own solves of a list of puzzles, per discipline, in one query
 * (the "solves" object of the public API's puzzle cards). The set of rows is
 * the same as /me/results?type=: solo rows the player owns, duo and team rows
 * the player took part in (team JSON containment, as GetPlayerSolvedPuzzles) -
 * unboxed and suspicious-flagged times included, it is the player's own data.
 * Times without seconds (relax solves) count as solves but carry no time.
 */
readonly final class GetPlayerPuzzleSolves
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * Puzzles the player has never solved are absent from the result; a
     * discipline without solves is an empty group (count 0, nulls).
     *
     * @param list<string> $puzzleIds
     *
     * @return array<string, PlayerPuzzleSolves> keyed by puzzle id
     */
    public function forPuzzles(string $playerId, array $puzzleIds): array
    {
        if ($puzzleIds === []) {
            return [];
        }

        $query = <<<SQL
SELECT
    pst.puzzle_id,
    pst.puzzling_type,
    count(*) AS solve_count,
    min(pst.seconds_to_solve) AS best_seconds,
    (array_agg(pst.seconds_to_solve ORDER BY COALESCE(pst.finished_at, pst.tracked_at) DESC, pst.tracked_at DESC)
        FILTER (WHERE pst.seconds_to_solve IS NOT NULL))[1] AS last_seconds,
    min(COALESCE(pst.finished_at, pst.tracked_at)) AS first_solved_at,
    max(COALESCE(pst.finished_at, pst.tracked_at)) AS last_solved_at
FROM puzzle_solving_time pst
WHERE pst.puzzle_id IN (:puzzleIds)
    AND (
        (pst.puzzling_type = 'solo' AND pst.player_id = :playerId)
        OR (
            pst.puzzling_type IN ('duo', 'team')
            AND pst.team IS NOT NULL
            AND (pst.team::jsonb -> 'puzzlers') @> jsonb_build_array(jsonb_build_object('player_id', CAST(:playerId AS UUID)))
        )
    )
GROUP BY pst.puzzle_id, pst.puzzling_type
SQL;

        /**
         * @var list<array{
         *     puzzle_id: string,
         *     puzzling_type: string,
         *     solve_count: int|string,
         *     best_seconds: null|int|string,
         *     last_seconds: null|int|string,
         *     first_solved_at: null|string,
         *     last_solved_at: null|string,
         * }> $rows
         */
        $rows = $this->database->executeQuery(
            $query,
            ['playerId' => $playerId, 'puzzleIds' => $puzzleIds],
            ['puzzleIds' => ArrayParameterType::STRING],
        )->fetchAllAssociative();

        /** @var array<string, array<string, PlayerPuzzleSolvesGroup>> $groups puzzle id → discipline → group */
        $groups = [];

        foreach ($rows as $row) {
            $groups[$row['puzzle_id']][$row['puzzling_type']] = PlayerPuzzleSolvesGroup::fromDatabaseRow($row);
        }

        $solves = [];

        foreach ($groups as $puzzleId => $byDiscipline) {
            $solves[$puzzleId] = new PlayerPuzzleSolves(
                puzzleId: $puzzleId,
                solo: $byDiscipline[PuzzlingType::Solo->value] ?? PlayerPuzzleSolvesGroup::empty(),
                duo: $byDiscipline[PuzzlingType::Duo->value] ?? PlayerPuzzleSolvesGroup::empty(),
                team: $byDiscipline[PuzzlingType::Team->value] ?? PlayerPuzzleSolvesGroup::empty(),
            );
        }

        return $solves;
    }
}
