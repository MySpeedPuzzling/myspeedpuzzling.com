<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Only an edition of a series moves to another series (MoveEditionToSeries) - moving a one-time event into a series
 * is not part of this change (docs/features/organizations/README.md, P23).
 */
final class NotAnEdition extends ConflictHttpException
{
    public function __construct()
    {
        parent::__construct('Only an edition of a series moves to another series - this is a one-time event. Nothing was changed.');
    }
}
