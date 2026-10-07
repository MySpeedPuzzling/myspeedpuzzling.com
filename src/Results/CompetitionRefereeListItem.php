<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\CountryCode;

readonly final class CompetitionRefereeListItem
{
    public function __construct(
        public string $playerId,
        public string $playerCode,
        public null|string $playerName,
        public null|CountryCode $playerCountry,
        public DateTimeImmutable $addedAt,
        public null|string $addedByName,
    ) {
    }

    /**
     * @param array{player_id: string, player_code: string, player_name: null|string, player_country: null|string, added_at: string, added_by_name: null|string} $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            playerId: $row['player_id'],
            playerCode: $row['player_code'],
            playerName: $row['player_name'],
            playerCountry: CountryCode::fromCode($row['player_country']),
            addedAt: new DateTimeImmutable($row['added_at']),
            addedByName: $row['added_by_name'],
        );
    }
}
