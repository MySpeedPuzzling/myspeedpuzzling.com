<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * A round puzzle that other rounds of the same competition and category have too (shared puzzles - a team challenge and
 * an open round on one puzzle, docs/features/competitions-management/shared-puzzles-and-divisions.md). Allowed: a
 * solving time's round then follows from each player's entry (SolvingTimeRoundMatch). These snippets only feed the
 * notices that say so - the round's puzzles page, the creation wizard's grid and the internal API's `sharedWithRounds`.
 */
final class SharedRoundPuzzle
{
    /**
     * A JSON array of the names of the other rounds of the competition in the same category that have the puzzle of the
     * round puzzle row, by start - `[]` when none.
     */
    public static function sqlSharingRoundNames(string $roundPuzzleAlias, string $roundAlias): string
    {
        return <<<SQL
(SELECT COALESCE(json_agg(shared_cr.name ORDER BY shared_cr.starts_at, shared_cr.name, shared_cr.id), '[]'::json)
    FROM competition_round shared_cr
    WHERE shared_cr.competition_id = {$roundAlias}.competition_id
        AND shared_cr.category = {$roundAlias}.category
        AND shared_cr.id <> {$roundAlias}.id
        AND EXISTS (
            SELECT 1 FROM competition_round_puzzle shared_crp
            WHERE shared_crp.round_id = shared_cr.id
                AND shared_crp.puzzle_id = {$roundPuzzleAlias}.puzzle_id
        ))
SQL;
    }

    /**
     * Whether one of those rounds keeps the puzzle secret differently (secret or not, or the name vs the picture only) -
     * the round's puzzles page explains what that does (A8): while any round still keeps it secret on the whole site,
     * it stays hidden everywhere (GetRoundPuzzlesHeldElsewhere); a round that reveals it shows it on its own pages.
     */
    public static function sqlHideDiffers(string $roundPuzzleAlias, string $roundAlias): string
    {
        $own = self::sqlHideSetting($roundPuzzleAlias);
        $other = self::sqlHideSetting('differs_crp');

        return <<<SQL
EXISTS (
    SELECT 1
    FROM competition_round_puzzle differs_crp
    INNER JOIN competition_round differs_cr ON differs_cr.id = differs_crp.round_id
    WHERE differs_crp.puzzle_id = {$roundPuzzleAlias}.puzzle_id
        AND differs_cr.competition_id = {$roundAlias}.competition_id
        AND differs_cr.category = {$roundAlias}.category
        AND differs_cr.id <> {$roundAlias}.id
        AND {$other} <> {$own}
)
SQL;
    }

    /**
     * @return list<string>
     */
    public static function names(null|string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }

        $names = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        if (!is_array($names)) {
            return [];
        }

        return array_values(array_filter($names, is_string(...)));
    }

    private static function sqlHideSetting(string $roundPuzzleAlias): string
    {
        return "CASE WHEN {$roundPuzzleAlias}.hide_until_round_starts THEN COALESCE({$roundPuzzleAlias}.hide_mode, 'entirely') ELSE 'public' END";
    }
}
