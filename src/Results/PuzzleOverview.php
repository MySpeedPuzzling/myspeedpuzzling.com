<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

readonly final class PuzzleOverview
{
    public function __construct(
        public string $puzzleId,
        public string $puzzleName,
        public null|string $puzzleAlternativeName,
        public string $manufacturerName,
        public int $piecesCount,
        public null|int $averageTime,
        public null|int $fastestTime,
        public int $solvedTimes,
        public null|string $puzzleImage,
        public bool $isAvailable,
    ) {
    }

    /**
     * @param array{
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
            puzzleImage: $row['puzzle_image'],
            isAvailable: $row['is_available'],
        );
    }
}
