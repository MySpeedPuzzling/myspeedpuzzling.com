<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

/**
 * The player claimed too many spots today (rate limiter `event_result_claim`, docs/features/event-results-import/README.md
 * "Gates").
 */
final class EventResultClaimLimitReached extends TooManyRequestsHttpException
{
}
