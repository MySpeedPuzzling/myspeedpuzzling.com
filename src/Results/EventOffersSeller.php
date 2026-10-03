<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\CountryCode;

/**
 * One face in the avatar row of the event page's marketplace card (GetEventOffers::summary()): a seller who is going
 * to the event and has published offers.
 *
 * @phpstan-type EventOffersSellerJsonRow array{
 *     id: string,
 *     name: null|string,
 *     code: string,
 *     avatar: null|string,
 *     country: null|string,
 * }
 */
readonly final class EventOffersSeller
{
    public function __construct(
        public string $playerId,
        public null|string $playerName,
        public string $playerCode,
        public null|string $avatar,
        public null|CountryCode $country,
    ) {
    }

    /**
     * @param EventOffersSellerJsonRow $row
     */
    public static function fromJsonRow(array $row): self
    {
        return new self(
            playerId: $row['id'],
            playerName: $row['name'],
            playerCode: $row['code'],
            avatar: $row['avatar'],
            country: CountryCode::fromCode($row['country']),
        );
    }

    public function displayName(): string
    {
        return $this->playerName ?? '#' . strtoupper($this->playerCode);
    }
}
