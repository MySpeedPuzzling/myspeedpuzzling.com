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
        [$countsAsSolve, $piecesPuzzled, $secondsPuzzled] = self::puzzledFragments();

        $query = <<<SQL
SELECT
    SUM({$secondsPuzzled}) AS total_seconds,
    COUNT(puzzle_solving_time.id) FILTER (WHERE {$countsAsSolve}) AS solved_puzzles_count,
    SUM({$piecesPuzzled}) AS total_pieces
FROM puzzle_solving_time
INNER JOIN puzzle ON puzzle.id = puzzle_solving_time.puzzle_id
WHERE puzzle_solving_time.suspicious = false
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

    public function countPlayers(): int
    {
        $query = <<<SQL
SELECT COUNT(id)
FROM player
WHERE user_id IS NOT NULL
SQL;

        $count = $this->database
            ->executeQuery($query)
            ->fetchOne();

        return is_numeric($count) ? (int) $count : 0;
    }

    public function globallyInMonth(int $month, int $year): GlobalStatistics
    {
        $startDate = sprintf('%04d-%02d-01', $year, $month);
        $endDate = $month === 12
            ? sprintf('%04d-01-01', $year + 1)
            : sprintf('%04d-%02d-01', $year, $month + 1);

        [$countsAsSolve, $piecesPuzzled, $secondsPuzzled] = self::puzzledFragments();

        $query = <<<SQL
SELECT
    SUM({$secondsPuzzled}) AS total_seconds,
    COUNT(puzzle_solving_time.id) FILTER (WHERE {$countsAsSolve}) AS solved_puzzles_count,
    SUM({$piecesPuzzled}) AS total_pieces
FROM puzzle_solving_time
INNER JOIN puzzle ON puzzle.id = puzzle_solving_time.puzzle_id
WHERE puzzle_solving_time.tracked_at >= :startDate
    AND puzzle_solving_time.tracked_at < :endDate
    AND puzzle_solving_time.suspicious = false
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

    /**
     * An unfinished result is not a solve, but its pieces placed and time spent were puzzled (CountsAsSolve).
     *
     * @return array{string, string, string}
     */
    private static function puzzledFragments(): array
    {
        return [
            CountsAsSolve::condition('puzzle_solving_time'),
            CountsAsSolve::piecesPuzzled('puzzle_solving_time', 'puzzle.pieces_count'),
            CountsAsSolve::secondsPuzzled('puzzle_solving_time'),
        ];
    }
}
