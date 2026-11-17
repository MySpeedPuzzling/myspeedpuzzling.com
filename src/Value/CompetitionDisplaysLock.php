<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * The lock key (SerializedByLock) of the writes that create displays of an event - adds and duplicates take turns, so
 * the cap CompetitionDisplay::MAX_PER_COMPETITION is counted exactly; and of the saves of one display's design.
 */
final readonly class CompetitionDisplaysLock
{
    public static function key(string $competitionId): string
    {
        return 'competition-displays-' . strtolower($competitionId);
    }

    /**
     * Saves of one display's design take turns - the version check and the write never interleave.
     */
    public static function display(string $displayId): string
    {
        return 'competition-display-' . strtolower($displayId);
    }
}
