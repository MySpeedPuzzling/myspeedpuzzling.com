<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

readonly final class PlayerProfile
{
    public function __construct(
        public string $playerName,
        public null|string $country,
        public null|string $city,
    ) {
    }

    /**
     * @param array{
     *     player_name: string,
     *     country: null|string,
     *     city: null|string,
     * } $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            playerName: $row['player_name'],
            country: $row['country'],
            city: $row['city'],
        );
    }
}
