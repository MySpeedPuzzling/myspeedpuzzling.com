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
        $startDate = sprintf('%04d-%02d-01', $year, $month);
        $endDate = $month === 12
            ? sprintf('%04d-01-01', $year + 1)
            : sprintf('%04d-%02d-01', $year, $month + 1);

        $query = <<<SQL
SELECT
    SUM(puzzle_solving_time.seconds_to_solve) AS total_seconds,
    COUNT(puzzle_solving_time.id) AS solved_puzzles_count,
    SUM(puzzle.pieces_count) AS total_pieces
FROM puzzle_solving_time
INNER JOIN puzzle ON puzzle.id = puzzle_solving_time.puzzle_id
WHERE puzzle_solving_time.tracked_at >= :startDate
    AND puzzle_solving_time.tracked_at < :endDate
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
                'startDate' => $startDate,
                'endDate' => $endDate,
            ])
            ->fetchAssociative();

        return GlobalStatistics::fromDatabaseRow($row);
    }
}
