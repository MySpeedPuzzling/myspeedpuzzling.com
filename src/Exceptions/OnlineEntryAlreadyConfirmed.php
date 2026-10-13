<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * The review confirmed the online entry's result - its evidence is closed, no new upload is taken
 * (`entry_confirmed`). An HTTP exception, so UnwrapHttpExceptionMiddleware hands it to the controller as itself.
 */
final class OnlineEntryAlreadyConfirmed extends ConflictHttpException
{
}
