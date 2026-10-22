<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

/**
 * The sender asked too many puzzlers today (rate limiter `partner_request`, docs/features/partner-finder.md D6).
 */
final class PartnerRequestLimitReached extends TooManyRequestsHttpException
{
}
