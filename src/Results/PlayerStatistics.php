<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

readonly final class PlayerStatistics
{
    public function __construct(
        public string $playerId,
        public string $playerName,
        public int $totalSeconds,
        public int $totalPieces,
        public int $solvedPuzzlesCount,
    ) {
    }

    /**
     * @param array{
     *     player_id: string,
     *     player_name: null|string,
     *     total_seconds: null|int,
     *     total_pieces: null|int,
     *     solved_puzzles_count: int,
     * } $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            playerId: $row['player_id'],
            playerName: $row['player_name'] ?? '',
            totalSeconds: $row['total_seconds'] ?? 0,
            totalPieces: $row['total_pieces'] ?? 0,
            solvedPuzzlesCount: $row['solved_puzzles_count'],
        );
    }
}
