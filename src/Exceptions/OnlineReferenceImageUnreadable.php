<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Throwable;

/**
 * An uploaded reference image of an online round (docs/features/online-competitions/README.md §5.8) that passed the
 * upload's checks (type, size) but is no picture Imagick can read - a damaged file. Thrown before anything was stored. An
 * HTTP exception, so UnwrapHttpExceptionMiddleware hands it to the controller as itself.
 */
final class OnlineReferenceImageUnreadable extends UnprocessableEntityHttpException
{
    public const string TRANSLATION_KEY = 'online_rounds.settings.reference.unreadable';

    public function __construct(null|Throwable $previous = null)
    {
        parent::__construct('The reference image could not be read - nothing was stored.', $previous);
    }
}
