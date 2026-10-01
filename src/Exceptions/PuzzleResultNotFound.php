<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The result detail of a time does not exist for this viewer: unknown time, a time of a player the
 * viewer has hidden, or a subject (solo player / pair / team) that is private to them.
 */
final class PuzzleResultNotFound extends NotFoundHttpException
{
}
