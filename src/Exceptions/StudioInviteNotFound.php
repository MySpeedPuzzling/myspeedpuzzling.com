<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * No such invitation to the studio - an unknown id, or one of another participant or another round than the request
 * is about (the endpoints answer `invite_not_found`). An HTTP exception, so UnwrapHttpExceptionMiddleware hands it to
 * the controller as itself.
 */
final class StudioInviteNotFound extends NotFoundHttpException
{
}
