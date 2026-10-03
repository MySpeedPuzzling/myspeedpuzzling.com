<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\CountryCode;

/**
 * One puzzler in the directory (docs/features/players-page/README.md, "Browse all"). Public numbers only.
 */
readonly final class PlayersDirectoryCard
{
    public function __construct(
        public string $playerId,
        public string $playerCode,
        public null|string $playerName,
        public null|CountryCode $playerCountry,
        public null|string $playerAvatar,
        public int $solvesThisMonth,
        public null|int $best500Seconds,
        public int $favoritesCount,
        public bool $competesInEvents,
        public bool $swapsPuzzles,
        public bool $onInstagram,
    ) {
    }

    /**
     * @param array{
     *     player_id: string,
     *     player_code: string,
     *     player_name: null|string,
     *     player_country: null|string,
     *     player_avatar: null|string,
     *     solves_this_month: int|string,
     *     best500_seconds: null|int|string,
     *     favorites_count: int|string,
     *     competes_in_events: bool,
     *     swaps_puzzles: bool,
     *     on_instagram: bool,
     * } $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            playerId: $row['player_id'],
            playerCode: $row['player_code'],
            playerName: $row['player_name'],
            playerCountry: CountryCode::fromCode($row['player_country']),
            playerAvatar: $row['player_avatar'],
            solvesThisMonth: (int) $row['solves_this_month'],
            best500Seconds: $row['best500_seconds'] === null ? null : (int) $row['best500_seconds'],
            favoritesCount: (int) $row['favorites_count'],
            competesInEvents: $row['competes_in_events'],
            swapsPuzzles: $row['swaps_puzzles'],
            onInstagram: $row['on_instagram'],
        );
    }
}
