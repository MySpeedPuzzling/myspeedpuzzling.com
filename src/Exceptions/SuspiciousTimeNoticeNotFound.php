<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * No such notice - or it is somebody else's (they must not learn it exists).
 */
final class SuspiciousTimeNoticeNotFound extends NotFoundHttpException
{
}
