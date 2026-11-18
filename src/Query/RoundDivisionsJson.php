<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

/**
 * The divisions a round uses as one JSON column of the statement that reads the round (shared-puzzles-and-divisions.md
 * D2) - NULL for a round without divisions, so reading them costs no statement of its own and a round without them
 * reads exactly what it did. Hydrated by RoundDivision::listFromJson(); in the event's order (position, then name).
 */
final readonly class RoundDivisionsJson
{
    public static function sql(string $roundIdColumn): string
    {
        return <<<SQL
(
    SELECT json_agg(json_build_object('id', d.id, 'name', d.name, 'shortName', d.short_name) ORDER BY d.position, d.name, d.id)
    FROM competition_round_division crd
    INNER JOIN competition_division d ON d.id = crd.division_id
    WHERE crd.round_id = {$roundIdColumn}
)
SQL;
    }
}
