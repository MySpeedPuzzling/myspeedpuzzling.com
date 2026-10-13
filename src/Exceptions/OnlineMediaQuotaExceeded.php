<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * An online entry holds as many evidence objects, or as many bytes of them, as a link may ever upload
 * (OnlineEntryQuota) - the participant API answers `media_quota_exceeded`. An HTTP exception, so
 * UnwrapHttpExceptionMiddleware hands it to the controller as itself.
 */
final class OnlineMediaQuotaExceeded extends UnprocessableEntityHttpException
{
}
