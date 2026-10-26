<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

/**
 * The asker sent too many borrow requests within the hour (rate limiter `borrow_request`,
 * docs/features/wishlist-availability.md).
 */
final class BorrowRequestLimitReached extends TooManyRequestsHttpException
{
}
