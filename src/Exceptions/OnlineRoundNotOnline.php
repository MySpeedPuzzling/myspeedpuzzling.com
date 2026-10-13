<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * A participant's phone named a round that does not use online timing, or one of another competition
 * (OnlineEntryResolver) - the participant API answers `round_not_online` (events: `wrong_round`). An HTTP exception,
 * so UnwrapHttpExceptionMiddleware hands it to the controller as itself.
 */
final class OnlineRoundNotOnline extends ConflictHttpException
{
}
