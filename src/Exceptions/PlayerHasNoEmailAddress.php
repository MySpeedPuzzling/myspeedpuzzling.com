<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\WithHttpStatus;

/**
 * The player has no account with an e-mail address, so there is nothing to block.
 */
#[WithHttpStatus(Response::HTTP_CONFLICT)]
final class PlayerHasNoEmailAddress extends \Exception
{
}
