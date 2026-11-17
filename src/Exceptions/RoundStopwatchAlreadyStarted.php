<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * "When the round's stopwatch starts" chosen for a round whose stopwatch already runs (or ran and stopped): the puzzle
 * would wait for a start that never comes - "Reveal now" is the way then. An HTTP exception, so
 * UnwrapHttpExceptionMiddleware hands it to the controller.
 */
final class RoundStopwatchAlreadyStarted extends UnprocessableEntityHttpException
{
}
