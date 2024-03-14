<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\SolvedPuzzle;

readonly final class GetFastestPairs
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return array<SolvedPuzzle>
     */
    public function perPiecesCount(int $piecesCount, int $howManyPlayers): array
    {
        $query = <<<SQL
SELECT
    puzzle.id AS puzzle_id,
    puzzle.name AS puzzle_name,
    puzzle.alternative_name AS puzzle_alternative_name,
    puzzle.image AS puzzle_image,
    pieces_count,
    comment,
    tracked_at,
    finished_at,
    finished_puzzle_photo,
    puzzle_solving_time.seconds_to_solve AS time,
    player.name AS player_name,
    player.country AS player_country,
    player.id AS player_id,
    manufacturer.name AS manufacturer_name,
    puzzle.identification_number AS puzzle_identification_number,
    puzzle_solving_time AS time_id,
    puzzle_solving_time.team ->> 'team_id' AS team_id,
    JSON_AGG(
        JSON_BUILD_OBJECT(
            'player_id', player_elem ->> 'player_id',
            'player_name', COALESCE(p.name, player_elem ->> 'player_name'),
            'player_country', p.country
        )
    ) AS players
FROM puzzle_solving_time
INNER JOIN puzzle ON puzzle.id = puzzle_solving_time.puzzle_id
INNER JOIN player ON puzzle_solving_time.player_id = player.id
INNER JOIN manufacturer ON manufacturer.id = puzzle.manufacturer_id,
LATERAL json_array_elements(puzzle_solving_time.team -> 'puzzlers') AS player_elem
LEFT JOIN player p ON p.id = (player_elem ->> 'player_id')::UUID
WHERE puzzle.pieces_count = :piecesCount
    AND puzzle_solving_time.team IS NOT NULL
    AND json_array_length(team -> 'puzzlers') = 2
GROUP BY puzzle.id, player.id, manufacturer.id, puzzle_solving_time.id
ORDER BY time ASC
LIMIT :howManyPlayers
SQL;

        $data = $this->database
            ->executeQuery($query, [
                'piecesCount' => $piecesCount,
                'howManyPlayers' => $howManyPlayers,
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
             *     finished_puzzle_photo: null|string,
             *     tracked_at: string,
             *     pieces_count: int,
             *     comment: null|string,
             *     puzzle_identification_number: null|string,
             *     players: null|string,
             *     finished_at: string,
             * } $row
             */

            return SolvedPuzzle::fromDatabaseRow($row);
        }, $data);
    }
}
