<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\Results\PuzzleOverview;

readonly final class GetPuzzleOverview
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @throws PuzzleNotFound
     */
    public function byId(string $puzzleId): PuzzleOverview
    {
        if (Uuid::isValid($puzzleId) === false) {
            throw new PuzzleNotFound();
        }

        $query = <<<SQL
SELECT
    puzzle.id AS puzzle_id,
    puzzle.name AS puzzle_name,
    puzzle.image AS puzzle_image,
    puzzle.alternative_name AS puzzle_alternative_name,
    puzzle.pieces_count,
    puzzle.is_available,
    manufacturer.name AS manufacturer_name,
    COUNT(puzzle_solving_time.id) AS solved_times,
    AVG(puzzle_solving_time.seconds_to_solve) AS average_time,
    MIN(puzzle_solving_time.seconds_to_solve) AS fastest_time
FROM puzzle
LEFT JOIN puzzle_solving_time ON puzzle_solving_time.puzzle_id = puzzle.id
INNER JOIN manufacturer ON puzzle.manufacturer_id = manufacturer.id
WHERE puzzle.id = :puzzleId
GROUP BY puzzle.name, puzzle.pieces_count, manufacturer.name, puzzle.alternative_name, puzzle.id
SQL;

        /**
         * @var null|array{
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
         * } $row
         */
        $row = $this->database
            ->executeQuery($query, [
                'puzzleId' => $puzzleId,
            ])
            ->fetchAssociative();

        if (is_array($row) === false) {
            throw new PuzzleNotFound();
        }

        return PuzzleOverview::fromDatabaseRow($row);
    }
}
