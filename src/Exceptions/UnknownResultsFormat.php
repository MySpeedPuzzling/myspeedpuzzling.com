<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * A results source names a format no code knows (event_results_sheet.format).
 */
final class UnknownResultsFormat extends UnprocessableEntityHttpException
{
}
