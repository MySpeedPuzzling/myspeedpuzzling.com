<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * No such field - or it is another player's, which nobody may tell apart.
 */
final class CustomFieldNotFound extends NotFoundHttpException
{
}
