<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

final readonly class AcceptFairUsePolicy
{
    public function __construct(
        public string $playerId,
    ) {
    }
}
