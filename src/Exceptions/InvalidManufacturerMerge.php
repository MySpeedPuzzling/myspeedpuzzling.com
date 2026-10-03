<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * A brand merge that cannot be done - checked before anything is changed, so
 * nothing is half-applied.
 */
final class InvalidManufacturerMerge extends UnprocessableEntityHttpException
{
}
