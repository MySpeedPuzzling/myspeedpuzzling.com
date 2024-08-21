<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\Results\PlayerRanking;

readonly final class GetRanking
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @throws PlayerNotFound
     * @return array<string, PlayerRanking>
     */
    public function allForPlayer(string $playerId): array
    {
        if (Uuid::isValid($playerId) === false) {
            throw new PlayerNotFound();
        }

        $query = <<<SQL
WITH BestTimes AS (
    SELECT
        puzzle_id,
        player_id,
        MIN(seconds_to_solve) as best_time
    FROM
        puzzle_solving_time
    WHERE team IS NULL
    GROUP BY
        puzzle_id, player_id
),
RankedTimes AS (
    SELECT
        puzzle_id,
        player_id,
        best_time,
        RANK() OVER (PARTITION BY puzzle_id ORDER BY best_time ASC) as rank,
        COUNT(player_id) OVER (PARTITION BY puzzle_id) as total_players
    FROM
        BestTimes
)
SELECT
    player_id,
    rank,
    total_players,
    best_time AS time,
    puzzle.id AS puzzle_id,
    puzzle.name AS puzzle_name,
    puzzle.alternative_name AS puzzle_alternative_name,
    puzzle.pieces_count,
    puzzle.image AS puzzle_image,
    manufacturer.name AS manufacturer_name
FROM
    RankedTimes
INNER JOIN puzzle ON puzzle.id = RankedTimes.puzzle_id
INNER JOIN manufacturer ON manufacturer.id = puzzle.manufacturer_id
WHERE
    player_id = :playerId
ORDER BY rank
SQL;

        $data = $this->database
            ->executeQuery($query, [
                'playerId' => $playerId,
            ])
            ->fetchAllAssociative();

        $ranking = [];

        foreach ($data as $row) {
            /**
             * @var array{
             *     player_id: string,
             *     rank: int,
             *     total_players: int,
             *     time: int,
             *     puzzle_id: string,
             *     puzzle_name: string,
             *     puzzle_alternative_name: null|string,
             *     pieces_count: int,
             *     puzzle_image: null|string,
             *     manufacturer_name: string,
             * } $row
             */

            $ranking[$row['puzzle_id']] = PlayerRanking::fromDatabaseRow($row);
        }

        return $ranking;
    }

    /**
     * @throws PlayerNotFound
     * @throws PuzzleNotFound
     */
    public function ofPuzzleForPlayer(string $puzzleId, string $playerId): PlayerRanking
    {
        if (Uuid::isValid($playerId) === false) {
            throw new PlayerNotFound();
        }

        if (Uuid::isValid($puzzleId) === false) {
            throw new PuzzleNotFound();
        }

        $query = <<<SQL
WITH BestTimes AS (
    SELECT
        puzzle_id,
        player_id,
        MIN(seconds_to_solve) as best_time
    FROM
        puzzle_solving_time
    WHERE team IS NULL
        AND puzzle_id = :puzzleId
    GROUP BY
        puzzle_id, player_id
),
RankedTimes AS (
    SELECT
        puzzle_id,
        player_id,
        best_time,
        RANK() OVER (PARTITION BY puzzle_id ORDER BY best_time ASC) as rank,
        COUNT(player_id) OVER (PARTITION BY puzzle_id) as total_players
    FROM
        BestTimes
)
SELECT
    player_id,
    rank,
    total_players,
    best_time AS time,
    puzzle.id AS puzzle_id,
    puzzle.name AS puzzle_name,
    puzzle.alternative_name AS puzzle_alternative_name,
    puzzle.pieces_count,
    puzzle.image AS puzzle_image,
    manufacturer.name AS manufacturer_name
FROM
    RankedTimes
INNER JOIN puzzle ON puzzle.id = RankedTimes.puzzle_id
INNER JOIN manufacturer ON manufacturer.id = puzzle.manufacturer_id
WHERE
    player_id = :playerId
ORDER BY rank
SQL;

        /**
         * @var array{
         *     player_id: string,
         *     rank: int,
         *     total_players: int,
         *     time: int,
         *     puzzle_id: string,
         *     puzzle_name: string,
         *     puzzle_alternative_name: null|string,
         *     pieces_count: int,
         *     puzzle_image: null|string,
         *     manufacturer_name: string,
         * } $row
         */
        $row = $this->database
            ->executeQuery($query, [
                'playerId' => $playerId,
                'puzzleId' => $puzzleId,
            ])
            ->fetchAssociative();

        return PlayerRanking::fromDatabaseRow($row);
    }
}
