<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * A venue section for an online event or series. The page editor does not offer it - only a hand-made request gets here.
 */
final class PageSectionTypeNotAvailable extends UnprocessableEntityHttpException
{
}
