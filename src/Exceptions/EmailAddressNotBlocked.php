<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The player's current address is not blocked by hand (a bounce suppression is never lifted by the internal API).
 */
final class EmailAddressNotBlocked extends NotFoundHttpException
{
}
