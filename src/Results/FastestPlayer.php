<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

readonly final class FastestPlayer
{
    public function __construct(
        public string $puzzleId,
        public string $puzzleName,
        public null|string $puzzleAlternativeName,
        public string $playerId,
        public string $playerName,
        public int $time,
        public null|string $puzzleImage,
    ) {
    }

    /** @param array{
     *     puzzle_id: string,
     *     puzzle_name: string,
     *     puzzle_alternative_name: null|string,
     *     puzzle_image: null|string,
     *     time: int,
     *     player_name: string,
     *     player_id: string,
     * } $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            puzzleId: $row['puzzle_id'],
            puzzleName: $row['puzzle_name'],
            puzzleAlternativeName: $row['puzzle_alternative_name'],
            playerId: $row['player_id'],
            playerName: $row['player_name'],
            time: $row['time'],
            puzzleImage: $row['puzzle_image'],
        );
    }
}
