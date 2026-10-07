<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * The round's official results changed after the organiser confirmed deleting them with the round - nothing was
 * deleted; the organiser confirms the new list.
 */
final class OfficialResultsChangedMeanwhile extends ConflictHttpException
{
    public function __construct()
    {
        parent::__construct('The round\'s official results changed since they were confirmed.');
    }
}
