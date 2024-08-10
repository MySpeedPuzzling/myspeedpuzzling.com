<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\CountryCode;

readonly final class ConnectedWjpcParticipant
{
    public function __construct(
        public string $playerId,
        public string $playerName,
        public null|CountryCode $playerCountry,
        public null|int $fastestTime,
        public null|int $averageTime,
        public int $solvedPuzzleCount,
        public string $wjpcName,
        public null|int $rank2023,
    ) {
    }

    /**
     * @param array{
     *     wjpc_name: string,
     *     rank_2023: null|int,
     *     player_id: string,
     *     player_name: null|string,
     *     player_code: string,
     *     player_country: null|string,
     *     average_time: null|int|float,
     *     fastest_time: null|int,
     *     solved_puzzle_count: int,
     * } $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            playerId: $row['player_id'],
            playerName: $row['player_name'] ?? $row['player_code'],
            playerCountry: CountryCode::fromCode($row['player_country']),
            fastestTime: $row['fastest_time'],
            averageTime: is_numeric($row['average_time']) ? (int) $row['average_time'] : null,
            solvedPuzzleCount: $row['solved_puzzle_count'],
            wjpcName: $row['wjpc_name'],
            rank2023: $row['rank_2023'],
        );
    }
}
