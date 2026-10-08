<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class SolvingTimeNotSuspicious extends ConflictHttpException
{
    public function __construct()
    {
        parent::__construct('The time is neither marked nor waiting in the verification queue - nothing to unmark.');
    }
}
