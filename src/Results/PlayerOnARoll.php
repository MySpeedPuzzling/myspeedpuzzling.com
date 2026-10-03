<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\CountryCode;

/**
 * A person on a roll this week (docs/features/players-page/README.md, "This week"): results and pieces of the last
 * 7 days, plus chips for what happened to them in those days. A summary of a person, never single results.
 */
readonly final class PlayerOnARoll
{
    public const int CHIPS = 2;

    /**
     * @param list<PlayerMomentChip> $chips the most notable first, at most CHIPS
     */
    public function __construct(
        public string $playerId,
        public string $playerCode,
        public null|string $playerName,
        public null|CountryCode $playerCountry,
        public null|string $playerAvatar,
        // Results solved in the last 7 days (community_player_stats.solves7d) and their pieces
        public int $puzzles,
        public int $pieces,
        public array $chips,
    ) {
    }

    /**
     * @param array{
     *     player_id: string,
     *     player_code: string,
     *     player_name: null|string,
     *     player_country: null|string,
     *     player_avatar: null|string,
     *     solves7d: int|string,
     *     pieces7d: int|string,
     *     moments: string,
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
            puzzles: (int) $row['solves7d'],
            pieces: (int) $row['pieces7d'],
            chips: PlayerMomentChip::mostNotable(PlayerMomentChip::listFromJson($row['moments']), self::CHIPS),
        );
    }
}
