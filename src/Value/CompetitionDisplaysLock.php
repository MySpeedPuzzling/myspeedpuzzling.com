<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * The lock key (SerializedByLock) of the writes that create displays of an event - adds and duplicates take turns, so
 * the cap CompetitionDisplay::MAX_PER_COMPETITION is counted exactly.
 */
final readonly class CompetitionDisplaysLock
{
    public static function key(string $competitionId): string
    {
        return 'competition-displays-' . strtolower($competitionId);
    }
}
