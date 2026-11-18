<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

/**
 * The printed label of an entry's division (shared-puzzles-and-divisions.md D5) - its short label, else its name - as a
 * scalar subquery of the statement that reads the entry: NULL without a division, or with one its round does not use
 * (the round page's rule, OfficialEntrantsView). Name tags and tents print it.
 */
final readonly class EntryDivisionLabel
{
    public static function sql(string $divisionIdColumn, string $roundIdColumn): string
    {
        return <<<SQL
(
    SELECT COALESCE(entry_division.short_name, entry_division.name)
    FROM competition_division entry_division
    INNER JOIN competition_round_division entry_round_division ON entry_round_division.division_id = entry_division.id AND entry_round_division.round_id = {$roundIdColumn}
    WHERE entry_division.id = {$divisionIdColumn}
)
SQL;
    }
}
