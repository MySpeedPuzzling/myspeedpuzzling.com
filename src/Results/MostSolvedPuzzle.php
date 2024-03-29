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
        public int $averageTimeSolo,
        public int $fastestTimeSolo,
        public null|string $puzzleImage,
        public string $manufacturerName,
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
     *     average_time_solo: null|string,
     *     fastest_time_solo: null|int,
     *     manufacturer_name: string,
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
            averageTimeSolo: (int) ($row['average_time_solo'] ?? 0),
            fastestTimeSolo: $row['fastest_time_solo'] ?? 0,
            puzzleImage: $row['puzzle_image'],
            manufacturerName: $row['manufacturer_name'],
        );
    }
}
