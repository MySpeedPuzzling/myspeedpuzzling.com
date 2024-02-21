<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\CountryCode;

readonly final class PlayerIdentification
{
    public function __construct(
        public string $playerId,
        public string $playerCode,
        public null|string $playerName,
        public null|CountryCode $playerCountry,
    ) {
    }

    /**
     * @param array{
     *     player_id: string,
     *     player_code: string,
     *     player_name: null|string,
     *     player_country: null|string,
     * } $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            playerId: $row['player_id'],
            playerCode: $row['player_code'],
            playerName: $row['player_name'],
            playerCountry: CountryCode::fromCode($row['player_country']),
        );
    }
}
