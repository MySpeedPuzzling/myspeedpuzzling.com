<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

final readonly class CreatePersonalAccessToken
{
    public function __construct(
        public string $tokenId,
        public string $playerId,
        public string $name,
    ) {
    }
}
