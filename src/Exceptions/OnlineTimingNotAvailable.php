<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use SpeedPuzzling\Web\Value\OnlineTimingAvailability;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Online timing refused for a round that cannot use it (OnlineTimingAvailability: an in-person event, a pair/team
 * round). Nothing was changed.
 */
final class OnlineTimingNotAvailable extends ConflictHttpException
{
    public function __construct(
        readonly public OnlineTimingAvailability $availability,
    ) {
        parent::__construct(sprintf('Online timing is not available for this round (%s).', $availability->value));
    }
}
