<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\CountryCode;

readonly final class MostActivePlayer
{
    public function __construct(
        public string $playerId,
        public string $playerName,
        public null|CountryCode $playerCountry,
        public int $solvedPuzzlesCount,
        public int $totalPiecesCount,
        public int $totalSeconds,
        public bool $isPrivate,
    ) {
    }

    /**
     * @param array{
     *     player_id: string,
     *     player_name: null|string,
     *     player_country: null|string,
     *     solved_puzzles_count: int,
     *     total_pieces_count: int,
     *     total_seconds: int,
     *     is_private: bool,
     * } $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            playerId: $row['player_id'],
            playerName: $row['player_name'] ?? '',
            playerCountry: CountryCode::fromCode($row['player_country']),
            solvedPuzzlesCount: $row['solved_puzzles_count'],
            totalPiecesCount: $row['total_pieces_count'],
            totalSeconds: $row['total_seconds'],
            isPrivate: $row['is_private'],
        );
    }
}
