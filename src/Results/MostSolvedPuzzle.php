<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

readonly final class MostSolvedPuzzle
{
    public function __construct(
        public int $solvedCount,
        public string $puzzleName,
        public int $piecesCount,
        public int $averageTime,
        public int $fastestTime,
    ) {
    }

    /**
     * @param array{
     *     solved_count: int,
     *     puzzle_name: string,
     *     pieces_count: int,
     *     average_time: string,
     *     fastest_time: int,
     * } $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            solvedCount: $row['solved_count'],
            puzzleName: $row['puzzle_name'],
            piecesCount: $row['pieces_count'],
            averageTime: (int) $row['average_time'],
            fastestTime: $row['fastest_time'],
        );
    }
}
