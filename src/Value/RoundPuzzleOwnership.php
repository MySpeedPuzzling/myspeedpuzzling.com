<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * Whether a round may take over a puzzle's site-wide hide ("Keep it hidden everywhere until …" on the round's puzzles
 * page): the round keeps it secret on its event page only, while the puzzle is the competition's own - unapproved, and
 * either added by an organiser of this competition (owner or maintainer of it or of its series) or created by this
 * very round puzzle (both UUIDv7 ids within 2 minutes - a puzzle created on the fly before the reveal model).
 * A public catalogue puzzle or a placeholder hidden by hand never qualifies.
 */
final class RoundPuzzleOwnership
{
    public const int CREATED_TOGETHER_SECONDS = 120;

    public static function sqlMayKeepHiddenEverywhere(string $roundPuzzleAlias, string $puzzleAlias, string $roundAlias): string
    {
        $createdTogether = self::sqlCreatedTogether("{$puzzleAlias}.id", "{$roundPuzzleAlias}.id");

        return <<<SQL
({$roundPuzzleAlias}.hide_until_round_starts
    AND {$roundPuzzleAlias}.hides_everywhere = false
    AND {$puzzleAlias}.approved = false
    AND (
        {$createdTogether}
        OR {$puzzleAlias}.added_by_user_id IN (
            SELECT owner_c.added_by_player_id FROM competition owner_c WHERE owner_c.id = {$roundAlias}.competition_id
            UNION SELECT owner_cm.player_id FROM competition_maintainer owner_cm WHERE owner_cm.competition_id = {$roundAlias}.competition_id
            UNION SELECT owner_cs.added_by_player_id FROM competition owner_c2 INNER JOIN competition_series owner_cs ON owner_cs.id = owner_c2.series_id WHERE owner_c2.id = {$roundAlias}.competition_id
            UNION SELECT owner_csm.player_id FROM competition owner_c3 INNER JOIN competition_series_maintainer owner_csm ON owner_csm.competition_series_id = owner_c3.series_id WHERE owner_c3.id = {$roundAlias}.competition_id
        )
    ))
SQL;
    }

    /**
     * Both ids are UUIDv7 (version 7) whose 48-bit millisecond timestamps lie within CREATED_TOGETHER_SECONDS.
     */
    public static function sqlCreatedTogether(string $firstIdColumn, string $secondIdColumn): string
    {
        $milliseconds = static fn (string $column): string => "('x' || lpad(substr(replace({$column}::text, '-', ''), 1, 12), 16, '0'))::bit(64)::bigint";
        $isV7 = static fn (string $column): string => "substr(replace({$column}::text, '-', ''), 13, 1) = '7'";
        $window = self::CREATED_TOGETHER_SECONDS * 1000;

        return "({$isV7($firstIdColumn)} AND {$isV7($secondIdColumn)}"
            . " AND abs({$milliseconds($firstIdColumn)} - {$milliseconds($secondIdColumn)}) <= {$window})";
    }
}
