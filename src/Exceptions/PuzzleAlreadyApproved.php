<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\WithHttpStatus;

/**
 * Somebody else approved (or merged) the puzzle first.
 */
#[WithHttpStatus(Response::HTTP_CONFLICT)]
final class PuzzleAlreadyApproved extends \Exception
{
}
