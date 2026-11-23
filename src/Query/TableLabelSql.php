<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

/**
 * The label of an entry's table number, read in the statement that reads the entry (table-labels-and-plates.md A2):
 * `competition_round.table_labels` names table number n with item n - 1. NULL when the round has no list or the list
 * does not name the number - the reader then shows the number (TableLabels::display()). No statement more.
 */
final class TableLabelSql
{
    /**
     * @param string $roundAlias the alias of the entry's competition_round
     * @param string $numberColumn the entry's table number column, e.g. `cpr.table_number`
     */
    public static function label(string $roundAlias, string $numberColumn): string
    {
        return "CASE WHEN {$roundAlias}.table_labels IS NOT NULL AND {$numberColumn} IS NOT NULL THEN {$roundAlias}.table_labels ->> ({$numberColumn} - 1) END";
    }
}
