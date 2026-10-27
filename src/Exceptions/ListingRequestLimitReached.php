<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

/**
 * The buyer sent too many listing requests within the hour (rate limiter `listing_request`, docs/features/marketplace/12-listing-requests.md).
 */
final class ListingRequestLimitReached extends TooManyRequestsHttpException
{
}
