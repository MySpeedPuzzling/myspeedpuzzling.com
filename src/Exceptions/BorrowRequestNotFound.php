<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Also what anybody but the request's asker or owner gets - a request is nobody else's business.
 */
final class BorrowRequestNotFound extends NotFoundHttpException
{
}
