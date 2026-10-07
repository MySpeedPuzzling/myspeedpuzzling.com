<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The round entry (RoundEntryRef) is not an entry of this round (any more).
 */
final class RoundEntryNotFound extends NotFoundHttpException
{
    public function __construct()
    {
        parent::__construct('The entry is not in this round.');
    }
}
