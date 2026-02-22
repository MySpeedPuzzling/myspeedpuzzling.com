<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Exceptions\PuzzleSolvingTimeNotFound;
use SpeedPuzzling\Web\Results\SolvedPuzzle;
use SpeedPuzzling\Web\Results\SolvedPuzzleDetail;
use SpeedPuzzling\Web\Results\SolvedPuzzleOverview;

readonly final class GetPlayerSolvedPuzzles
{
    public function __construct(
        private Connection $database,
        private GetTeamPlayers $getTeamPlayers,
    ) {
    }

    /**
     * @throws PlayerNotFound
     */
    public function getOldestResultDate(string $playerId): null|DateTimeImmutable
    {
        if (Uuid::isValid($playerId) === false) {
            throw new PlayerNotFound();
        }

        $query = <<<SQL
SELECT MIN(finished_at) AS first_date
FROM puzzle_solving_time
WHERE
    player_id = :playerId
    OR (team::jsonb -> 'puzzlers') @> jsonb_build_array(jsonb_build_object('player_id', CAST(:playerId AS UUID)))
LIMIT 1
SQL;

        /** @var false|null|string $result */
        $result = $this->database->executeQuery($query, [
            'playerId' => $playerId,
        ])->fetchOne();

        if ($result === false || $result === null) {
            return null;
        }

        return new DateTimeImmutable($result);
    }

    /**
     * @throws PuzzleSolvingTimeNotFound
     */
    public function byTimeId(string $timeId): SolvedPuzzleDetail
    {
        if (Uuid::isValid($timeId) === false) {
            throw new PuzzleSolvingTimeNotFound();
        }

        $query = <<<SQL
SELECT
    puzzle_solving_time.id as time_id,
    puzzle.id AS puzzle_id,
    puzzle_solving_time.team ->> 'team_id' AS team_id,
    puzzle.name AS puzzle_name,
    puzzle.alternative_name AS puzzle_alternative_name,
    puzzle.image AS puzzle_image,
    puzzle_solving_time.seconds_to_solve AS time,
    puzzle_solving_time.player_id AS player_id,
    pieces_count,
    player.name AS player_name,
    puzzle_solving_time.comment,
    manufacturer.name AS manufacturer_name,
    manufacturer.id AS manufacturer_id,
    finished_at,
    finished_puzzle_photo,
    first_attempt,
    puzzle_solving_time.unboxed,
    competition.id AS competition_id,
    CASE
        WHEN puzzle_solving_time.team IS NOT NULL THEN
            JSON_AGG(
                JSON_BUILD_OBJECT(
                    'player_id', player_elem.player ->> 'player_id',
                    'player_name', COALESCE(p.name, player_elem.player ->> 'player_name'),
                    'player_code', p.code,
                    'player_country', p.country,
                    'is_private', p.is_private
                ) ORDER BY player_elem.ordinality
            )
        ELSE NULL
    END AS players
FROM puzzle_solving_time
    LEFT JOIN LATERAL json_array_elements(puzzle_solving_time.team -> 'puzzlers') WITH ORDINALITY AS player_elem(player, ordinality) ON puzzle_solving_time.team IS NOT NULL
    LEFT JOIN player p ON p.id = (player_elem.player ->> 'player_id')::UUID
    INNER JOIN puzzle ON puzzle.id = puzzle_solving_time.puzzle_id
    INNER JOIN player ON puzzle_solving_time.player_id = player.id
    INNER JOIN manufacturer ON manufacturer.id = puzzle.manufacturer_id
    LEFT JOIN competition ON competition.id = puzzle_solving_time.competition_id
WHERE puzzle_solving_time.id = :timeId
GROUP BY puzzle_solving_time.id, puzzle.id, player.id, manufacturer.id, competition.id
SQL;

        /**
         * @var false|array{
         *     time_id: string,
         *     team_id: null|string,
         *     player_id: string,
         *     puzzle_id: string,
         *     puzzle_name: string,
         *     puzzle_alternative_name: null|string,
         *     manufacturer_name: string,
         *     manufacturer_id: string,
         *     puzzle_image: null|string,
         *     time: int,
         *     pieces_count: int,
         *     comment: null|string,
         *     players: null|string,
         *     finished_at: null|string,
         *     finished_puzzle_photo: string,
         *     first_attempt: bool,
         *     unboxed: bool,
         *     competition_id: null|string,
         * } $row
         */
        $row = $this->database
            ->executeQuery($query, [
                'timeId' => $timeId,
            ])
            ->fetchAssociative();

        if (is_array($row) === false) {
            throw new PuzzleSolvingTimeNotFound();
        }

        return SolvedPuzzleDetail::fromDatabaseRow($row);
    }

    /**
     * @return array<SolvedPuzzle>
     * @throws PlayerNotFound
     */
    public function soloByPlayerId(
        string $playerId,
        null|DateTimeImmutable $dateFrom = null,
        null|DateTimeImmutable $dateTo = null,
        bool $onlyFirstTries = false,
    ): array {
        if (Uuid::isValid($playerId) === false) {
            throw new PlayerNotFound();
        }

        $this->assertPlayerExists($playerId);

        $query = <<<SQL
WITH solved_counts AS (
    SELECT
        puzzle_id,
        COUNT(id) AS solved_times
    FROM puzzle_solving_time
    WHERE puzzling_type = 'solo'
      AND player_id = :playerId
    GROUP BY puzzle_id
)
SELECT
    puzzle_solving_time.id as time_id,
    puzzle.id AS puzzle_id,
    puzzle.name AS puzzle_name,
    puzzle.alternative_name AS puzzle_alternative_name,
    puzzle.image AS puzzle_image,
    puzzle_solving_time.seconds_to_solve AS time,
    puzzle_solving_time.player_id AS player_id,
    pieces_count,
    player.name AS player_name,
    player.code AS player_code,
    player.country AS player_country,
    puzzle.identification_number AS puzzle_identification_number,
    puzzle_solving_time.comment,
    puzzle_solving_time.tracked_at,
    finished_at,
    manufacturer.name AS manufacturer_name,
    puzzle_solving_time.finished_puzzle_photo AS finished_puzzle_photo,
    first_attempt,
    puzzle_solving_time.unboxed,
    solved_counts.solved_times AS solved_times,
    competition.id AS competition_id,
    competition.shortcut AS competition_shortcut,
    competition.name AS competition_name,
    competition.slug AS competition_slug,
    puzzle_solving_time.suspicious
FROM puzzle_solving_time
    INNER JOIN puzzle ON puzzle.id = puzzle_solving_time.puzzle_id
    INNER JOIN player ON puzzle_solving_time.player_id = player.id
    INNER JOIN manufacturer ON manufacturer.id = puzzle.manufacturer_id
    LEFT JOIN solved_counts ON solved_counts.puzzle_id = puzzle_solving_time.puzzle_id
    LEFT JOIN competition ON competition.id = puzzle_solving_time.competition_id
WHERE
    puzzle_solving_time.player_id = :playerId
    AND puzzle_solving_time.puzzling_type = 'solo'
SQL;

        if ($onlyFirstTries === true) {
            $query .= <<<SQL
    AND puzzle_solving_time.first_attempt = true
SQL;
        }

        if ($dateFrom !== null) {
            $query .= <<<SQL
    AND COALESCE(puzzle_solving_time.finished_at, puzzle_solving_time.tracked_at) >= :dateFrom
SQL;
        }

        if ($dateTo !== null) {
            $query .= <<<SQL
    AND COALESCE(puzzle_solving_time.finished_at, puzzle_solving_time.tracked_at) <= :dateTo
SQL;
        }

        $query .= <<<SQL
    ORDER BY seconds_to_solve
SQL;

        $data = $this->database
            ->executeQuery($query, [
                'playerId' => $playerId,
                'dateFrom' => $dateFrom?->format('Y-m-d H:i:s'),
                'dateTo' => $dateTo?->format('Y-m-d H:i:s'),
            ])
            ->fetchAllAssociative();

        return array_map(static function (array $row): SolvedPuzzle {
            /**
             * @var array{
             *     time_id: string,
             *     player_id: string,
             *     player_name: null|string,
             *     player_code: string,
             *     player_country: null|string,
             *     puzzle_id: string,
             *     puzzle_name: string,
             *     puzzle_alternative_name: null|string,
             *     manufacturer_name: string,
             *     puzzle_image: null|string,
             *     time: int,
             *     pieces_count: int,
             *     comment: null|string,
             *     tracked_at: string,
             *     finished_puzzle_photo: null|string,
             *     puzzle_identification_number: null|string,
             *     finished_at: null|string,
             *     first_attempt: bool,
             *     unboxed: bool,
             *     solved_times: int,
             *     competition_id: null|string,
             *     competition_name: null|string,
             *     competition_shortcut: null|string,
             *     competition_slug: null|string,
             *     suspicious: bool,
             * } $row
             */

            return SolvedPuzzle::fromDatabaseRow($row);
        }, $data);
    }

    /**
     * @return array<SolvedPuzzle>
     * @throws PlayerNotFound
     */
    public function duoByPlayerId(
        string $playerId,
        null|DateTimeImmutable $dateFrom = null,
        null|DateTimeImmutable $dateTo = null,
        bool $onlyFirstTries = false,
    ): array {
        if (Uuid::isValid($playerId) === false) {
            throw new PlayerNotFound();
        }

        $this->assertPlayerExists($playerId);

        $query = <<<SQL
WITH filtered_pst_ids AS (
    SELECT id
    FROM puzzle_solving_time
    WHERE
        (team::jsonb -> 'puzzlers') @> jsonb_build_array(jsonb_build_object('player_id', CAST(:playerId AS UUID)))
        AND puzzling_type = 'duo'
SQL;

        if ($dateFrom !== null) {
            $query .= <<<SQL
    AND COALESCE(puzzle_solving_time.finished_at, puzzle_solving_time.tracked_at) >= :dateFrom
SQL;
        }

        if ($dateTo !== null) {
            $query .= <<<SQL
    AND COALESCE(puzzle_solving_time.finished_at, puzzle_solving_time.tracked_at) <= :dateTo
SQL;
        }

        if ($onlyFirstTries === true) {
            $query .= <<<SQL
    AND puzzle_solving_time.first_attempt = true
SQL;
        }

        $query .= <<<SQL
)
SELECT
    pst.id as time_id,
    puzzle.id AS puzzle_id,
    puzzle.name AS puzzle_name,
    puzzle.alternative_name AS puzzle_alternative_name,
    puzzle.image AS puzzle_image,
    pst.seconds_to_solve AS time,
    pst.player_id AS player_id,
    pieces_count,
    finished_puzzle_photo,
    tracked_at,
    finished_at,
    puzzle.identification_number AS puzzle_identification_number,
    pst.comment,
    manufacturer.name AS manufacturer_name,
    pst.team ->> 'team_id' AS team_id,
    first_attempt,
    pst.unboxed,
    competition.id AS competition_id,
    competition.shortcut AS competition_shortcut,
    competition.name AS competition_name,
    competition.slug AS competition_slug,
    pst.suspicious
FROM filtered_pst_ids fids
INNER JOIN puzzle_solving_time pst ON pst.id = fids.id
INNER JOIN puzzle ON puzzle.id = pst.puzzle_id
INNER JOIN manufacturer ON manufacturer.id = puzzle.manufacturer_id
LEFT JOIN competition ON competition.id = pst.competition_id
ORDER BY pst.seconds_to_solve ASC
SQL;

        $data = $this->database
            ->executeQuery($query, [
                'playerId' => $playerId,
                'dateFrom' => $dateFrom?->format('Y-m-d H:i:s'),
                'dateTo' => $dateTo?->format('Y-m-d H:i:s'),
            ])
            ->fetchAllAssociative();

        /** @var array<string> $timeIds */
        $timeIds = array_column($data, 'time_id');

        $players = $this->getTeamPlayers->byIds($timeIds);

        return array_map(static function (array $row) use ($players): SolvedPuzzle {
            /**
             * @var array{
             *     time_id: string,
             *     team_id: null|string,
             *     player_id: string,
             *     player_name: null,
             *     player_code: string,
             *     player_country: null,
             *     puzzle_id: string,
             *     puzzle_name: string,
             *     puzzle_alternative_name: null|string,
             *     manufacturer_name: string,
             *     puzzle_image: null|string,
             *     time: int,
             *     pieces_count: int,
             *     comment: null|string,
             *     finished_puzzle_photo: null|string,
             *     puzzle_identification_number: null|string,
             *     tracked_at: string,
             *     finished_at: null|string,
             *     first_attempt: bool,
             *     unboxed: bool,
             *     competition_id: null|string,
             *     competition_name: null|string,
             *     competition_shortcut: null|string,
             *     competition_slug: null|string,
             *     suspicious: bool,
             * } $row
             */

            $row['players'] = $players[$row['time_id']] ?? null;

            // Dummy placeholder values
            $row['player_name'] = null;
            $row['player_code'] = '';
            $row['player_country'] = null;

            return SolvedPuzzle::fromDatabaseRow($row);
        }, $data);
    }

    /**
     * @return array<SolvedPuzzle>
     * @throws PlayerNotFound
     */
    public function teamByPlayerId(
        string $playerId,
        null|DateTimeImmutable $dateFrom = null,
        null|DateTimeImmutable $dateTo = null,
        bool $onlyFirstTries = false,
    ): array {
        if (Uuid::isValid($playerId) === false) {
            throw new PlayerNotFound();
        }

        $this->assertPlayerExists($playerId);

        $query = <<<SQL
WITH filtered_pst_ids AS (
    SELECT id
    FROM puzzle_solving_time
    WHERE
        (team::jsonb -> 'puzzlers') @> jsonb_build_array(jsonb_build_object('player_id', CAST(:playerId AS UUID)))
        AND puzzling_type = 'team'
SQL;

        if ($dateFrom !== null) {
            $query .= <<<SQL
    AND COALESCE(puzzle_solving_time.finished_at, puzzle_solving_time.tracked_at) >= :dateFrom
SQL;
        }

        if ($dateTo !== null) {
            $query .= <<<SQL
    AND COALESCE(puzzle_solving_time.finished_at, puzzle_solving_time.tracked_at) <= :dateTo
SQL;
        }

        if ($onlyFirstTries === true) {
            $query .= <<<SQL
    AND puzzle_solving_time.first_attempt = true
SQL;
        }

        $query .= <<<SQL
)
SELECT
    pst.id as time_id,
    puzzle.id AS puzzle_id,
    puzzle.name AS puzzle_name,
    puzzle.alternative_name AS puzzle_alternative_name,
    puzzle.image AS puzzle_image,
    pst.seconds_to_solve AS time,
    pst.player_id AS player_id,
    pieces_count,
    finished_puzzle_photo,
    tracked_at,
    finished_at,
    puzzle.identification_number AS puzzle_identification_number,
    pst.comment,
    manufacturer.name AS manufacturer_name,
    pst.team ->> 'team_id' AS team_id,
    first_attempt,
    pst.unboxed,
    competition.id AS competition_id,
    competition.shortcut AS competition_shortcut,
    competition.name AS competition_name,
    competition.slug AS competition_slug,
    pst.suspicious
FROM filtered_pst_ids fids
INNER JOIN puzzle_solving_time pst ON pst.id = fids.id
INNER JOIN puzzle ON puzzle.id = pst.puzzle_id
INNER JOIN manufacturer ON manufacturer.id = puzzle.manufacturer_id
LEFT JOIN competition ON competition.id = pst.competition_id
ORDER BY pst.seconds_to_solve ASC
SQL;

        $data = $this->database
            ->executeQuery($query, [
                'playerId' => $playerId,
                'dateFrom' => $dateFrom?->format('Y-m-d H:i:s'),
                'dateTo' => $dateTo?->format('Y-m-d H:i:s'),
            ])
            ->fetchAllAssociative();

        /** @var array<string> $timeIds */
        $timeIds = array_column($data, 'time_id');

        $players = $this->getTeamPlayers->byIds($timeIds);

        return array_map(static function (array $row) use ($players): SolvedPuzzle {
            /**
             * @var array{
             *     time_id: string,
             *     team_id: null|string,
             *     player_id: string,
             *     player_name: null,
             *     player_code: string,
             *     player_country: null,
             *     puzzle_id: string,
             *     puzzle_name: string,
             *     puzzle_alternative_name: null|string,
             *     manufacturer_name: string,
             *     puzzle_image: null|string,
             *     time: int,
             *     pieces_count: int,
             *     comment: null|string,
             *     finished_puzzle_photo: null|string,
             *     puzzle_identification_number: null|string,
             *     tracked_at: string,
             *     finished_at: null|string,
             *     first_attempt: bool,
             *     unboxed: bool,
             *     competition_id: null|string,
             *     competition_name: null|string,
             *     competition_shortcut: null|string,
             *     competition_slug: null|string,
             *     suspicious: bool,
             * } $row
             */

            $row['players'] = $players[$row['time_id']] ?? null;

            // Dummy placeholder values
            $row['player_name'] = null;
            $row['player_code'] = '';
            $row['player_country'] = null;

            return SolvedPuzzle::fromDatabaseRow($row);
        }, $data);
    }

    /**
     * @return array<SolvedPuzzle>
     * @throws PlayerNotFound
     */
    public function byPlayerIdPuzzleIdAndCategory(string $playerId, string $puzzleId, string $category): array
    {
        return match ($category) {
            'solo' => $this->soloByPlayerIdAndPuzzleId($playerId, $puzzleId),
            'duo' => $this->duoByPlayerIdAndPuzzleId($playerId, $puzzleId),
            'team' => $this->teamByPlayerIdAndPuzzleId($playerId, $puzzleId),
            default => [],
        };
    }

    /**
     * @return array<SolvedPuzzle>
     * @throws PlayerNotFound
     */
    private function soloByPlayerIdAndPuzzleId(string $playerId, string $puzzleId): array
    {
        if (Uuid::isValid($playerId) === false || Uuid::isValid($puzzleId) === false) {
            throw new PlayerNotFound();
        }

        $query = <<<SQL
WITH solved_counts AS (
    SELECT
        puzzle_id,
        COUNT(id) AS solved_times
    FROM puzzle_solving_time
    WHERE puzzling_type = 'solo'
      AND player_id = :playerId
    GROUP BY puzzle_id
)
SELECT
    puzzle_solving_time.id as time_id,
    puzzle.id AS puzzle_id,
    puzzle.name AS puzzle_name,
    puzzle.alternative_name AS puzzle_alternative_name,
    puzzle.image AS puzzle_image,
    puzzle_solving_time.seconds_to_solve AS time,
    puzzle_solving_time.player_id AS player_id,
    pieces_count,
    player.name AS player_name,
    player.code AS player_code,
    player.country AS player_country,
    puzzle.identification_number AS puzzle_identification_number,
    puzzle_solving_time.comment,
    puzzle_solving_time.tracked_at,
    finished_at,
    manufacturer.name AS manufacturer_name,
    puzzle_solving_time.finished_puzzle_photo AS finished_puzzle_photo,
    first_attempt,
    puzzle_solving_time.unboxed,
    solved_counts.solved_times AS solved_times,
    competition.id AS competition_id,
    competition.shortcut AS competition_shortcut,
    competition.name AS competition_name,
    competition.slug AS competition_slug,
    puzzle_solving_time.suspicious
FROM puzzle_solving_time
    INNER JOIN puzzle ON puzzle.id = puzzle_solving_time.puzzle_id
    INNER JOIN player ON puzzle_solving_time.player_id = player.id
    INNER JOIN manufacturer ON manufacturer.id = puzzle.manufacturer_id
    LEFT JOIN solved_counts ON solved_counts.puzzle_id = puzzle_solving_time.puzzle_id
    LEFT JOIN competition ON competition.id = puzzle_solving_time.competition_id
WHERE
    puzzle_solving_time.player_id = :playerId
    AND puzzle_solving_time.puzzle_id = :puzzleId
    AND puzzle_solving_time.puzzling_type = 'solo'
ORDER BY COALESCE(puzzle_solving_time.finished_at, puzzle_solving_time.tracked_at) DESC
SQL;

        $data = $this->database
            ->executeQuery($query, [
                'playerId' => $playerId,
                'puzzleId' => $puzzleId,
            ])
            ->fetchAllAssociative();

        return array_map(static function (array $row): SolvedPuzzle {
            /**
             * @var array{
             *     time_id: string,
             *     player_id: string,
             *     player_name: null|string,
             *     player_code: string,
             *     player_country: null|string,
             *     puzzle_id: string,
             *     puzzle_name: string,
             *     puzzle_alternative_name: null|string,
             *     manufacturer_name: string,
             *     puzzle_image: null|string,
             *     time: int,
             *     pieces_count: int,
             *     comment: null|string,
             *     tracked_at: string,
             *     finished_puzzle_photo: null|string,
             *     puzzle_identification_number: null|string,
             *     finished_at: null|string,
             *     first_attempt: bool,
             *     unboxed: bool,
             *     solved_times: int,
             *     competition_id: null|string,
             *     competition_name: null|string,
             *     competition_shortcut: null|string,
             *     competition_slug: null|string,
             *     suspicious: bool,
             * } $row
             */

            return SolvedPuzzle::fromDatabaseRow($row);
        }, $data);
    }

    /**
     * @return array<SolvedPuzzle>
     * @throws PlayerNotFound
     */
    private function duoByPlayerIdAndPuzzleId(string $playerId, string $puzzleId): array
    {
        if (Uuid::isValid($playerId) === false || Uuid::isValid($puzzleId) === false) {
            throw new PlayerNotFound();
        }

        $query = <<<SQL
WITH filtered_pst_ids AS (
    SELECT id
    FROM puzzle_solving_time
    WHERE
        (team::jsonb -> 'puzzlers') @> jsonb_build_array(jsonb_build_object('player_id', CAST(:playerId AS UUID)))
        AND puzzling_type = 'duo'
        AND puzzle_id = :puzzleId
)
SELECT
    pst.id as time_id,
    puzzle.id AS puzzle_id,
    puzzle.name AS puzzle_name,
    puzzle.alternative_name AS puzzle_alternative_name,
    puzzle.image AS puzzle_image,
    pst.seconds_to_solve AS time,
    pst.player_id AS player_id,
    pieces_count,
    finished_puzzle_photo,
    tracked_at,
    finished_at,
    puzzle.identification_number AS puzzle_identification_number,
    pst.comment,
    manufacturer.name AS manufacturer_name,
    pst.team ->> 'team_id' AS team_id,
    first_attempt,
    pst.unboxed,
    competition.id AS competition_id,
    competition.shortcut AS competition_shortcut,
    competition.name AS competition_name,
    competition.slug AS competition_slug,
    pst.suspicious
FROM filtered_pst_ids fids
INNER JOIN puzzle_solving_time pst ON pst.id = fids.id
INNER JOIN puzzle ON puzzle.id = pst.puzzle_id
INNER JOIN manufacturer ON manufacturer.id = puzzle.manufacturer_id
LEFT JOIN competition ON competition.id = pst.competition_id
ORDER BY COALESCE(pst.finished_at, pst.tracked_at) DESC
SQL;

        $data = $this->database
            ->executeQuery($query, [
                'playerId' => $playerId,
                'puzzleId' => $puzzleId,
            ])
            ->fetchAllAssociative();

        /** @var array<string> $timeIds */
        $timeIds = array_column($data, 'time_id');

        $players = $this->getTeamPlayers->byIds($timeIds);

        return array_map(static function (array $row) use ($players): SolvedPuzzle {
            /**
             * @var array{
             *     time_id: string,
             *     team_id: null|string,
             *     player_id: string,
             *     player_name: null,
             *     player_code: string,
             *     player_country: null,
             *     puzzle_id: string,
             *     puzzle_name: string,
             *     puzzle_alternative_name: null|string,
             *     manufacturer_name: string,
             *     puzzle_image: null|string,
             *     time: int,
             *     pieces_count: int,
             *     comment: null|string,
             *     finished_puzzle_photo: null|string,
             *     puzzle_identification_number: null|string,
             *     tracked_at: string,
             *     finished_at: null|string,
             *     first_attempt: bool,
             *     unboxed: bool,
             *     competition_id: null|string,
             *     competition_name: null|string,
             *     competition_shortcut: null|string,
             *     competition_slug: null|string,
             *     suspicious: bool,
             * } $row
             */

            $row['players'] = $players[$row['time_id']] ?? null;

            // Dummy placeholder values
            $row['player_name'] = null;
            $row['player_code'] = '';
            $row['player_country'] = null;

            return SolvedPuzzle::fromDatabaseRow($row);
        }, $data);
    }

    /**
     * @return array<SolvedPuzzle>
     * @throws PlayerNotFound
     */
    private function teamByPlayerIdAndPuzzleId(string $playerId, string $puzzleId): array
    {
        if (Uuid::isValid($playerId) === false || Uuid::isValid($puzzleId) === false) {
            throw new PlayerNotFound();
        }

        $query = <<<SQL
WITH filtered_pst_ids AS (
    SELECT id
    FROM puzzle_solving_time
    WHERE
        (team::jsonb -> 'puzzlers') @> jsonb_build_array(jsonb_build_object('player_id', CAST(:playerId AS UUID)))
        AND puzzling_type = 'team'
        AND puzzle_id = :puzzleId
)
SELECT
    pst.id as time_id,
    puzzle.id AS puzzle_id,
    puzzle.name AS puzzle_name,
    puzzle.alternative_name AS puzzle_alternative_name,
    puzzle.image AS puzzle_image,
    pst.seconds_to_solve AS time,
    pst.player_id AS player_id,
    pieces_count,
    finished_puzzle_photo,
    tracked_at,
    finished_at,
    puzzle.identification_number AS puzzle_identification_number,
    pst.comment,
    manufacturer.name AS manufacturer_name,
    pst.team ->> 'team_id' AS team_id,
    first_attempt,
    pst.unboxed,
    competition.id AS competition_id,
    competition.shortcut AS competition_shortcut,
    competition.name AS competition_name,
    competition.slug AS competition_slug,
    pst.suspicious
FROM filtered_pst_ids fids
INNER JOIN puzzle_solving_time pst ON pst.id = fids.id
INNER JOIN puzzle ON puzzle.id = pst.puzzle_id
INNER JOIN manufacturer ON manufacturer.id = puzzle.manufacturer_id
LEFT JOIN competition ON competition.id = pst.competition_id
ORDER BY COALESCE(pst.finished_at, pst.tracked_at) DESC
SQL;

        $data = $this->database
            ->executeQuery($query, [
                'playerId' => $playerId,
                'puzzleId' => $puzzleId,
            ])
            ->fetchAllAssociative();

        /** @var array<string> $timeIds */
        $timeIds = array_column($data, 'time_id');

        $players = $this->getTeamPlayers->byIds($timeIds);

        return array_map(static function (array $row) use ($players): SolvedPuzzle {
            /**
             * @var array{
             *     time_id: string,
             *     team_id: null|string,
             *     player_id: string,
             *     player_name: null,
             *     player_code: string,
             *     player_country: null,
             *     puzzle_id: string,
             *     puzzle_name: string,
             *     puzzle_alternative_name: null|string,
             *     manufacturer_name: string,
             *     puzzle_image: null|string,
             *     time: int,
             *     pieces_count: int,
             *     comment: null|string,
             *     finished_puzzle_photo: null|string,
             *     puzzle_identification_number: null|string,
             *     tracked_at: string,
             *     finished_at: null|string,
             *     first_attempt: bool,
             *     unboxed: bool,
             *     competition_id: null|string,
             *     competition_name: null|string,
             *     competition_shortcut: null|string,
             *     competition_slug: null|string,
             *     suspicious: bool,
             * } $row
             */

            $row['players'] = $players[$row['time_id']] ?? null;

            // Dummy placeholder values
            $row['player_name'] = null;
            $row['player_code'] = '';
            $row['player_country'] = null;

            return SolvedPuzzle::fromDatabaseRow($row);
        }, $data);
    }

    /**
     * Count distinct puzzles solved by player (solo or in team)
     *
     * @throws PlayerNotFound
     */
    public function countByPlayerId(string $playerId): int
    {
        if (Uuid::isValid($playerId) === false) {
            throw new PlayerNotFound();
        }

        $query = <<<SQL
SELECT COUNT(DISTINCT puzzle_id)
FROM puzzle_solving_time
WHERE
    player_id = :playerId
    OR (team::jsonb -> 'puzzlers') @> jsonb_build_array(jsonb_build_object('player_id', CAST(:playerId AS UUID)))
SQL;

        /** @var int|false $result */
        $result = $this->database->executeQuery($query, [
            'playerId' => $playerId,
        ])->fetchOne();

        return $result !== false ? (int) $result : 0;
    }

    /**
     * Get a single solved puzzle by puzzle ID and player ID
     *
     * @throws PlayerNotFound
     */
    public function byPuzzleIdAndPlayerId(string $puzzleId, string $playerId): null|SolvedPuzzleOverview
    {
        if (Uuid::isValid($playerId) === false) {
            throw new PlayerNotFound();
        }

        $query = <<<SQL
SELECT
    puzzle.id AS puzzle_id,
    puzzle.name AS puzzle_name,
    puzzle.alternative_name AS puzzle_alternative_name,
    puzzle.identification_number AS puzzle_identification_number,
    puzzle.ean AS ean,
    puzzle.pieces_count,
    manufacturer.name AS manufacturer_name,
    puzzle.image,
    puzzle_solving_time.finished_at
FROM puzzle_solving_time
    INNER JOIN puzzle ON puzzle.id = puzzle_solving_time.puzzle_id
    INNER JOIN manufacturer ON manufacturer.id = puzzle.manufacturer_id
WHERE puzzle.id = :puzzleId
  AND (
    puzzle_solving_time.player_id = :playerId
    OR (puzzle_solving_time.team::jsonb -> 'puzzlers') @> jsonb_build_array(jsonb_build_object('player_id', CAST(:playerId AS UUID)))
  )
ORDER BY COALESCE(puzzle_solving_time.finished_at, puzzle_solving_time.tracked_at) DESC
LIMIT 1
SQL;

        $data = $this->database
            ->executeQuery($query, [
                'puzzleId' => $puzzleId,
                'playerId' => $playerId,
            ])
            ->fetchAssociative();

        if ($data === false) {
            return null;
        }

        /**
         * @var array{
         *     puzzle_id: string,
         *     puzzle_name: string,
         *     puzzle_alternative_name: null|string,
         *     puzzle_identification_number: null|string,
         *     ean: null|string,
         *     pieces_count: int,
         *     manufacturer_name: null|string,
         *     image: null|string,
         *     finished_at: null|string,
         * } $data
         */

        return new SolvedPuzzleOverview(
            puzzleId: $data['puzzle_id'],
            puzzleName: $data['puzzle_name'],
            puzzleAlternativeName: $data['puzzle_alternative_name'],
            puzzleIdentificationNumber: $data['puzzle_identification_number'],
            ean: $data['ean'],
            piecesCount: $data['pieces_count'],
            manufacturerName: $data['manufacturer_name'],
            image: $data['image'],
            finishedAt: $data['finished_at'] !== null ? new DateTimeImmutable($data['finished_at']) : null,
        );
    }

    /**
     * Get all solved puzzles for list display, ordered alphabetically by puzzle name
     *
     * @return array<SolvedPuzzleOverview>
     * @throws PlayerNotFound
     */
    public function allByPlayerId(string $playerId): array
    {
        if (Uuid::isValid($playerId) === false) {
            throw new PlayerNotFound();
        }

        $query = <<<SQL
SELECT DISTINCT ON (puzzle.name, puzzle.id)
    puzzle.id AS puzzle_id,
    puzzle.name AS puzzle_name,
    puzzle.alternative_name AS puzzle_alternative_name,
    puzzle.identification_number AS puzzle_identification_number,
    puzzle.ean AS ean,
    puzzle.pieces_count,
    manufacturer.name AS manufacturer_name,
    puzzle.image,
    puzzle_solving_time.finished_at
FROM puzzle_solving_time
    INNER JOIN puzzle ON puzzle.id = puzzle_solving_time.puzzle_id
    INNER JOIN manufacturer ON manufacturer.id = puzzle.manufacturer_id
WHERE
    puzzle_solving_time.player_id = :playerId
    OR (puzzle_solving_time.team::jsonb -> 'puzzlers') @> jsonb_build_array(jsonb_build_object('player_id', CAST(:playerId AS UUID)))
ORDER BY puzzle.name ASC, puzzle.id, COALESCE(puzzle_solving_time.finished_at, puzzle_solving_time.tracked_at) DESC
SQL;

        $data = $this->database
            ->executeQuery($query, [
                'playerId' => $playerId,
            ])
            ->fetchAllAssociative();

        return array_map(static function (array $row): SolvedPuzzleOverview {
            /**
             * @var array{
             *     puzzle_id: string,
             *     puzzle_name: string,
             *     puzzle_alternative_name: null|string,
             *     puzzle_identification_number: null|string,
             *     ean: null|string,
             *     pieces_count: int,
             *     manufacturer_name: null|string,
             *     image: null|string,
             *     finished_at: null|string,
             * } $row
             */

            return new SolvedPuzzleOverview(
                puzzleId: $row['puzzle_id'],
                puzzleName: $row['puzzle_name'],
                puzzleAlternativeName: $row['puzzle_alternative_name'],
                puzzleIdentificationNumber: $row['puzzle_identification_number'],
                ean: $row['ean'],
                piecesCount: $row['pieces_count'],
                manufacturerName: $row['manufacturer_name'],
                image: $row['image'],
                finishedAt: $row['finished_at'] !== null ? new DateTimeImmutable($row['finished_at']) : null,
            );
        }, $data);
    }

    /**
     * @throws PlayerNotFound
     */
    private function assertPlayerExists(string $playerId): void
    {
        $exists = $this->database->executeQuery(
            'SELECT 1 FROM player WHERE id = :playerId',
            ['playerId' => $playerId],
        )->fetchOne();

        if ($exists === false) {
            throw new PlayerNotFound();
        }
    }
}
