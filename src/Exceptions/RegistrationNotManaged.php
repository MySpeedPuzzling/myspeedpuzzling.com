<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * A registration action (mark paid, promote, check-in) on an event that does not manage registration - a page opened
 * before the organiser switched management off.
 */
final class RegistrationNotManaged extends ConflictHttpException
{
}
