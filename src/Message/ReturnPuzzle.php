<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

readonly final class ReturnPuzzle
{
    public function __construct(
        public string $puzzleId,
        public string $initiatorId, // who initiates the return
    ) {
    }
}