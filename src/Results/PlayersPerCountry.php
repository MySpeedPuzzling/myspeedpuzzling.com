<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\CountryCode;

readonly final class PlayersPerCountry
{
    public function __construct(
        public null|CountryCode $countryCode,
        public int $playersCount,
    ) {
    }

    /**
     * @param array{
     *     country: string,
     *     players_count: int,
     * } $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            countryCode: CountryCode::fromCode($row['country']),
            playersCount: $row['players_count'],
        );
    }
}
