<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\GlobalStatistics;

readonly final class GetStatistics
{
    public function __construct(
        private Connection $database,
    ) {
    }

    public function globally(): GlobalStatistics
    {
        $query = <<<SQL
SELECT
    SUM(puzzle_solving_time.seconds_to_solve) AS total_seconds,
    COUNT(puzzle_solving_time.id) AS solved_puzzles_count,
    SUM(puzzle.pieces_count) AS total_pieces
FROM puzzle_solving_time
INNER JOIN puzzle ON puzzle.id = puzzle_solving_time.puzzle_id
SQL;

        /**
         * @var array{
         *     total_seconds: null|int,
         *     total_pieces: null|int,
         *     solved_puzzles_count: null|int,
         * } $row
         */
        $row = $this->database
            ->executeQuery($query)
            ->fetchAssociative();

        return GlobalStatistics::fromDatabaseRow($row);
    }

    public function globallyInMonth(int $month, int $year): GlobalStatistics
    {
        $query = <<<SQL
SELECT
    SUM(puzzle_solving_time.seconds_to_solve) AS total_seconds,
    COUNT(puzzle_solving_time.id) AS solved_puzzles_count,
    SUM(puzzle.pieces_count) AS total_pieces
FROM puzzle_solving_time
INNER JOIN puzzle ON puzzle.id = puzzle_solving_time.puzzle_id
WHERE EXTRACT(MONTH FROM puzzle_solving_time.tracked_at) = :month
    AND EXTRACT(YEAR FROM puzzle_solving_time.tracked_at) = :year
SQL;

        /**
         * @var array{
         *     total_seconds: null|int,
         *     total_pieces: null|int,
         *     solved_puzzles_count: null|int,
         * } $row
         */
        $row = $this->database
            ->executeQuery($query, [
                'month' => $month,
                'year' => $year,
            ])
            ->fetchAssociative();

        return GlobalStatistics::fromDatabaseRow($row);
    }
}
