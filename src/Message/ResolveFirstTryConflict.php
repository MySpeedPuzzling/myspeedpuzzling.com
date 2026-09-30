<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

readonly final class ResolveFirstTryConflict
{
    public function __construct(
        public string $playerId,
        public string $puzzleId,
        // The result that stays the first try; null = none of them was the first try
        public null|string $keepTimeId,
    ) {
    }
}
