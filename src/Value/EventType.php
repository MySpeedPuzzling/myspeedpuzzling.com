<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * What kind of event an event, edition or series is (docs/features/events-page/event-types.md) - one or several, in
 * this order everywhere they are listed. Stored by value: never rename a value.
 */
enum EventType: string
{
    case Competition = 'competition';
    case Marathon = 'marathon';
    case Workshop = 'workshop';
    case CasualPuzzling = 'casual_puzzling';
    case PuzzleSwap = 'puzzle_swap';

    public function translationKey(): string
    {
        return 'event_type.' . $this->value;
    }
}
