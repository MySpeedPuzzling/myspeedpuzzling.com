<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

readonly final class MostFavoritePlayer
{
    public function __construct(
        public string $playerId,
        public string $playerCode,
        public null|string $playerName,
        public int $favoriteCount,
    ) {
    }

    /**
     * @param array{
     *     player_id: string,
     *     player_code: string,
     *     player_name: null|string,
     *     favorite_count: int,
     * } $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            playerId: $row['player_id'],
            playerCode: $row['player_code'],
            playerName: $row['player_name'],
            favoriteCount: $row['favorite_count'],
        );
    }
}
