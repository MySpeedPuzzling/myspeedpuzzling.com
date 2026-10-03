<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * No such row in the player's own comparison line-ups (docs/features/player-comparison.md) - somebody else's row
 * included, it is none of their business.
 */
final class ComparisonSubjectNotFound extends NotFoundHttpException
{
    public function __construct()
    {
        parent::__construct('Comparison subject not found');
    }
}
