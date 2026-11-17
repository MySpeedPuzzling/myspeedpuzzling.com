<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * "Also reveal the hidden puzzles now" (RevealPuzzlesTheStopwatchMissed): the puzzles waiting for the stopwatch, or how
 * far they would come out, changed since the organiser confirmed the list - nothing was revealed. An HTTP exception, so
 * UnwrapHttpExceptionMiddleware hands it to the controller as itself.
 */
final class StopwatchMissedRevealChanged extends ConflictHttpException
{
}
