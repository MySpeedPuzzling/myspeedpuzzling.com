<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The event is not one the marketplace works with: online, not publicly visible, undated or already over
 * (GetMarketplaceEvents::SQL_QUALIFIES) - or it does not exist at all.
 */
final class CompetitionNotEligibleForMarketplace extends NotFoundHttpException
{
}
