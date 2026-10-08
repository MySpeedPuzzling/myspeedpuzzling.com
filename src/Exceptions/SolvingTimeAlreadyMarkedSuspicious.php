<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class SolvingTimeAlreadyMarkedSuspicious extends ConflictHttpException
{
    public function __construct()
    {
        parent::__construct('The time is already marked "needs verification" - nothing changed.');
    }
}
