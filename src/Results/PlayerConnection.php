<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * One end of a favorites link as seen from a player: someone the player
 * follows, or someone following the player.
 */
readonly final class PlayerConnection
{
    public function __construct(
        public string $playerId,
        public string $playerCode,
        public null|string $playerName,
        public null|string $playerCountry,
        public null|string $playerAvatar,
        public bool $isPrivate,
        public bool $isMutual,
    ) {
    }

    /**
     * @param array{
     *     player_id: string,
     *     player_code: string,
     *     player_name: null|string,
     *     player_country: null|string,
     *     player_avatar: null|string,
     *     is_private: bool,
     *     is_mutual: bool,
     * } $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            playerId: $row['player_id'],
            playerCode: $row['player_code'],
            playerName: $row['player_name'],
            playerCountry: $row['player_country'],
            playerAvatar: $row['player_avatar'],
            isPrivate: $row['is_private'],
            isMutual: $row['is_mutual'],
        );
    }
}
