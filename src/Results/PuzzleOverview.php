<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

readonly final class PuzzleOverview
{
    public function __construct(
        public string $puzzleId,
        public string $puzzleName,
        public string $puzzleAlternativeName,
        public string $manufacturerName,
        public int $piecesCount,
        public int $averageTime,
        public int $fastestTime,
        public int $solvedTimes,
    ) {
    }

    /**
     * @param array{
     *     puzzle_id: string,
     *     puzzle_name: string,
     *     puzzle_alternative_name: string,
     *     manufacturer_name: string,
     *     pieces_count: int,
     *     average_time: string,
     *     fastest_time: int,
     *     solved_times: int,
     * } $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            puzzleId: $row['puzzle_id'],
            puzzleName: $row['puzzle_name'],
            puzzleAlternativeName: $row['puzzle_alternative_name'],
            manufacturerName: $row['manufacturer_name'],
            piecesCount: $row['pieces_count'],
            averageTime: (int) $row['average_time'],
            fastestTime: $row['fastest_time'],
            solvedTimes: $row['solved_times'],
        );
    }
}
