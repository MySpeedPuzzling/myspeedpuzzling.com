<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

readonly final class UpdatePuzzlePuzzleUrl
{
    public function __construct(
        public string $puzzleId,
        public null|string $puzzleUrl,
    ) {
    }
}
