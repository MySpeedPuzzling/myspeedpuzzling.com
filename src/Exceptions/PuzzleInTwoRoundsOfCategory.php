<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * The internal API's answer to a change that would put a puzzle into two rounds of one category of a competition -
 * the invariant behind round results (PuzzleAlreadyInCompetitionRoundCategory is the handlers' own exception).
 */
final class PuzzleInTwoRoundsOfCategory extends ConflictHttpException
{
    public function __construct(string $category, string $conflictingRoundName, null|\Throwable $previous = null)
    {
        parent::__construct(sprintf(
            'A puzzle can be in only one %s round of a competition - one of them is already in round "%s". Nothing was changed.',
            $category,
            $conflictingRoundName,
        ), $previous);
    }
}
