<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

use SpeedPuzzling\Web\Results\PlayerConnection;

/**
 * A player on the token owner's favorites or followers list. A private player
 * is masked as everywhere in the API - the website's "Secret puzzler #CODE".
 */
final class PlayerConnectionResponse
{
    public function __construct(
        public string $id,
        public null|string $name,
        public string $code,
        public null|string $avatar,
        public null|string $country,
        public bool $isPrivate,
        public bool $isMutual,
    ) {
    }

    public static function fromConnection(PlayerConnection $connection): self
    {
        return new self(
            id: $connection->playerId,
            name: $connection->isPrivate ? null : $connection->playerName,
            code: $connection->playerCode,
            avatar: $connection->isPrivate ? null : $connection->playerAvatar,
            country: $connection->isPrivate ? null : $connection->playerCountry,
            isPrivate: $connection->isPrivate,
            isMutual: $connection->isMutual,
        );
    }
}
