<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\CountryCode;

/**
 * Lightweight player display info used by the comparison feature (subject chips,
 * co-solver chips, bucket launcher). Includes privacy flag for add-time checks.
 */
readonly final class ComparisonPlayer
{
    public function __construct(
        public string $playerId,
        public string $playerCode,
        public null|string $playerName,
        public null|CountryCode $playerCountry,
        public null|string $playerAvatar,
        public bool $isPrivate,
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
     * } $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            playerId: $row['player_id'],
            playerCode: strtoupper($row['player_code']),
            playerName: $row['player_name'],
            playerCountry: CountryCode::fromCode($row['player_country']),
            playerAvatar: $row['player_avatar'],
            isPrivate: $row['is_private'],
        );
    }

    public function displayName(): string
    {
        return $this->playerName ?? ('#' . $this->playerCode);
    }
}
