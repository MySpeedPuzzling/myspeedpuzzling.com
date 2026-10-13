<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use SpeedPuzzling\Web\Entity\Competition;

/**
 * The one rule for "is this event online": a one-time event by its own flag, an edition by its series' flag. Editions
 * copy their series' flag when they are made or moved (AddEdition, MoveEditionToSeries), but a series edited later does
 * not rewrite them - the events pages (GetEventOccurrences, GetOrganizedEvents) and the edition page go by the series,
 * and so does online timing (docs/features/online-competitions/): who may turn it on (OnlineTimingAvailability), the
 * ⋯ menu's "Online timing" (GetOrganizedEvents) and "Open my competition page" (GetEventAttendance).
 */
final class EventIsOnline
{
    public static function of(Competition $competition): bool
    {
        return $competition->series !== null ? $competition->series->isOnline : $competition->isOnline;
    }

    /**
     * The same in SQL - `$competition` is the alias of the event's row, `$series` of its series (LEFT JOINed)
     */
    public static function sql(string $competition, string $series): string
    {
        return "CASE WHEN {$competition}.series_id IS NULL THEN {$competition}.is_online ELSE {$series}.is_online END";
    }
}
