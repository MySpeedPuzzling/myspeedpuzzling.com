<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * No such duplicate case - or it is about somebody else (they must not learn it exists).
 */
final class DuplicateCaseNotFound extends NotFoundHttpException
{
}
