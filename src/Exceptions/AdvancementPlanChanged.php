<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * The qualified entries, the target rounds or their entries changed since the organiser saw the advancement plan (or
 * no plan was shown) - nothing was written; the plan has to be reviewed again.
 */
final class AdvancementPlanChanged extends ConflictHttpException
{
    public function __construct()
    {
        parent::__construct('The advancement plan changed meanwhile.');
    }
}
