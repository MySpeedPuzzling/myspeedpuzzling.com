<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\CountryCode;

readonly final class NotConnectedCompetitionParticipant
{
    public function __construct(
        public string $id,
        public string $name,
        public null|CountryCode $countryCode,
    ) {
    }

    /**
     * @param array{
     *     id: string,
     *     name: string,
     *     country: null|string,
     * } $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            id: $row['id'],
            name: $row['name'],
            countryCode: CountryCode::fromCode($row['country']),
        );
    }
}
