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
     *     total_seconds: null|int,
     *     total_pieces: null|int,
     *     solved_puzzles_count: null|int,
     * } $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            totalSeconds: $row['total_seconds'] ?? 0,
            totalPieces: $row['total_pieces'] ?? 0,
            solvedPuzzlesCount: $row['solved_puzzles_count'] ?? 0,
        );
    }
}
