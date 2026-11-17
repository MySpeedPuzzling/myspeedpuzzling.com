<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * How a round's puzzles are solved (competition toolkit A2, docs/features/competitions-management/toolkit/
 * R-review-and-plan.md §1.1, A-round-formats.md §2.1):
 *
 * - Total (the default, every round before the toolkit): one result for the whole round, whatever its puzzles.
 * - Pick: every entry solves K of the round's N puzzles (CompetitionRound::$puzzlesToSolve, 1 ≤ K ≤ N), in any order.
 *   K = N is "solve all of them", K = 1 "pick one of N".
 *
 * Only the organiser's choice behind COMPETITION_TOOLKIT_MANAGE; a round with online timing stays `total` (its compete
 * page is built around one puzzle).
 */
enum RoundPuzzleFormat: string
{
    case Total = 'total';
    case Pick = 'pick';

    /**
     * Whether K puzzles of N is a valid `pick` - the one rule the entity, the form and the guards share.
     */
    public static function isValidPick(null|int $toSolve, int $puzzlesCount): bool
    {
        return $toSolve !== null && $toSolve >= 1 && $toSolve <= $puzzlesCount;
    }
}
