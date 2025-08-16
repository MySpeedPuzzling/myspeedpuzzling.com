<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

readonly final class CheckFirstTryAvailability
{
    public function forPlayer(string $playerId, string $puzzleId): bool
    {
        return false;
    }
}
