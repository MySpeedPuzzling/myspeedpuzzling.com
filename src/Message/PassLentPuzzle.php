<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

readonly final class PassLentPuzzle
{
    public function __construct(
        public string $lentPuzzleId,
        public string $currentHolderPlayerId,
        public null|string $newHolderPlayerId = null,
        public null|string $newHolderName = null,
    ) {
    }
}
