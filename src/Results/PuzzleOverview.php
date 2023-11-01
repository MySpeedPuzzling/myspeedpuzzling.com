<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

readonly final class PuzzleOverview
{
    public function __construct(
        public string $puzzleName,
        public string $puzzleAlternativeName,
        public string $manufacturerName,
        public int $piecesCount,
        public int $averageTime,
        public int $fastestTime,
        public int $solvedCount,
    ) {
    }

    /**
     * @param array{
     *     puzzle_name: string,
     *     puzzle_alternative_name: string,
     *     manufacturer_name: string,
     *     pieces_count: int,
     *     average_time: string,
     *     fastest_time: int,
     *     solved_count: int,
     * } $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            puzzleName: $row['puzzle_name'],
            puzzleAlternativeName: $row['puzzle_alternative_name'],
            manufacturerName: $row['manufacturer_name'],
            piecesCount: $row['pieces_count'],
            averageTime: (int) $row['average_time'],
            fastestTime: $row['fastest_time'],
            solvedCount: $row['solved_count'],
        );
    }
}
