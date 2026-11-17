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

    // The tables the same people had in an earlier round of the same kind (`from_round`) - the rest after the highest
    // (competition toolkit W3, "same table for every round")
    case SameTables = 'same_tables';

    /**
     * Earlier rounds, MySpeedPuzzling times and the same tables place the entrants that have data and put the rest last.
     */
    public function needsData(): bool
    {
        return $this === self::EarlierRounds || $this === self::MspTimes || $this === self::SameTables;
    }
}
