<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\Results\PuzzleSolver;
use SpeedPuzzling\Web\Results\PuzzleSolversGroup;

readonly final class GetPuzzleSolvers
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @throws PuzzleNotFound
     * @return array<PuzzleSolver>
     */
    public function soloByPuzzleId(string $puzzleId): array
    {
        if (Uuid::isValid($puzzleId) === false) {
            throw new PuzzleNotFound();
        }

        $query = <<<SQL
SELECT
    player.id AS player_id,
    player.name AS player_name,
    player.code AS player_code,
    player.country AS player_country,
    puzzle_solving_time.puzzle_id AS puzzle_id,
    puzzle_solving_time.seconds_to_solve AS time,
    finished_at,
    first_attempt,
    unboxed,
    is_private,
    competition.id AS competition_id,
    competition.shortcut AS competition_shortcut,
    competition.name AS competition_name,
    competition.slug AS competition_slug
FROM puzzle_solving_time
INNER JOIN player ON puzzle_solving_time.player_id = player.id
LEFT JOIN competition ON competition.id = puzzle_solving_time.competition_id
WHERE puzzle_solving_time.puzzle_id = :puzzleId
    AND puzzle_solving_time.puzzling_type = 'solo'
    AND puzzle_solving_time.seconds_to_solve IS NOT NULL
    AND puzzle_solving_time.suspicious = false
ORDER BY seconds_to_solve ASC
SQL;

        $data = $this->database
            ->executeQuery($query, [
                'puzzleId' => $puzzleId,
            ])
            ->fetchAllAssociative();

        return array_map(static function (array $row): PuzzleSolver {
            /**
             * @var array{
             *     puzzle_id: string,
             *     player_id: string,
             *     player_name: null|string,
             *     player_code: string,
             *     player_country: null|string,
             *     time: int,
             *     finished_at: string,
             *     first_attempt: bool,
             *     unboxed: bool,
             *     is_private: bool,
             *     competition_id: null|string,
             *     competition_shortcut: null|string,
             *     competition_name: null|string,
             *     competition_slug: null|string,
             * } $row
             */

            return PuzzleSolver::fromDatabaseRow($row);
        }, $data);
    }

    /**
     * @throws PuzzleNotFound
     * @return array<PuzzleSolversGroup>
     */
    public function duoByPuzzleId(string $puzzleId): array
    {
        if (Uuid::isValid($puzzleId) === false) {
            throw new PuzzleNotFound();
        }

        $query = <<<SQL
SELECT
    pst.player_id AS player_id,
    pst.puzzle_id AS puzzle_id,
    pst.seconds_to_solve AS time,
    comment,
    pst.team ->> 'team_id' AS team_id,
    finished_at,
    first_attempt,
    pst.unboxed,
    competition.id AS competition_id,
    competition.shortcut AS competition_shortcut,
    competition.name AS competition_name,
    competition.slug AS competition_slug,
    JSON_AGG(
        JSON_BUILD_OBJECT(
            'player_id', player_elem.player ->> 'player_id',
            'player_name', COALESCE(p.name, player_elem.player ->> 'player_name'),
            'player_code', p.code,
            'player_country', p.country,
            'is_private', p.is_private
        ) ORDER BY player_elem.ordinality
    ) AS players
FROM
    puzzle_solving_time pst
    LEFT JOIN competition ON competition.id = pst.competition_id,
    LATERAL json_array_elements(pst.team -> 'puzzlers') WITH ORDINALITY AS player_elem(player, ordinality)
    LEFT JOIN player p ON p.id = (player_elem.player ->> 'player_id')::UUID
WHERE
    pst.puzzle_id = :puzzleId
    AND pst.puzzling_type = 'duo'
    AND pst.seconds_to_solve IS NOT NULL
    AND pst.suspicious = false
GROUP BY
    pst.id, time, competition.id
ORDER BY time ASC
SQL;

        $data = $this->database
            ->executeQuery($query, [
                'puzzleId' => $puzzleId,
            ])
            ->fetchAllAssociative();

        return array_map(static function (array $row): PuzzleSolversGroup {
            /**
             * @var array{
             *     player_id: string,
             *     puzzle_id: string,
             *     time: int,
             *     comment: null|string,
             *     team_id: null|string,
             *     players: string,
             *     finished_at: string,
             *     first_attempt: bool,
             *     unboxed: bool,
             *     competition_id: null|string,
             *     competition_shortcut: null|string,
             *     competition_name: null|string,
             *     competition_slug: null|string,
             * } $row
             */

            return PuzzleSolversGroup::fromDatabaseRow($row);
        }, $data);
    }

    /**
     * @throws PuzzleNotFound
     * @return array<PuzzleSolversGroup>
     */
    public function teamByPuzzleId(string $puzzleId): array
    {
        if (Uuid::isValid($puzzleId) === false) {
            throw new PuzzleNotFound();
        }

        $query = <<<SQL
SELECT
    pst.player_id AS player_id,
    pst.puzzle_id AS puzzle_id,
    pst.seconds_to_solve AS time,
    comment,
    pst.team ->> 'team_id' AS team_id,
    finished_at,
    first_attempt,
    pst.unboxed,
    competition.id AS competition_id,
    competition.shortcut AS competition_shortcut,
    competition.name AS competition_name,
    competition.slug AS competition_slug,
    JSON_AGG(
        JSON_BUILD_OBJECT(
            'player_id', player_elem.player ->> 'player_id',
            'player_name', COALESCE(p.name, player_elem.player ->> 'player_name'),
            'player_code', p.code,
            'player_country', p.country,
            'is_private', p.is_private
        ) ORDER BY player_elem.ordinality
    ) AS players
FROM
    puzzle_solving_time pst
    LEFT JOIN competition ON competition.id = pst.competition_id,
    LATERAL json_array_elements(pst.team -> 'puzzlers') WITH ORDINALITY AS player_elem(player, ordinality)
    LEFT JOIN player p ON p.id = (player_elem.player ->> 'player_id')::UUID
WHERE
    pst.puzzle_id = :puzzleId
    AND pst.puzzling_type = 'team'
    AND pst.seconds_to_solve IS NOT NULL
    AND pst.suspicious = false
GROUP BY
    pst.id, time, competition.id
ORDER BY time ASC
SQL;

        $data = $this->database
            ->executeQuery($query, [
                'puzzleId' => $puzzleId,
            ])
            ->fetchAllAssociative();

        return array_map(static function (array $row): PuzzleSolversGroup {
            /**
             * @var array{
             *     player_id: string,
             *     puzzle_id: string,
             *     time: int,
             *     comment: null|string,
             *     team_id: null|string,
             *     players: string,
             *     finished_at: string,
             *     first_attempt: bool,
             *     unboxed: bool,
             *     competition_id: null|string,
             *     competition_shortcut: null|string,
             *     competition_name: null|string,
             *     competition_slug: null|string,
             * } $row
             */

            return PuzzleSolversGroup::fromDatabaseRow($row);
        }, $data);
    }

    /**
     * @throws PuzzleNotFound
     * @return array{solo: int, duo: int, team: int}
     */
    public function relaxCountsByPuzzleId(string $puzzleId): array
    {
        if (Uuid::isValid($puzzleId) === false) {
            throw new PuzzleNotFound();
        }

        $query = <<<SQL
SELECT
    COUNT(*) FILTER (WHERE puzzling_type = 'solo') AS solo_count,
    COUNT(*) FILTER (WHERE puzzling_type = 'duo') AS duo_count,
    COUNT(*) FILTER (WHERE puzzling_type = 'team') AS team_count
FROM puzzle_solving_time
WHERE puzzle_id = :puzzleId
    AND seconds_to_solve IS NULL
SQL;

        /** @var array{solo_count: int, duo_count: int, team_count: int} $row */
        $row = $this->database
            ->executeQuery($query, [
                'puzzleId' => $puzzleId,
            ])
            ->fetchAssociative();

        return [
            'solo' => (int) $row['solo_count'],
            'duo' => (int) $row['duo_count'],
            'team' => (int) $row['team_count'],
        ];
    }
}
