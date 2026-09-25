<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\WithHttpStatus;

/**
 * The approval form asked for something that cannot be done - checked before
 * anything is changed, so nothing is half-applied.
 */
#[WithHttpStatus(Response::HTTP_UNPROCESSABLE_ENTITY)]
final class InvalidPuzzleApproval extends \Exception
{
}
