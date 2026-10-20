<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * No such invitation - or, for the player answering, no open one any more (answered, or withdrawn by an admin)
 */
final class ModeratorInvitationNotFound extends NotFoundHttpException
{
}
