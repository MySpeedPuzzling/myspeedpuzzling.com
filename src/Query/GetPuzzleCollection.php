<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\PuzzleOverview;

readonly final class GetPuzzleCollection
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return array<string, PuzzleOverview>
     */
    public function forPlayer(string $playerId): array
    {
        return $this->forPlayerInFolder($playerId, null);
    }

    /**
     * @return array<string, PuzzleOverview>
     */
    public function forPlayerInFolder(string $playerId, null|string $folderId): array
    {
        $folderCondition = $folderId === null 
            ? 'player_puzzle_collection.folder_id IS NULL'
            : 'player_puzzle_collection.folder_id = :folderId';

        $query = <<<SQL
SELECT
    puzzle.id AS puzzle_id,
    puzzle.name AS puzzle_name,
    puzzle.image AS puzzle_image,
    puzzle.alternative_name AS puzzle_alternative_name,
    puzzle.pieces_count,
    puzzle.is_available,
    puzzle.approved AS puzzle_approved,
    manufacturer.name AS manufacturer_name,
    manufacturer.id AS manufacturer_id,
    ean AS puzzle_ean,
    puzzle.identification_number AS puzzle_identification_number,
    player_puzzle_collection.notes,
    player_puzzle_collection.added_at,
    player_puzzle_collection.lent_to_id,
    player_puzzle_collection.lent_at,
    lent_to_player.name AS lent_to_player_name,
    COUNT(puzzle_solving_time.id) AS solved_times,
    AVG(CASE WHEN team IS NULL THEN seconds_to_solve END) AS average_time_solo,
    MIN(CASE WHEN team IS NULL THEN seconds_to_solve END) AS fastest_time_solo,
    AVG(CASE WHEN json_array_length(team->'puzzlers') = 2 THEN seconds_to_solve END) AS average_time_duo,
    MIN(CASE WHEN json_array_length(team->'puzzlers') = 2 THEN seconds_to_solve END) AS fastest_time_duo,
    AVG(CASE WHEN json_array_length(team->'puzzlers') > 2 THEN seconds_to_solve END) AS average_time_team,
    MIN(CASE WHEN json_array_length(team->'puzzlers') > 2 THEN seconds_to_solve END) AS fastest_time_team
FROM player_puzzle_collection
INNER JOIN puzzle ON player_puzzle_collection.puzzle_id = puzzle.id
LEFT JOIN puzzle_solving_time ON puzzle_solving_time.puzzle_id = puzzle.id
INNER JOIN manufacturer ON puzzle.manufacturer_id = manufacturer.id
LEFT JOIN player lent_to_player ON player_puzzle_collection.lent_to_id = lent_to_player.id
WHERE player_puzzle_collection.player_id = :playerId
AND {$folderCondition}
GROUP BY puzzle.name, puzzle.pieces_count, manufacturer.name, manufacturer.id, puzzle.alternative_name, puzzle.id, 
         player_puzzle_collection.notes, player_puzzle_collection.added_at, player_puzzle_collection.lent_to_id, 
         player_puzzle_collection.lent_at, lent_to_player.name
ORDER BY COALESCE(puzzle.alternative_name, puzzle.name) ASC, manufacturer_name ASC, pieces_count ASC
SQL;

        $parameters = ['playerId' => $playerId];
        if ($folderId !== null) {
            $parameters['folderId'] = $folderId;
        }

        $data = $this->database
            ->executeQuery($query, $parameters)
            ->fetchAllAssociative();

        /** @var array<string, PuzzleOverview> $results */
        $results = [];

        foreach ($data as $row) {
            /**
             * @var array{
             *     puzzle_id: string,
             *     puzzle_name: string,
             *     puzzle_image: null|string,
             *     puzzle_alternative_name: null|string,
             *     puzzle_approved: bool,
             *     manufacturer_name: string,
             *     manufacturer_id: string,
             *     pieces_count: int,
             *     average_time_solo: null|string,
             *     fastest_time_solo: null|int,
             *     average_time_duo: null|string,
             *     fastest_time_duo: null|int,
             *     average_time_team: null|string,
             *     fastest_time_team: null|int,
             *     solved_times: int,
             *     is_available: bool,
             *     puzzle_ean: null|string,
             *     puzzle_identification_number: null|string,
             * } $row
             */

            $results[$row['puzzle_id']] = PuzzleOverview::fromDatabaseRow($row);
        }

        return $results;
    }
}
