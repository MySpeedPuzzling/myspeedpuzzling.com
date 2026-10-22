<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\CountryCode;

/**
 * One face on the event page's partner card (GetPartnerCardData): a puzzler with an open partner listing at the event,
 * newest first - never one the viewer has hidden, never a private player hidden from the viewer, never the viewer.
 *
 * @phpstan-type PartnerCardFaceJsonRow array{
 *     id: string,
 *     name: null|string,
 *     code: string,
 *     avatar: null|string,
 *     country: null|string,
 * }
 */
readonly final class PartnerCardFace
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
     * @param PartnerCardFaceJsonRow $row
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
