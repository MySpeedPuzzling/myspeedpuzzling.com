<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

readonly final class GlobalStatistics
{
    public function __construct(
        public int $totalSeconds,
        public int $totalPieces,
        public int $solvedPuzzlesCount,
    ) {
    }

    /**
     * @param array{
     *     total_seconds: int,
     *     total_pieces: int,
     *     solved_puzzles_count: int,
     * } $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            totalSeconds: $row['total_seconds'],
            totalPieces: $row['total_pieces'],
            solvedPuzzlesCount: $row['solved_puzzles_count'],
        );
    }
}
