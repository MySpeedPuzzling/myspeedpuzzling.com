<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\WithHttpStatus;

/**
 * The player never had a membership, but the trial is not open to them yet: the account is younger than
 * FreeTrial::MINIMUM_ACCOUNT_AGE_DAYS or has fewer than FreeTrial::MINIMUM_LOGGED_PUZZLES puzzles logged.
 */
#[WithHttpStatus(Response::HTTP_CONFLICT)]
final class FreeTrialNotUnlockedYet extends \Exception
{
}
