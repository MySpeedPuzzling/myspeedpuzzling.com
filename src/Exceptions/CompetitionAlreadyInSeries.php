<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Only a one-time event becomes a series (ConvertCompetitionToSeries) - an edition is one already.
 */
final class CompetitionAlreadyInSeries extends ConflictHttpException
{
    public function __construct()
    {
        parent::__construct('The competition is an edition of a series already - only a one-time event becomes a series.');
    }
}
