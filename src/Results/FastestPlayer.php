<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

readonly final class FastestPlayer
{
    public function __construct(
        public string $puzzleName,
        public string $playerName,
        public int $time,
    ) {
    }

    /**
     * @param array{puzzle_name: string, time: int, player_name: string} $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            puzzleName: $row['puzzle_name'],
            playerName: $row['player_name'],
            time: $row['time'],
        );
    }
}
