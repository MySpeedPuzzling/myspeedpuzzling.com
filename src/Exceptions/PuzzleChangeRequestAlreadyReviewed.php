<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Only a pending change request can be approved - approving it again would apply its
 * changes, log the decision and notify the player a second time.
 */
final class PuzzleChangeRequestAlreadyReviewed extends ConflictHttpException
{
}
