<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * A round move (MoveRoundToCompetition) refused: the target competition has one of the round's puzzles in a round of
 * the same category already. Rounds of one category may share a puzzle otherwise (a solving time's round then follows
 * from each player's entry - shared-puzzles-and-divisions.md), but a moved round has no entries, so its times would be
 * re-derived there by weaker evidence than they have now.
 */
final class PuzzleInTwoRoundsOfCategory extends ConflictHttpException
{
    public function __construct(string $category, string $conflictingRoundName, null|\Throwable $previous = null)
    {
        parent::__construct(sprintf(
            'The target already has one of these puzzles in its %s round "%s" - a moved round would share it with that round. Nothing was changed.',
            $category,
            $conflictingRoundName,
        ), $previous);
    }
}
