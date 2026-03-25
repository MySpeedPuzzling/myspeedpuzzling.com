<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;

readonly final class PlayerEloEntry
{
    public function __construct(
        public string $playerId,
        public null|string $playerName,
        public null|string $playerCode,
        public null|string $playerCountry,
        public null|string $playerAvatar,
        public int $eloRating,
        public int $rank,
        public null|DateTimeImmutable $lastSolveAt,
    ) {
    }

    /**
     * @param array{
     *     player_id: string,
     *     player_name: null|string,
     *     player_code: string,
     *     player_country: null|string,
     *     player_avatar: null|string,
     *     elo_rating: int|string,
     *     rank: int|string,
     *     last_solve_at: null|string,
     * } $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            playerId: $row['player_id'],
            playerName: $row['player_name'],
            playerCode: $row['player_code'],
            playerCountry: $row['player_country'],
            playerAvatar: $row['player_avatar'],
            eloRating: (int) $row['elo_rating'],
            rank: (int) $row['rank'],
            lastSolveAt: $row['last_solve_at'] !== null ? new DateTimeImmutable($row['last_solve_at']) : null,
        );
    }
}
