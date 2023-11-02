<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

readonly final class FastestPlayer
{
    public function __construct(
        public string $puzzleId,
        public string $puzzleName,
        public string $playerId,
        public string $playerName,
        public int $time,
    ) {
    }

    /**
     * @param array{puzzle_id: string, puzzle_name: string, time: int, player_name: string, player_id: string} $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            puzzleId: $row['puzzle_id'],
            puzzleName: $row['puzzle_name'],
            playerId: $row['player_id'],
            playerName: $row['player_name'],
            time: $row['time'],
        );
    }
}
