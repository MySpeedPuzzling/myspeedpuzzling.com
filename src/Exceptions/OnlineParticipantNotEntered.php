<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * The participant of a personal link has no entry in the round their phone named (OnlineEntryResolver) - nothing of
 * theirs is stored for it: the participant API answers `not_entered`. An HTTP exception, so
 * UnwrapHttpExceptionMiddleware hands it to the controller as itself.
 */
final class OnlineParticipantNotEntered extends ConflictHttpException
{
}
