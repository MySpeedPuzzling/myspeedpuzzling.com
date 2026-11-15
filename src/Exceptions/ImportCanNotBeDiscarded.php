<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * The import added rows (or tried to): it is kept - its rows' keys and links are what "never twice" and Undo rest on
 * (docs/features/data-import/adding.md "What is never deleted").
 */
final class ImportCanNotBeDiscarded extends ConflictHttpException
{
    public function __construct()
    {
        parent::__construct('An import that added rows can not be discarded.');
    }
}
