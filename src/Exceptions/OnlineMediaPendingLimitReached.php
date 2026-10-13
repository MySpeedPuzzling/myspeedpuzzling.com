<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use SpeedPuzzling\Web\Value\OnlineEntryQuota;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

/**
 * An online entry asked for many uploads lately that never arrived (OnlineEntryQuota::MAX_PENDING_MEDIA_PER_ENTRY) -
 * the participant API answers `too_many_pending_media` with Retry-After: the phone comes back later (a store that is
 * down makes it ask again and again). An HTTP exception, so UnwrapHttpExceptionMiddleware hands it to the controller
 * as itself.
 */
final class OnlineMediaPendingLimitReached extends TooManyRequestsHttpException
{
    public function __construct()
    {
        parent::__construct(OnlineEntryQuota::PENDING_MEDIA_RETRY_AFTER_SECONDS);
    }
}
