<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * A scheduled reveal at or before now would reveal the puzzle the moment it is saved - that is "Reveal now",
 * an explicit choice of its own. An HTTP exception, so UnwrapHttpExceptionMiddleware hands it to the controller.
 */
final class RevealMomentAlreadyPassed extends UnprocessableEntityHttpException
{
}
