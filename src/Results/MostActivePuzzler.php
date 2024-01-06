<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

readonly final class MostActivePuzzler
{
    public function __construct(
        public string $playerId,
        public string $playerName,
        public int $solvedPuzzlesCount,
    ) {
    }

    /**
     * @param array{
     *     player_id: string,
     *     player_name: null|string,
     *     solved_puzzles_count: int,
     * } $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            playerId: $row['player_id'],
            playerName: $row['player_name'] ?? '',
            solvedPuzzlesCount: $row['solved_puzzles_count'],
        );
    }
}
