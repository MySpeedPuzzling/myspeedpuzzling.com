<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * A revealed round puzzle is public - its reveal is not changed any more, so nothing public is hidden again silently.
 * An HTTP exception, so UnwrapHttpExceptionMiddleware hands it to the controller as itself.
 */
final class RoundPuzzleAlreadyRevealed extends ConflictHttpException
{
}
