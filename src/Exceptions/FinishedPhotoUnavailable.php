<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Throwable;

/**
 * The image proxy did not hand over a finished-puzzle photo it should have (FinishedPhotoRendition) - answered `503`
 * with `Retry-After`, so the image pipeline tries that photo again later; nothing is wrong with the request.
 */
final class FinishedPhotoUnavailable extends ServiceUnavailableHttpException
{
    public function __construct(string $message, null|Throwable $previous = null)
    {
        parent::__construct(60, $message, $previous);
    }
}
