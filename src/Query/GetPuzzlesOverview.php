<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\PuzzleOverview;

readonly final class GetPuzzlesOverview
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return array<PuzzleOverview>
     */
    public function allApprovedOrAddedByPlayer(null|string $playerId): array
    {
        $query = <<<SQL
SELECT
    puzzle.id AS puzzle_id,
    puzzle.name AS puzzle_name,
    puzzle.image AS puzzle_image,
    puzzle.alternative_name AS puzzle_alternative_name,
    puzzle.pieces_count,
    puzzle.is_available,
    manufacturer.name AS manufacturer_name,
    ean AS puzzle_ean,
    puzzle.identification_number AS puzzle_identification_number,
    COUNT(puzzle_solving_time.id) AS solved_times,
    AVG(puzzle_solving_time.seconds_to_solve) AS average_time,
    MIN(puzzle_solving_time.seconds_to_solve) AS fastest_time
FROM puzzle
LEFT JOIN puzzle_solving_time ON puzzle_solving_time.puzzle_id = puzzle.id
INNER JOIN manufacturer ON puzzle.manufacturer_id = manufacturer.id
WHERE
    puzzle.approved = true
    OR puzzle.added_by_user_id = :playerId
GROUP BY puzzle.name, puzzle.pieces_count, manufacturer.name, puzzle.alternative_name, puzzle.id
ORDER BY COALESCE(puzzle.alternative_name, puzzle.name) ASC, manufacturer_name ASC, pieces_count ASC
SQL;

        $data = $this->database
            ->executeQuery($query, [
                'playerId' => $playerId,
            ])
            ->fetchAllAssociative();

        return array_map(static function(array $row): PuzzleOverview {
            /**
             * @var array{
             *     puzzle_id: string,
             *     puzzle_name: string,
             *     puzzle_image: null|string,
             *     puzzle_alternative_name: null|string,
             *     manufacturer_name: string,
             *     pieces_count: int,
             *     average_time: null|string,
             *     fastest_time: null|int,
             *     solved_times: int,
             *     is_available: bool,
             *     puzzle_ean: null|string,
             *     puzzle_identification_number: null|string
             * } $row
             */

            return PuzzleOverview::fromDatabaseRow($row);
        }, $data);
    }
}
