<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\SolvedPuzzle;

readonly final class GetFastestPlayers
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return array<SolvedPuzzle>
     */
    public function perPiecesCount(int $piecesCount, int $limit): array
    {
        $query = <<<SQL
WITH FastestTimes AS (
    SELECT
        player_id,
        puzzle_id,
        MIN(seconds_to_solve) AS min_seconds_to_solve
    FROM puzzle_solving_time
    INNER JOIN puzzle ON puzzle.id = puzzle_solving_time.puzzle_id
    INNER JOIN player ON player.id = puzzle_solving_time.player_id
    WHERE team IS NULL AND puzzle.pieces_count = :piecesCount AND player.name IS NOT NULL
    GROUP BY player_id, puzzle_id
    ORDER BY min_seconds_to_solve
    LIMIT :limit
)
SELECT
    puzzle.id AS puzzle_id,
    puzzle.name AS puzzle_name,
    puzzle.alternative_name AS puzzle_alternative_name,
    puzzle.image AS puzzle_image,
    puzzle.pieces_count,
    puzzle_solving_time.comment,
    puzzle_solving_time.tracked_at,
    puzzle_solving_time.finished_at,
    puzzle_solving_time.finished_puzzle_photo,
    FastestTimes.min_seconds_to_solve AS time,
    player.name AS player_name,
    player.country AS player_country,
    player.id AS player_id,
    COUNT(puzzle_solving_time.puzzle_id) AS solved_times,
    manufacturer.name AS manufacturer_name,
    puzzle_solving_time.id AS time_id,
    puzzle.identification_number AS puzzle_identification_number,
    puzzle_solving_time.first_attempt
FROM FastestTimes
INNER JOIN puzzle_solving_time ON FastestTimes.player_id = puzzle_solving_time.player_id AND FastestTimes.puzzle_id = puzzle_solving_time.puzzle_id AND FastestTimes.min_seconds_to_solve = puzzle_solving_time.seconds_to_solve
INNER JOIN puzzle ON puzzle.id = FastestTimes.puzzle_id
INNER JOIN player ON FastestTimes.player_id = player.id
INNER JOIN manufacturer ON manufacturer.id = puzzle.manufacturer_id
GROUP BY player.id, puzzle.id, manufacturer.id, puzzle_solving_time.id, FastestTimes.min_seconds_to_solve
ORDER BY FastestTimes.min_seconds_to_solve
SQL;

        $data = $this->database
            ->executeQuery($query, [
                'piecesCount' => $piecesCount,
                'limit' => $limit,
            ])
            ->fetchAllAssociative();

        return array_map(static function(array $row): SolvedPuzzle {
            /** @var array{
             *     puzzle_id: string,
             *     puzzle_name: string,
             *     puzzle_alternative_name: null|string,
             *     puzzle_image: null|string,
             *     time: int,
             *     player_name: string,
             *     player_country: null|string,
             *     player_id: string,
             *     solved_times: int,
             *     manufacturer_name: string,
             *     time_id: string,
             *     solved_times: int,
             *     finished_puzzle_photo: null|string,
             *     tracked_at: string,
             *     pieces_count: int,
             *     comment: null|string,
             *     puzzle_identification_number: null|string,
             *     finished_at: string,
             *     first_attempt: bool,
             * } $row
             */

            return SolvedPuzzle::fromDatabaseRow($row);
        }, $data);
    }
}
