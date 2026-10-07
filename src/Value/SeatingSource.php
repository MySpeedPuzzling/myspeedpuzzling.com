<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * What a seating proposal numbers the tables by (docs/features/competitions-management/seating.md).
 */
enum SeatingSource: string
{
    // The advancement seed from the entrants' results in earlier rounds of the event (AdvancementSeeding)
    case EarlierRounds = 'earlier_rounds';

    // Each entrant's expected time for the round's piece count from their MySpeedPuzzling times (GetSeatingSeedTimes)
    case MspTimes = 'msp_times';

    // A seeded draw - the same draw number gives the same order
    case Random = 'random';

    // Alphabetically
    case Name = 'name';

    /**
     * Earlier rounds and MySpeedPuzzling times order the entrants that have data and put the rest last.
     */
    public function needsData(): bool
    {
        return $this === self::EarlierRounds || $this === self::MspTimes;
    }
}
