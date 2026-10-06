<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * A puzzle is secret while a competition keeps it hidden on the whole site: puzzle.hide_until (the puzzle) or
 * puzzle.hide_image_until (its picture and codes) in the future (SecretPuzzleHides), and it is a competition's - in a
 * round keeping it hidden everywhere, or unapproved (a puzzle created for a round is unapproved and cannot be approved
 * before its reveal; one removed from its round stays secret). An approved placeholder hidden by hand (Ravensburger
 * Puzzle Month) is not. A secret puzzle is not shown to moderators and not approved, merged or edited
 * (docs/features/competitions-management "Hide Until Round Starts").
 */
final class PuzzleSecrecy
{
    /**
     * True for a puzzle that is not secret at :now. Bind :now as 'Y-m-d H:i:s'.
     */
    public static function sqlNotSecret(string $puzzleAlias, string $nowParameter = ':now'): string
    {
        return 'NOT ' . self::sqlSecret($puzzleAlias, $nowParameter);
    }

    public static function sqlSecret(string $puzzleAlias, string $nowParameter = ':now'): string
    {
        // IS NOT NULL first: a comparison with NULL makes the whole condition NULL - and NOT NULL is not true
        return "((({$puzzleAlias}.hide_until IS NOT NULL AND {$puzzleAlias}.hide_until > {$nowParameter}::timestamp)"
            . " OR ({$puzzleAlias}.hide_image_until IS NOT NULL AND {$puzzleAlias}.hide_image_until > {$nowParameter}::timestamp))"
            . " AND ({$puzzleAlias}.approved = false OR EXISTS ("
            . "SELECT 1 FROM competition_round_puzzle secrecy_crp"
            . " WHERE secrecy_crp.puzzle_id = {$puzzleAlias}.id AND secrecy_crp.hides_everywhere)))";
    }
}
