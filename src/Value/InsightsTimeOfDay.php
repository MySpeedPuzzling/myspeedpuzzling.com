<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * When a result was logged, in the player's own time zone (player.timezone) - only for players with a zone, and only
 * for results logged on the day they were solved (labelled "when you logged": the stored time is the logging moment,
 * not the solve).
 */
enum InsightsTimeOfDay: string
{
    case Morning = 'morning';
    case Afternoon = 'afternoon';
    case Evening = 'evening';
    case Night = 'night';

    public static function ofHour(int $hour): self
    {
        return match (true) {
            $hour >= 5 && $hour < 12 => self::Morning,
            $hour >= 12 && $hour < 17 => self::Afternoon,
            $hour >= 17 && $hour < 22 => self::Evening,
            default => self::Night,
        };
    }
}
