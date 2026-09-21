<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\WithHttpStatus;

/**
 * The free trial is for players who never had a membership of any kind - and only while the
 * feature is switched on.
 */
#[WithHttpStatus(Response::HTTP_CONFLICT)]
final class FreeTrialNotAvailable extends \Exception
{
}
