<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * The values cannot be saved on the puzzle - checked before anything is changed,
 * so nothing is half-applied.
 */
final class InvalidPuzzleValues extends UnprocessableEntityHttpException
{
}
