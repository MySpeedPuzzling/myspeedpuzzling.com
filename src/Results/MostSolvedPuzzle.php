<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

readonly final class MostSolvedPuzzle
{
    public function __construct(
        public string $puzzleId,
        public string $puzzleName,
        public null|string $puzzleAlternativeName,
        public int $solvedTimes,
        public int $piecesCount,
        public int $averageTime,
        public int $fastestTime,
        public null|string $puzzleImage,
    ) {
    }

    /**
     * @param array{
     *     puzzle_id: string,
     *     puzzle_name: string,
     *     puzzle_alternative_name: null|string,
     *     puzzle_image: null|string,
     *     solved_times: int,
     *     pieces_count: int,
     *     average_time: string,
     *     fastest_time: int,
     * } $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            puzzleId: $row['puzzle_id'],
            puzzleName: $row['puzzle_name'],
            puzzleAlternativeName: $row['puzzle_alternative_name'],
            solvedTimes: $row['solved_times'],
            piecesCount: $row['pieces_count'],
            averageTime: (int) $row['average_time'],
            fastestTime: $row['fastest_time'],
            puzzleImage: $row['puzzle_image'],
        );
    }
}
