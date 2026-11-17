<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\EventResultsImport;

/**
 * An imported single person nobody has claimed yet (docs/features/event-results-import/solo-and-relays.md §1, shape A):
 * a solo result without its player, no team snapshot. Read models show it like an anonymous team of one - one person
 * whose anonymous slot is `s:<time id>` (unique to the result, so two of them never read as one person), named by the
 * sheet (event_imported_result.name). The same key is the claim log's slot key (ResultClaimLog::$slotKey).
 */
final class UnclaimedSolo
{
    public const string SLOT_PREFIX = 's:';

    public static function slotOf(string $timeId): string
    {
        return self::SLOT_PREFIX . $timeId;
    }

    /**
     * SQL: the slot of a solving time row `$alias` - NULL unless it is an imported solo result nobody has claimed.
     */
    public static function sqlSlot(string $alias): string
    {
        return "CASE WHEN {$alias}.team IS NULL AND {$alias}.player_id IS NULL AND {$alias}.created_via = 'event_import' THEN '" . self::SLOT_PREFIX . "' || {$alias}.id::text END";
    }

    /**
     * SQL: the sheet's name of an imported solo result nobody has claimed (NULL for every other row). A CASE, so the
     * lookup runs only for such a row - never once per tracked time of a long solo list.
     */
    public static function sqlName(string $alias): string
    {
        return "CASE WHEN {$alias}.team IS NULL AND {$alias}.player_id IS NULL THEN (SELECT eir.name FROM event_imported_result eir WHERE eir.solving_time_id = {$alias}.id) END";
    }
}
