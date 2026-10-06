<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Switching a secret round puzzle from "entirely" to "image only" publishes its name at once - and for good (a public
 * name is never hidden again, PuzzleNameAlreadyPublic). Like every other change that reveals something right away, it
 * needs an explicit yes.
 *
 * An HTTP exception, so UnwrapHttpExceptionMiddleware hands it to the controller as itself.
 */
final class NamePublicationNotConfirmed extends ConflictHttpException
{
}
