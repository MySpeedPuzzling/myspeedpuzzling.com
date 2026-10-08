<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * MoveEditionToSeries to the series the edition is in already.
 */
final class EditionAlreadyInSeries extends ConflictHttpException
{
    public function __construct()
    {
        parent::__construct('The edition is in this series already. Nothing was changed.');
    }
}
