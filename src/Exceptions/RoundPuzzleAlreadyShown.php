<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * A round puzzle that was not secret has shown its puzzle on the event page - once the round has started, or while
 * another round shows the puzzle, it does not become secret any more: nothing public is hidden again.
 * An HTTP exception, so UnwrapHttpExceptionMiddleware hands it to the controller as itself.
 */
final class RoundPuzzleAlreadyShown extends ConflictHttpException
{
}
