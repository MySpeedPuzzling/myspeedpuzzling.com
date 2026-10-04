<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * The reviewed values cannot be applied to the puzzle - checked before anything is changed,
 * so nothing is half-applied.
 */
final class InvalidPuzzleChangeRequestApproval extends UnprocessableEntityHttpException
{
}
