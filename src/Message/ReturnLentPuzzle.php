<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

readonly final class ReturnLentPuzzle
{
    public function __construct(
        public string $lentPuzzleId,
        public string $actingPlayerId,
    ) {
    }
}
