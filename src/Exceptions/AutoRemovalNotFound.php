<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * No such automatic removal - or it is not the asking player's to undo (they must not learn it exists).
 */
final class AutoRemovalNotFound extends NotFoundHttpException
{
}
