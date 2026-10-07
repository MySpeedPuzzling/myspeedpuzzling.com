<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Registration settings the form would never send (it validates the same rules) - refused before anything changes.
 */
final class InvalidRegistrationSettings extends UnprocessableEntityHttpException
{
}
