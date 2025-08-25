<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

readonly final class LendPuzzle
{
    public function __construct(
        public string $playerId,
        public string $puzzleId,
        public string $lendToPlayerId,
    ) {
    }
}