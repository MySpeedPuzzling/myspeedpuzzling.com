<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The player or pair/team does not exist for whoever wants to compare it (docs/features/player-comparison.md):
 * unknown, blocked by them, private and not revealed to them, a team with a member they blocked, or a team of
 * private players they are not part of. One answer for all of them - the reason must never show.
 */
final class ComparisonSubjectNotAvailable extends NotFoundHttpException
{
    public function __construct()
    {
        parent::__construct('Comparison subject not available');
    }
}
