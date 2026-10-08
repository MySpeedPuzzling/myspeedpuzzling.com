<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * MoveRoundToCompetition names the competition the round was in when the move was asked for (its lock key) - the round
 * has moved since, so the move was decided on a stale page. Nothing changed.
 */
final class RoundMovedMeanwhile extends ConflictHttpException
{
    public function __construct()
    {
        parent::__construct('The round was moved to another competition meanwhile. Nothing was changed - load it again.');
    }
}
