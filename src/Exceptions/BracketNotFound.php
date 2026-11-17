<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The public bracket page of an event that is not public or declared no advancement steps (competition toolkit A9).
 */
final class BracketNotFound extends NotFoundHttpException
{
}
