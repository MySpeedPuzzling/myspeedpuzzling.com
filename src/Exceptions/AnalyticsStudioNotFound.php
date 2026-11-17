<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * An analytics studio that is not there (docs/features/competitions-management/analytics-studio.md §1): the round does
 * not exist or has no slug, its event is not publicly visible (a draft, unapproved, an edition of a draft series - for
 * its team too), or the entry asked for is not one of the round's.
 */
final class AnalyticsStudioNotFound extends NotFoundHttpException
{
}
