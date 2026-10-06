<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * A competition's secret puzzle whose name is already public (kept secret "image only") cannot be hidden "entirely"
 * on the whole site again - times, collections, listings and caches already show the name, and their owners would hit
 * 404s on their own items. It can only keep its picture secret.
 *
 * An HTTP exception, so UnwrapHttpExceptionMiddleware hands it to the controller as itself.
 */
final class PuzzleNameAlreadyPublic extends ConflictHttpException
{
    public function __construct()
    {
        parent::__construct('Its name is already public - it can only keep its picture secret. Nothing was changed.');
    }
}
