<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A personal link that opens nothing: an unknown or malformed token, a link replaced by a new one, or a participant
 * removed from the competition (docs/features/online-competitions/README.md §5.4).
 */
final class OnlineLinkNotFound extends NotFoundHttpException
{
}
