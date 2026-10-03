<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Without a membership the Solo line-up is "you + 1 other": the player's own row can be neither removed nor swapped
 * out (docs/features/player-comparison.md). Members may compare other people without themselves.
 */
final class CanNotRemoveYourselfFromComparison extends AccessDeniedHttpException
{
    public function __construct()
    {
        parent::__construct('Only members can remove themselves from the comparison');
    }
}
