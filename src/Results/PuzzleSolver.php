<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

readonly final class PuzzleSolver
{
    public function __construct(
        public string $playerId,
        public string $playerName,
        public int $time,
        public int $playersCount,
        public null|string $groupName,
    ) {
    }

    /**
     * @param array{
     *     player_id: string,
     *     player_name: string,
     *     time: int,
     *     players_count: int,
     *     group_name: null|string
     * } $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            playerId: $row['player_id'],
            playerName: $row['player_name'],
            time: $row['time'],
            playersCount: $row['players_count'],
            groupName: $row['group_name'],
        );
    }
}
