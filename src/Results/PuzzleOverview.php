<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

readonly final class PuzzleOverview
{
    public function __construct(
        public string $puzzleId,
        public string $puzzleName,
        public null|string $puzzleAlternativeName,
        public bool $puzzleApproved,
        public string $manufacturerId,
        public string $manufacturerName,
        public int $piecesCount,
        public int $averageTimeSolo,
        public int $fastestTimeSolo,
        public int $averageTimeDuo,
        public int $fastestTimeDuo,
        public int $averageTimeTeam,
        public int $fastestTimeTeam,
        public int $solvedTimes,
        public null|string $puzzleImage,
        public bool $isAvailable,
        public null|string $puzzleEan,
        public null|string $puzzleIdentificationNumber,
    ) {
    }

    /**
     * @param array{
     *     puzzle_id: string,
     *     puzzle_name: string,
     *     puzzle_image: null|string,
     *     puzzle_alternative_name: null|string,
     *     puzzle_approved: bool,
     *     manufacturer_id: string,
     *     manufacturer_name: string,
     *     pieces_count: int,
     *     average_time_solo: null|string,
     *     fastest_time_solo: null|int,
     *     average_time_duo: null|string,
     *     fastest_time_duo: null|int,
     *     average_time_team: null|string,
     *     fastest_time_team: null|int,
     *     solved_times: int,
     *     is_available: bool,
     *     puzzle_ean: null|string,
     *     puzzle_identification_number: null|string,
     * } $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            puzzleId: $row['puzzle_id'],
            puzzleName: $row['puzzle_name'],
            puzzleAlternativeName: $row['puzzle_alternative_name'],
            puzzleApproved: $row['puzzle_approved'],
            manufacturerId: $row['manufacturer_id'],
            manufacturerName: $row['manufacturer_name'],
            piecesCount: $row['pieces_count'],
            averageTimeSolo: (int) $row['average_time_solo'],
            fastestTimeSolo: (int) $row['fastest_time_solo'],
            averageTimeDuo: (int) $row['average_time_duo'],
            fastestTimeDuo: (int) $row['fastest_time_duo'],
            averageTimeTeam: (int) $row['average_time_team'],
            fastestTimeTeam: (int) $row['fastest_time_team'],
            solvedTimes: $row['solved_times'],
            puzzleImage: $row['puzzle_image'],
            isAvailable: $row['is_available'],
            puzzleEan: $row['puzzle_ean'],
            puzzleIdentificationNumber: $row['puzzle_identification_number'],
        );
    }
}
