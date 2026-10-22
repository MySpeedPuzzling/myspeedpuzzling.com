<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The event has no partner finder (docs/features/partner-finder.md D1): not in person, not publicly visible, undated,
 * over, or every round solo - PartnerFinderEligibility.
 */
final class PartnerFinderNotAvailable extends NotFoundHttpException
{
}
